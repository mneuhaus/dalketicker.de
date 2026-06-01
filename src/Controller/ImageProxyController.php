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
        if ($url === null || $url === '' || !$this->isSafeUrl($url)) {
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
        @file_put_contents($bin, $data);
        @file_put_contents($ctFile, $type);

        return $this->serve($data, $type);
    }

    /** @return array{0: ?string, 1: string} */
    private function fetch(string $url): array
    {
        try {
            $resp = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => 'Dalketicker/1.0 (+https://dalketicker.neuhaus.nrw)'],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);
            if ($resp->getStatusCode() !== 200) {
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
        } catch (\Throwable) {
            return [null, ''];
        }
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

    /** Allow only http(s) to non-private hosts (defence in depth). */
    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        $host = $parts['host'];
        $ips = @gethostbynamel($host) ?: [];
        if ($ips === [] && filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false; // private / reserved → reject
            }
        }

        return true;
    }
}
