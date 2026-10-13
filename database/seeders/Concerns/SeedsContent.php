<?php

namespace Database\Seeders\Concerns;

use App\Services\Seo\SocialImage;
use App\Support\ImageBytes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Shared helpers for the demo content seeders. Like SeoPageSeeder they only
 * fill what is still empty, so edited records are never overwritten, unless
 * overwrite mode is switched on (demo:refresh-content) to rewrite the demo
 * records to the current copy.
 */
trait SeedsContent
{
    /** Fields that keep their stored value even in overwrite mode. */
    private const KEPT_ON_OVERWRITE = ['published_at'];

    private bool $overwrite = false;

    public function overwriting(bool $overwrite = true): static
    {
        $this->overwrite = $overwrite;

        return $this;
    }

    /**
     * A 1200x628 abstract cover (no text, so no font is needed), written once.
     */
    protected function cover(string $path, int $hue): string
    {
        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            return $path;
        }

        $width = SocialImage::WIDTH;
        $height = SocialImage::HEIGHT;
        $image = imagecreatetruecolor($width, $height);
        [$r1, $g1, $b1] = $this->hsl($hue, 0.55, 0.18);
        [$r2, $g2, $b2] = $this->hsl(($hue + 40) % 360, 0.65, 0.42);

        for ($x = 0; $x < $width; $x++) {
            $t = $x / ($width - 1);
            $color = imagecolorallocate($image, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imageline($image, $x, 0, $x, $height, (int) $color);
        }

        imagealphablending($image, true);
        [$r3, $g3, $b3] = $this->hsl(($hue + 180) % 360, 0.7, 0.6);

        foreach ([[880, 160, 420], [1040, 470, 300], [240, 520, 260]] as $i => [$cx, $cy, $size]) {
            $shape = imagecolorallocatealpha($image, $r3, $g3, $b3, 90 + $i * 10);
            imagefilledellipse($image, $cx, $cy, $size, $size, (int) $shape);
        }

        $line = imagecolorallocatealpha($image, 255, 255, 255, 105);
        for ($y = 80; $y < $height; $y += 56) {
            imageline($image, 80, $y, 560, $y, (int) $line);
        }

        $disk->put($path, ImageBytes::jpeg($image, 84));

        return $path;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function fillBlank(Model $model, array $values): void
    {
        foreach ($values as $field => $value) {
            $replace = $this->overwrite && ! in_array($field, self::KEPT_ON_OVERWRITE, true);

            if ($replace || blank($model->getAttribute($field))) {
                $model->setAttribute($field, $value);
            }
        }
    }

    /**
     * SEO row for a record; the seeded title is final, so no site affix is added.
     *
     * @param  array<string, mixed>  $seo
     */
    protected function seedSeo(Model $model, array $seo): void
    {
        /** @var Model $meta */
        $meta = $model->seo()->firstOrNew();
        $seeded = $this->overwrite || blank($meta->getAttribute('meta_title'));
        $this->fillBlank($meta, $seo);

        if ($seeded) {
            $meta->setAttribute('title_affix_mode', 'none');
        }

        if ($meta->isDirty()) {
            $model->seo()->save($meta);
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hsl(int $hue, float $saturation, float $lightness): array
    {
        $c = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - $c / 2;

        [$r, $g, $b] = match (intdiv($hue % 360, 60)) {
            0 => [$c, $x, 0],
            1 => [$x, $c, 0],
            2 => [0, $c, $x],
            3 => [0, $x, $c],
            4 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }
}
