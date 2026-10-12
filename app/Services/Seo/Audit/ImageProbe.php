<?php

namespace App\Services\Seo\Audit;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads an image's real format and pixel size from its first bytes, the way
 * social networks and favicon checkers do, without trusting headers or names.
 */
final class ImageProbe
{
    private const PROBE_BYTES = 65535;

    /**
     * @return array{ok: bool, status: ?int, content_type: string, bytes: ?int, redirected: bool, format: ?string, width: ?int, height: ?int, sizes: list<array{0: int, 1: int}>, error: ?string}
     */
    public function fetch(string $url, bool $followRedirects = true): array
    {
        try {
            $response = Http::timeout((int) config('seo.audit.link_timeout', 5) * 2)
                ->withUserAgent((string) config('seo.audit.user_agent'))
                ->withHeaders(['Range' => 'bytes=0-'.self::PROBE_BYTES, 'Accept' => 'image/*,*/*;q=0.8'])
                ->withOptions(['allow_redirects' => $followRedirects ? ['max' => 5, 'track_redirects' => true] : false])
                ->get($url);
        } catch (Throwable $exception) {
            return $this->empty(null, 'The image did not answer: '.mb_substr($exception->getMessage(), 0, 150));
        }

        $status = $response->status();
        $redirected = $response->header('X-Guzzle-Redirect-History') !== '';

        if (! in_array($status, [200, 206], true)) {
            return [...$this->empty($status, "HTTP {$status}"), 'redirected' => $redirected || ($status >= 300 && $status < 400)];
        }

        $info = self::inspect($response->body());

        return [
            'ok' => $info['format'] !== null,
            'status' => $status,
            'content_type' => strtolower(trim(explode(';', $response->header('Content-Type'))[0])),
            'bytes' => $this->totalBytes($response->header('Content-Range'), $response->header('Content-Length'), strlen($response->body()), $status),
            'redirected' => $redirected,
            ...$info,
            'error' => $info['format'] === null ? 'Not a recognised image format.' : null,
        ];
    }

    /**
     * @return array{format: ?string, width: ?int, height: ?int, sizes: list<array{0: int, 1: int}>}
     */
    public static function inspect(string $bytes): array
    {
        $none = ['format' => null, 'width' => null, 'height' => null, 'sizes' => []];

        if (strlen($bytes) < 12) {
            return $none;
        }

        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && strlen($bytes) >= 24) {
            $size = unpack('Nwidth/Nheight', substr($bytes, 16, 8));

            return self::sized('png', (int) $size['width'], (int) $size['height']);
        }

        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            $size = unpack('vwidth/vheight', substr($bytes, 6, 4));

            return self::sized('gif', (int) $size['width'], (int) $size['height']);
        }

        if (str_starts_with($bytes, "\xFF\xD8")) {
            return self::jpeg($bytes) ?? ['format' => 'jpeg', 'width' => null, 'height' => null, 'sizes' => []];
        }

        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return self::webp($bytes) ?? ['format' => 'webp', 'width' => null, 'height' => null, 'sizes' => []];
        }

        if (substr($bytes, 0, 4) === "\x00\x00\x01\x00") {
            return self::ico($bytes);
        }

        if (preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg\b/is', substr($bytes, 0, 2048)) === 1) {
            return ['format' => 'svg', 'width' => null, 'height' => null, 'sizes' => []];
        }

        return $none;
    }

    /**
     * @return array{format: string, width: int, height: int, sizes: list<array{0: int, 1: int}>}
     */
    private static function sized(string $format, int $width, int $height): array
    {
        return ['format' => $format, 'width' => $width, 'height' => $height, 'sizes' => [[$width, $height]]];
    }

    private static function jpeg(string $bytes): ?array
    {
        $offset = 2;
        $length = strlen($bytes);

        while ($offset + 9 < $length) {
            if ($bytes[$offset] !== "\xFF") {
                $offset++;

                continue;
            }

            $marker = ord($bytes[$offset + 1]);

            if ($marker === 0xFF) {
                $offset++;

                continue;
            }

            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $offset += 2;

                continue;
            }

            $segment = unpack('n', substr($bytes, $offset + 2, 2))[1];

            // SOF0-SOF15 carry the frame size; C4 (DHT), C8 (JPG) and CC (DAC) do not.
            if ($marker >= 0xC0 && $marker <= 0xCF && ! in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                $size = unpack('nheight/nwidth', substr($bytes, $offset + 5, 4));

                return self::sized('jpeg', (int) $size['width'], (int) $size['height']);
            }

            $offset += 2 + $segment;
        }

        return null;
    }

    private static function webp(string $bytes): ?array
    {
        $chunk = substr($bytes, 12, 4);

        if ($chunk === 'VP8X' && strlen($bytes) >= 30) {
            $width = 1 + (ord($bytes[24]) | ord($bytes[25]) << 8 | ord($bytes[26]) << 16);
            $height = 1 + (ord($bytes[27]) | ord($bytes[28]) << 8 | ord($bytes[29]) << 16);

            return self::sized('webp', $width, $height);
        }

        if ($chunk === 'VP8 ' && strlen($bytes) >= 30) {
            $size = unpack('vwidth/vheight', substr($bytes, 26, 4));

            return self::sized('webp', (int) $size['width'] & 0x3FFF, (int) $size['height'] & 0x3FFF);
        }

        if ($chunk === 'VP8L' && strlen($bytes) >= 25) {
            $bits = unpack('V', substr($bytes, 21, 4))[1];

            return self::sized('webp', ($bits & 0x3FFF) + 1, (($bits >> 14) & 0x3FFF) + 1);
        }

        return null;
    }

    /**
     * @return array{format: string, width: ?int, height: ?int, sizes: list<array{0: int, 1: int}>}
     */
    private static function ico(string $bytes): array
    {
        $count = unpack('v', substr($bytes, 4, 2))[1];
        $sizes = [];

        for ($i = 0; $i < $count && 6 + ($i + 1) * 16 <= strlen($bytes); $i++) {
            $entry = substr($bytes, 6 + $i * 16, 2);
            $sizes[] = [ord($entry[0]) ?: 256, ord($entry[1]) ?: 256];
        }

        usort($sizes, static fn (array $a, array $b) => $b[0] <=> $a[0]);

        return ['format' => 'ico', 'width' => $sizes[0][0] ?? null, 'height' => $sizes[0][1] ?? null, 'sizes' => $sizes];
    }

    private function totalBytes(string $contentRange, string $contentLength, int $received, int $status): ?int
    {
        if ($status === 206 && preg_match('#/(\d+)$#', $contentRange, $match) === 1) {
            return (int) $match[1];
        }

        return is_numeric($contentLength) && $status === 200 ? max((int) $contentLength, $received) : ($status === 200 ? $received : null);
    }

    /**
     * @return array{ok: bool, status: ?int, content_type: string, bytes: ?int, redirected: bool, format: ?string, width: ?int, height: ?int, sizes: list<array{0: int, 1: int}>, error: ?string}
     */
    private function empty(?int $status, string $error): array
    {
        return [
            'ok' => false, 'status' => $status, 'content_type' => '', 'bytes' => null, 'redirected' => false,
            'format' => null, 'width' => null, 'height' => null, 'sizes' => [], 'error' => $error,
        ];
    }
}
