<?php

namespace App\Services\Brand;

use App\Support\ImageBytes;
use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the files browsers and crawlers ask for from the admin's favicon:
 * a real favicon.ico (16, 32 and 48 px PNG entries), the 192 and 512 px
 * manifest icons and the 180 px Apple touch icon. The uploaded source is
 * never touched. SVG sources return null; the frontend renders those.
 */
final class FaviconSet
{
    public const DIRECTORY = 'settings/favicon-set';

    public const ICO_SIZES = [16, 32, 48];

    /** @var array<string, int> */
    public const PNG_SIZES = [
        'icon_192' => 192,
        'icon_512' => 512,
        'apple_touch_icon' => 180,
    ];

    private const MAX_SOURCE_BYTES = 5 * 1024 * 1024;

    /**
     * @return array<string, string>|null Public-disk paths keyed by file.
     */
    public function generate(?string $source): ?array
    {
        $bytes = ImageBytes::load($source, self::MAX_SOURCE_BYTES);

        if ($bytes === null) {
            return null;
        }

        $ico = ImageBytes::isIco($bytes) ? $bytes : null;
        $image = ImageBytes::decode($ico !== null ? (self::largestPngEntry($ico) ?? '') : $bytes);

        if ($image === null && $ico === null) {
            return null;
        }

        $directory = self::DIRECTORY.'/'.substr(sha1($bytes), 0, 16);
        $disk = Storage::disk('public');
        $files = ['favicon_ico' => $directory.'/favicon.ico'];

        $disk->put($files['favicon_ico'], $ico ?? self::ico($image));

        if ($image !== null) {
            foreach (self::PNG_SIZES as $key => $size) {
                $files[$key] = $directory.'/'.str_replace('_', '-', $key).'.png';
                $disk->put($files[$key], ImageBytes::png(ImageBytes::contain($image, $size)));
            }
        }

        return $files;
    }

    /**
     * Deletes a generated set. Only paths inside the set directory are removed.
     *
     * @param  array<string, mixed>|null  $files
     */
    public function delete(?array $files): void
    {
        $directories = collect($files ?? [])
            ->filter(fn ($path) => is_string($path) && str_starts_with($path, self::DIRECTORY.'/') && ! str_contains($path, '..'))
            ->map(fn (string $path) => dirname($path))
            ->unique();

        foreach ($directories as $directory) {
            Storage::disk('public')->deleteDirectory($directory);
        }
    }

    /** PNG-in-ICO; every modern browser and crawler reads PNG entries. */
    public static function ico(GdImage $image): string
    {
        $entries = array_map(
            fn (int $size) => [$size, ImageBytes::png(ImageBytes::contain($image, $size))],
            self::ICO_SIZES,
        );

        $count = count($entries);
        $offset = 6 + 16 * $count;
        $directory = pack('vvv', 0, 1, $count);
        $data = '';

        foreach ($entries as [$size, $png]) {
            $directory .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($png), $offset);
            $data .= $png;
            $offset += strlen($png);
        }

        return $directory.$data;
    }

    /**
     * Sizes of every entry in an ICO file, for tests and diagnostics.
     *
     * @return list<array{width: int, height: int, png: bool, offset: int, length: int}>
     */
    public static function icoEntries(string $ico): array
    {
        if (! ImageBytes::isIco($ico) || strlen($ico) < 6) {
            return [];
        }

        $count = unpack('v', substr($ico, 4, 2))[1];
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $entry = substr($ico, 6 + 16 * $i, 16);

            if (strlen($entry) < 16) {
                break;
            }

            $info = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vlength/Voffset', $entry);
            $entries[] = [
                'width' => $info['width'] ?: 256,
                'height' => $info['height'] ?: 256,
                'png' => substr($ico, $info['offset'], 8) === "\x89PNG\r\n\x1a\n",
                'offset' => $info['offset'],
                'length' => $info['length'],
            ];
        }

        return $entries;
    }

    private static function largestPngEntry(string $ico): ?string
    {
        $best = collect(self::icoEntries($ico))
            ->filter(fn (array $entry) => $entry['png'])
            ->sortByDesc('width')
            ->first();

        return $best === null ? null : substr($ico, $best['offset'], $best['length']);
    }
}
