<?php

namespace App\Support\Seo;

final class SeoPath
{
    public const MAX_LENGTH = 255;

    /**
     * Leading slash, no query or fragment, no trailing slash (except "/"),
     * no repeated slashes. Returns null for anything that isn't a plain path.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1 || str_starts_with($value, '//')) {
            return null;
        }

        $value = preg_split('/[?#]/', $value, 2)[0];
        $value = '/'.trim((string) preg_replace('#/+#', '/', $value), '/');

        if (strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match('#^/[A-Za-z0-9\-._~%/@:+]*$#', $value) !== 1 || str_contains($value, '..')) {
            return null;
        }

        return $value;
    }
}
