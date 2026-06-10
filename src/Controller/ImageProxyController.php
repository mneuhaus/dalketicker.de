<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Proxies & caches event images through our own server so the visitor's browser
 * never talks to the source servers (no IP leak — DSGVO-friendly).
 *
 * Security: it only ever fetches the image URL stored for a known event (no
 * arbitrary ?url= parameter → no open proxy / SSRF surface), plus scheme,
 * private-IP, size and content-type guards as defence in depth.
 */
final class ImageProxyController extends AbstractController
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_WIDTH = 1000;
    private const MAX_REDIRECTS = 3;
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    public function __construct(
        private readonly EventRepository $events,
        private readonly HttpClientInterface $http,
        private readonly string $imageCacheDir,
    ) {
    }

    #[Route('/img/{id}', name: 'image_proxy', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Cache(public: true, maxage: 2592000)]
    public function image(int $id): Response
    {
        $event = $this->events->find($id);
        // Facts-only (aggregator) sources: never serve their images, even if a
        // URL is guessed directly — the legal safeguard holds at every layer.
        $url = ($event !== null && !$event->isFactsOnly()) ? $event->getImageUrl() : null;
        if ($url === null || $url === '') {
            return $this->transparentPixel();
        }

        $key = sha1($url);
        $bin = $this->imageCacheDir.'/'.$key;
        $ctFile = $bin.'.ct';

        if (is_file($bin) && is_file($ctFile)) {
            return $this->serve((string) file_get_contents($bin), (string) file_get_contents($ctFile));
        }

        [$data, $type] = $this->fetch($url);
        if ($data === null) {
            return $this->transparentPixel();
        }

        // Downscale oversized images (event flyers are often 1–2 MB) so cards
        // stay fast. We only ever display them a few hundred px wide.
        $shrunk = $this->downscale($data);
        if ($shrunk !== null) {
            [$data, $type] = $shrunk;
        }

        if (!is_dir($this->imageCacheDir)) {
            @mkdir($this->imageCacheDir, 0775, true);
        }
        $this->writeAtomic($ctFile, $type);
        $this->writeAtomic($bin, $data);

        return $this->serve($data, $type);
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
                    'headers' => ['User-Agent' => 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)'],
                    'timeout' => 15,
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
                $data = $resp->getContent(false);
                if (strlen($data) > self::MAX_BYTES) {
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
