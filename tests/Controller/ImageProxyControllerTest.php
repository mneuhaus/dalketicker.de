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
