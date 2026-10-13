<?php

namespace App\Support;

use GdImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads stored images (a public-disk path or an http(s) URL) and turns GD
 * canvases back into bytes. GD has no SVG or ICO decoder, so those come back
 * as null from decode().
 */
final class ImageBytes
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_PIXELS = 40_000_000;

    public static function load(?string $stored, int $maxBytes = self::MAX_BYTES): ?string
    {
        $stored = MediaUrl::store($stored);

        if ($stored === null) {
            return null;
        }

        try {
            if (preg_match('#^https?://#i', $stored) === 1) {
                return self::download($stored, $maxBytes);
            }

            $disk = Storage::disk('public');

            if (str_contains($stored, '..') || ! $disk->exists($stored) || $disk->size($stored) > $maxBytes) {
                return null;
            }

            $bytes = $disk->get($stored);

            return is_string($bytes) && $bytes !== '' ? $bytes : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Streams a remote image and stops reading once it passes the cap, so an
     * oversized or endless response is never buffered whole.
     */
    private static function download(string $url, int $maxBytes): ?string
    {
        $response = Http::timeout(8)->connectTimeout(4)->withOptions(['stream' => true])->get($url);

        if (! $response->successful() || (int) $response->header('Content-Length') > $maxBytes) {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(65536);

            if (strlen($body) > $maxBytes) {
                $stream->close();

                return null;
            }
        }

        return $body !== '' ? $body : null;
    }

    public static function decode(string $bytes): ?GdImage
    {
        if ($bytes === '' || self::isSvg($bytes)) {
            return null;
        }

        // A small file can declare a huge canvas; GD would allocate width x height x 4 bytes.
        $size = @getimagesizefromstring($bytes);

        if (is_array($size) && $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return null;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    public static function isIco(string $bytes): bool
    {
        return str_starts_with($bytes, "\x00\x00\x01\x00");
    }

    public static function isSvg(string $bytes): bool
    {
        return preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE svg[^>]*>\s*)?<svg[\s>]/is', substr($bytes, 0, 4096)) === 1;
    }

    /** Transparent square canvas with the source scaled to fit inside it. */
    public static function contain(GdImage $source, int $size): GdImage
    {
        $canvas = self::transparent($size, $size);
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min($size / $width, $size / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        imagecopyresampled(
            $canvas, $source,
            intdiv($size - $targetWidth, 2), intdiv($size - $targetHeight, 2), 0, 0,
            $targetWidth, $targetHeight, $width, $height,
        );

        return $canvas;
    }

    /** Fills the whole box, cropping the overflow from the centre. */
    public static function cover(GdImage $source, int $width, int $height, array $background = [255, 255, 255]): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, ...$background));
        imagealphablending($canvas, true);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = max($width / $sourceWidth, $height / $sourceHeight);
        $cropWidth = min($sourceWidth, (int) round($width / $scale));
        $cropHeight = min($sourceHeight, (int) round($height / $scale));

        imagecopyresampled(
            $canvas, $source,
            0, 0, intdiv($sourceWidth - $cropWidth, 2), intdiv($sourceHeight - $cropHeight, 2),
            $width, $height, $cropWidth, $cropHeight,
        );

        return $canvas;
    }

    public static function png(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    public static function jpeg(GdImage $image, int $quality = 85): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    private static function transparent(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }
}
