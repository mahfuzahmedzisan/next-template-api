<?php

namespace App\Services\Seo;

use App\Support\ImageBytes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * A 1200x628 (1.91:1) cover-cropped JPEG of an OG or Twitter image, so
 * share cards never get letterboxed or rejected for their ratio. Variants
 * are built once per source value and kept on the public disk.
 */
final class SocialImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 628;

    public const DIRECTORY = 'seo/og';

    private const FAILED_TTL = 86400;

    /**
     * Null when the source is missing, an SVG, or not readable by GD.
     *
     * @return array{path: string, width: int, height: int, type: string}|null
     */
    public function variant(?string $stored): ?array
    {
        $stored = trim((string) $stored);

        if ($stored === '' || self::isSvgPath($stored)) {
            return null;
        }

        $hash = sha1($stored);
        $path = self::DIRECTORY.'/'.$hash.'.jpg';
        $disk = Storage::disk('public');
        $result = ['path' => $path, 'width' => self::WIDTH, 'height' => self::HEIGHT, 'type' => 'image/jpeg'];

        if ($disk->exists($path)) {
            return $result;
        }

        $failedKey = 'seo:social-image-failed:'.$hash;

        if (Cache::has($failedKey)) {
            return null;
        }

        $bytes = ImageBytes::load($stored);
        $image = $bytes !== null ? ImageBytes::decode($bytes) : null;

        if ($image === null) {
            Cache::put($failedKey, true, self::FAILED_TTL);

            return null;
        }

        $disk->put($path, ImageBytes::jpeg(ImageBytes::cover($image, self::WIDTH, self::HEIGHT), 86));

        return $result;
    }

    public static function isSvgPath(string $stored): bool
    {
        $path = parse_url($stored, PHP_URL_PATH);

        return is_string($path) && str_ends_with(strtolower($path), '.svg');
    }
}
