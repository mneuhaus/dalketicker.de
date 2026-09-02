<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ImageProxyController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Guards of the private fetch(): the upstream is attacker-controlled per threat
 * model, so oversized bodies, lying Content-Length headers and decompression
 * bombs must all be rejected before they reach memory limits or GD.
 */
final class ImageProxyControllerTest extends TestCase
{
    private const PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testAcceptsASmallValidImage(): void
    {
        $body = (string) base64_decode(self::PIXEL_PNG, true);
        $client = new MockHttpClient(new MockResponse($body, ['response_headers' => ['content-type' => 'image/png']]));

        self::assertSame([$body, 'image/png'], $this->fetch($client));
    }

    public function testRejectsOversizedContentLengthBeforeDownloading(): void
    {
        $client = new MockHttpClient(new MockResponse('x', ['response_headers' => [
            'content-type' => 'image/png',
            'content-length' => (string) (100 * 1024 * 1024),
        ]]));

        self::assertSame([null, ''], $this->fetch($client));
    }

    public function testAbortsWhenTheStreamedBodyExceedsTheCap(): void
    {
        // 10 MB in 1-MB chunks, no Content-Length — only streaming catches it.
        $chunks = array_fill(0, 10, str_repeat('a', 1024 * 1024));
        $client = new MockHttpClient(new MockResponse($chunks, ['response_headers' => ['content-type' => 'image/png']]));

        self::assertSame([null, ''], $this->fetch($client));
    }

    public function testRejectsDecompressionBombDimensions(): void
    {
        $client = new MockHttpClient(new MockResponse(
            $this->pngHeader(25000, 25000),
            ['response_headers' => ['content-type' => 'image/png']],
        ));

        self::assertSame([null, ''], $this->fetch($client));
    }

    public function testRejectsBytesThatAreNoImage(): void
    {
        $client = new MockHttpClient(new MockResponse('definitiv kein bild', ['response_headers' => ['content-type' => 'image/png']]));

        self::assertSame([null, ''], $this->fetch($client));
    }

    public function testRejectsDisallowedContentType(): void
    {
        $client = new MockHttpClient(new MockResponse('<svg/>', ['response_headers' => ['content-type' => 'image/svg+xml']]));

        self::assertSame([null, ''], $this->fetch($client));
    }

    public function testSweepEvictsOnlyLongUnusedEntries(): void
    {
        $dir = $this->cacheDir();
        $fresh = $this->cacheFile($dir, 'fresh', time() - 86400);
        $old = $this->cacheFile($dir, 'old', time() - 61 * 86400);
        $edge = $this->cacheFile($dir, 'edge', time() - 59 * 86400);

        $this->sweep($dir);

        self::assertFileExists($fresh);
        self::assertFileExists($edge, 'entries inside the TTL must survive');
        self::assertFileDoesNotExist($old);
    }

    public function testSweepRemovesAbandonedTempFilesQuickly(): void
    {
        $dir = $this->cacheDir();
        // A .tmp is orphaned the moment its request died — no reason to keep it
        // for the full TTL, but an in-flight write must not be pulled away.
        $abandoned = $this->cacheFile($dir, 'abc.1234.tmp', time() - 7200);
        $inFlight = $this->cacheFile($dir, 'def.5678.tmp', time() - 60);

        $this->sweep($dir);

        self::assertFileDoesNotExist($abandoned);
        self::assertFileExists($inFlight);
    }

    public function testKeepAliveRefreshesStaleEntriesOnly(): void
    {
        $dir = $this->cacheDir();
        $staleMtime = time() - 30 * 86400;
        $stale = $this->cacheFile($dir, 'stale', $staleMtime);
        $recentMtime = time() - 600;
        $recent = $this->cacheFile($dir, 'recent', $recentMtime);

        $controller = $this->controller($dir);
        \Closure::bind(fn (string ...$p) => $this->keepAlive(...$p), $controller, ImageProxyController::class)($stale, $recent);

        clearstatcache();
        // A viewed entry must not age out of the cache while it is still in use.
        self::assertGreaterThan($staleMtime, filemtime($stale));
        self::assertSame($recentMtime, filemtime($recent), 'no write when the entry was touched today');
    }

    public function testEmptyOrHalfMissingCacheEntriesAreNotServed(): void
    {
        $dir = $this->cacheDir();
        $controller = $this->controller($dir);
        $read = \Closure::bind(fn (string $bin, string $ct): ?array => $this->readCached($bin, $ct), $controller, ImageProxyController::class);

        // A sweep on another replica between "exists" and "read" leaves an
        // empty read — that must fall through to a fetch, never be served for 30 days.
        $this->cacheFile($dir, 'empty', time());
        file_put_contents($dir.'/empty', '');
        $this->cacheFile($dir, 'empty.ct', time());
        self::assertNull($read($dir.'/empty', $dir.'/empty.ct'));

        $this->cacheFile($dir, 'orphan', time());
        self::assertNull($read($dir.'/orphan', $dir.'/orphan.ct'), 'body without content type');

        $this->cacheFile($dir, 'ok', time());
        $this->cacheFile($dir, 'ok.ct', time());
        self::assertSame(['x', 'x'], $read($dir.'/ok', $dir.'/ok.ct'));
    }

    public function testAFailedFetchIsRememberedForAnHour(): void
    {
        $dir = $this->cacheDir();
        $controller = $this->controller($dir);
        $missed = \Closure::bind(fn (string $f): bool => $this->recentlyMissed($f), $controller, ImageProxyController::class);

        self::assertFalse($missed($dir.'/none.miss'));
        $fresh = $this->cacheFile($dir, 'fresh.miss', time() - 600);
        self::assertTrue($missed($fresh), 'no upstream retry within the hour');
        $old = $this->cacheFile($dir, 'old.miss', time() - 2 * 3600);
        self::assertFalse($missed($old), 'after the hour the image is tried again');
    }

    private function cacheDir(): string
    {
        $dir = sys_get_temp_dir().'/dalke-imgcache-'.bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        register_shutdown_function(static function () use ($dir): void {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        });

        return $dir;
    }

    private function cacheFile(string $dir, string $name, int $mtime): string
    {
        $path = $dir.'/'.$name;
        file_put_contents($path, 'x');
        touch($path, $mtime);

        return $path;
    }

    private function sweep(string $dir): void
    {
        $controller = $this->controller($dir);
        \Closure::bind(fn () => $this->sweepCache(), $controller, ImageProxyController::class)();
    }

    private function controller(string $cacheDir): ImageProxyController
    {
        $controller = (new \ReflectionClass(ImageProxyController::class))->newInstanceWithoutConstructor();
        \Closure::bind(function (string $dir): void {
            $this->imageCacheDir = $dir;
        }, $controller, ImageProxyController::class)($cacheDir);

        return $controller;
    }

    /**
     * Runs the private fetch() against a mocked upstream. The controller is
     * built without its constructor (only the http client matters here); the
     * URL uses a public IP literal so no DNS lookup happens.
     *
     * @return array{0: ?string, 1: string}
     */
    private function fetch(HttpClientInterface $client, string $url = 'http://93.184.216.34/bild.png'): array
    {
        $controller = (new \ReflectionClass(ImageProxyController::class))->newInstanceWithoutConstructor();
        \Closure::bind(function (HttpClientInterface $http): void {
            $this->http = $http;
        }, $controller, ImageProxyController::class)($client);

        return \Closure::bind(fn (string $u): array => $this->fetch($u), $controller, ImageProxyController::class)($url);
    }

    /** Minimal PNG (signature + IHDR) declaring arbitrary dimensions — enough for getimagesizefromstring(). */
    private function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('N', 13).'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00".pack('N', 0);

        return "\x89PNG\r\n\x1a\n".$ihdr;
    }
}
