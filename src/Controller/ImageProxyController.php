<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventRepository;
use App\Service\RegionContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Proxies & caches event images through our own server so the visitor's browser
 * never talks to the source servers (no IP leak — DSGVO-friendly).
 *
 * Security: it only ever fetches the image URL stored for a known event (no
 * arbitrary ?url= parameter → no open proxy / SSRF surface), plus scheme,
 * private-IP, size, pixel-dimension and content-type guards as defence in depth.
 */
final class ImageProxyController extends AbstractController
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;
    private const MAX_WIDTH = 1000;
    private const MAX_REDIRECTS = 3;
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    /** Cache entries nobody requested for this long are evicted ({@see sweepCache}). */
    private const CACHE_TTL_DAYS = 60;
    /** A failed fetch is not retried upstream for this long ({@see recentlyMissed}). */
    private const MISS_TTL = 3600;
    /** Roughly one in N cache misses runs a sweep. */
    private const SWEEP_EVERY = 100;
    /** Upper bound on files inspected per sweep, so a single request can't stall on it. */
    private const SWEEP_MAX_FILES = 50000;

    public function __construct(
        private readonly EventRepository $events,
        private readonly HttpClientInterface $http,
        private readonly string $imageCacheDir,
        private readonly RegionContext $regions,
    ) {
    }

    #[Route('/img/{id}', name: 'image_proxy', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function image(int $id): Response
    {
        $event = $this->events->findVisible($id, $this->regions->current());
        // We only serve an image where we may actually show it: a real URL, a
        // non-facts-only source, and a recorded publishing permission
        // ({@see Event::canShowImage()}). The legal safeguard holds at every
        // layer — even a directly guessed URL yields nothing for an aggregator
        // or not-yet-cleared source. Admins preview an unapproved image via the
        // raw source URL in the backend, not through this public proxy.
        $url = ($event !== null && $event->canShowImage()) ? $event->getImageUrl() : null;
        if ($url === null || $url === '') {
            return $this->transparentPixel();
        }

        $key = sha1($url);
        $bin = $this->imageCacheDir.'/'.$key;
        $ctFile = $bin.'.ct';
        $missFile = $bin.'.miss';

        if (($cached = $this->readCached($bin, $ctFile)) !== null) {
            return $this->serve(...$cached);
        }
        if ($this->recentlyMissed($missFile)) {
            return $this->transparentPixel();
        }

        if (!is_dir($this->imageCacheDir)) {
            @mkdir($this->imageCacheDir, 0775, true);
        }

        // Collapse concurrent first views of one image into a single upstream
        // fetch: everyone else waits on the lock, then finds the cache filled.
        $lock = @fopen($bin.'.lock', 'c');
        if ($lock !== false && !@flock($lock, \LOCK_EX)) {
            fclose($lock);
            $lock = false;
        }
        try {
            if ($lock !== false) {
                if (($cached = $this->readCached($bin, $ctFile)) !== null) {
                    return $this->serve(...$cached);
                }
                if ($this->recentlyMissed($missFile)) {
                    return $this->transparentPixel();
                }
            }

            [$data, $type] = $this->fetch($url);
            if ($data === null) {
                // Negative cache. Without it a source serving SVGs, a dead
                // link or a slow host costs an upstream round-trip (up to
                // 20 s of worker time) on every single page view — and hands
                // anyone controlling an image URL a cheap way to pin workers.
                $this->writeAtomic($missFile, (string) time());

                return $this->transparentPixel();
            }

            // Downscale oversized images (event flyers are often 1–2 MB) so
            // cards stay fast. We only ever display them a few hundred px wide.
            $shrunk = $this->downscale($data);
            if ($shrunk !== null) {
                [$data, $type] = $shrunk;
            }

            $this->writeAtomic($ctFile, $type);
            $this->writeAtomic($bin, $data);
            @unlink($missFile);
        } finally {
            if ($lock !== false) {
                flock($lock, \LOCK_UN);
                fclose($lock);
            }
        }
        $this->sweepOccasionally();

        return $this->serve($data, $type);
    }

    /**
     * The cached entry, or null when there is none. Reads first and checks
     * after: between an is_file() and the read another replica's sweep may
     * have unlinked the entry, and an empty body must fall through to a fetch
     * instead of being served with a 30-day max-age.
     *
     * @return array{0: string, 1: string}|null
     *
     * @phpstan-impure
     */
    private function readCached(string $bin, string $ctFile): ?array
    {
        $data = @file_get_contents($bin);
        $type = @file_get_contents($ctFile);
        if ($data === false || $data === '' || $type === false || $type === '') {
            return null;
        }
        $this->keepAlive($bin, $ctFile);

        return [$data, $type];
    }

    /**
     * Whether a fetch of this image failed within the last {@see MISS_TTL} seconds.
     *
     * @phpstan-impure
     */
    private function recentlyMissed(string $missFile): bool
    {
        $mtime = @filemtime($missFile);

        return $mtime !== false && $mtime > time() - self::MISS_TTL;
    }

    /**
     * Mark a cache entry as recently used. {@see sweepCache} evicts by
     * modification time, so without this a popular image would be dropped
     * {@see CACHE_TTL_DAYS} days after it was first fetched, no matter how
     * often it is viewed. Refreshed at most once a day — touching on every
     * hit would mean a write per card image per page view.
     */
    private function keepAlive(string ...$paths): void
    {
        $cutoff = time() - 86400;
        foreach ($paths as $path) {
            if ((int) @filemtime($path) < $cutoff) {
                @touch($path);
            }
        }
    }

    /**
     * Run {@see sweepCache} on roughly one in {@see SWEEP_EVERY} cache misses.
     * A miss already pays for a network fetch, so a directory scan next to it
     * doesn't show — and no cron job is needed, which matters here: the
     * scheduler container does not mount the uploads volume this cache lives
     * on (docker-compose.prod.yml) and could not see these files at all.
     */
    private function sweepOccasionally(): void
    {
        if (random_int(1, self::SWEEP_EVERY) === 1) {
            $this->sweepCache();
        }
    }

    /**
     * Evict cache entries nobody has requested in {@see CACHE_TTL_DAYS} days,
     * plus any ".tmp" left behind by an interrupted write. The cache is pure
     * derived data — an evicted image is simply re-fetched on its next view.
     */
    private function sweepCache(): void
    {
        $now = time();
        $cutoff = $now - self::CACHE_TTL_DAYS * 86400;
        $seen = 0;
        try {
            $entries = new \FilesystemIterator($this->imageCacheDir, \FilesystemIterator::SKIP_DOTS);
        } catch (\Throwable) {
            return;
        }
        foreach ($entries as $entry) {
            if (++$seen > self::SWEEP_MAX_FILES) {
                return;
            }
            $path = $entry->getPathname();
            // filemtime() (not the iterator's getMTime()) so a file another
            // replica just swept yields false instead of throwing mid-scan.
            $mtime = @filemtime($path);
            if ($mtime === false) {
                continue;
            }
            $stale = str_ends_with($path, '.tmp') ? $mtime < $now - 3600 : $mtime < $cutoff;
            if ($stale) {
                @unlink($path);
            }
        }
    }

    /**
     * Write via temp file + rename: the cache dir lives on a volume shared by
     * multiple replicas, and a plain file_put_contents could let a concurrent
     * reader see (and serve) a half-written file.
     */
    private function writeAtomic(string $path, string $data): void
    {
        $tmp = $path.'.'.uniqid('', true).'.tmp';
        if (@file_put_contents($tmp, $data) === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * Fetch the image, following redirects manually: every hop is validated and
     * the connection is pinned to the validated IP via the "resolve" option, so
     * a DNS answer can't change between check and request (no rebinding TOCTOU).
     * TLS certificates are still verified against the hostname.
     *
     * @return array{0: ?string, 1: string}
     */
    private function fetch(string $url): array
    {
        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $host = (string) parse_url($url, \PHP_URL_HOST);
                $ip = $this->validatedPublicIp($url);
                if ($ip === null) {
                    return [null, ''];
                }
                $resp = $this->http->request('GET', $url, [
                    'headers' => ['User-Agent' => 'Dalketicker/1.0 (+https://dalketicker.de)'],
                    'timeout' => 15, // idle timeout
                    'max_duration' => 20, // hard cap — a slow-drip upstream can't pin the worker
                    'max_redirects' => 0,
                    'resolve' => [$host => $ip],
                ]);
                $status = $resp->getStatusCode();
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $next = $this->redirectTarget($url, $resp->getHeaders(false)['location'][0] ?? '');
                    $resp->cancel();
                    if ($next === null) {
                        return [null, ''];
                    }
                    $url = $next;
                    continue;
                }
                if ($status !== 200) {
                    return [null, ''];
                }
                $type = strtolower(explode(';', $resp->getHeaders(false)['content-type'][0] ?? '')[0]);
                if (!in_array($type, self::ALLOWED_TYPES, true)) {
                    return [null, ''];
                }
                if ((int) ($resp->getHeaders(false)['content-length'][0] ?? 0) > self::MAX_BYTES) {
                    return [null, ''];
                }
                // Stream the body so an upstream that lies about (or omits) its
                // Content-Length can never materialize hundreds of MB in memory
                // — abort as soon as the cap is crossed.
                $data = '';
                foreach ($this->http->stream($resp) as $chunk) {
                    if ($chunk->isTimeout()) {
                        $resp->cancel();

                        return [null, ''];
                    }
                    $data .= $chunk->getContent();
                    if (strlen($data) > self::MAX_BYTES) {
                        $resp->cancel();

                        return [null, ''];
                    }
                }
                // Cheap header sniff before anything GD-decodes the bytes: a
                // well-compressed bomb far below MAX_BYTES expands to gigabytes
                // of pixels (uncatchable OOM). Reject it entirely — such a file
                // is never cached or served.
                $info = @getimagesizefromstring($data);
                if ($info === false || $info[0] * $info[1] > self::MAX_PIXELS) {
                    return [null, ''];
                }

                return [$data, $type];
            }

            return [null, '']; // too many redirects
        } catch (\Throwable) {
            return [null, ''];
        }
    }

    /** Absolute redirect target, or null for missing/exotic (path-relative) Locations. */
    private function redirectTarget(string $base, string $location): ?string
    {
        if ($location === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        if (str_starts_with($location, '/') && !empty($parts['host'])) {
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';

            return $scheme.'://'.$parts['host'].$port.$location;
        }

        return null;
    }

    /**
     * Shrink images wider than {@see MAX_WIDTH} and re-encode as JPEG, so a
     * card image is a few dozen KB instead of one or two MB. Returns null when
     * GD is unavailable, the image can't be decoded, or it's already small.
     *
     * @return array{0: string, 1: string}|null
     */
    private function downscale(string $data): ?array
    {
        if (!\function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= self::MAX_WIDTH) {
            imagedestroy($img);

            return null; // already small enough — keep the original
        }

        $nh = max(1, (int) round($h * self::MAX_WIDTH / $w));
        $scaled = imagescale($img, self::MAX_WIDTH, $nh);
        imagedestroy($img);
        if ($scaled === false) {
            return null;
        }

        // Flatten onto white so transparent PNGs don't turn black as JPEG.
        $canvas = imagecreatetruecolor(self::MAX_WIDTH, $nh);
        if ($canvas !== false) {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $scaled, 0, 0, 0, 0, self::MAX_WIDTH, $nh);
            imagedestroy($scaled);
            $scaled = $canvas;
        }

        ob_start();
        imageinterlace($scaled, true);
        $ok = imagejpeg($scaled, null, 82);
        $out = ob_get_clean();
        imagedestroy($scaled);

        return ($ok && is_string($out) && $out !== '') ? [$out, 'image/jpeg'] : null;
    }

    private function serve(string $data, string $type): Response
    {
        $resp = new Response($data, 200, ['Content-Type' => $type ?: 'image/jpeg']);
        $resp->setPublic();
        $resp->setMaxAge(2592000);
        $resp->headers->set('X-Content-Type-Options', 'nosniff');

        return $resp;
    }

    private function transparentPixel(): Response
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $resp = new Response($png, 200, ['Content-Type' => 'image/png']);
        $resp->setPublic();
        $resp->setMaxAge(3600); // retry sooner on misses
        $resp->headers->set('Cache-Control', $resp->headers->get('Cache-Control', '').', stale-while-revalidate=86400');

        return $resp;
    }

    /**
     * Allow only http(s) to public hosts (defence in depth). Returns the first
     * validated IP so the caller can pin the connection to it, or null when the
     * URL is unsafe or doesn't resolve to a usable address.
     */
    private function validatedPublicIp(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }
        // IPv6 URL literals come bracketed from parse_url ("[2001:db8::1]").
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveIps($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            // FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE also covers IPv6 (::1,
            // fc00::/7, fe80::/10, …) — verified.
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null; // private / reserved → reject
            }
        }

        return $ips[0];
    }

    /**
     * All A and AAAA records for the host (v4 first), so IPv6-only image hosts
     * resolve too. Both HttpClient backends accept a bare IPv6 address in the
     * "resolve" option (curl: CURLOPT_RESOLVE "host:port:ip", native: brackets
     * the IP itself).
     *
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $v4 = $v6 = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $v4[] = (string) $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $v6[] = (string) $record['ipv6'];
            }
        }

        return [...$v4, ...$v6];
    }
}
