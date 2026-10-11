<?php

namespace App\Audit\Support;

final class Redactor
{
    public const MASK = '[redacted]';

    /**
     * Replaces the value of every sensitive key, at any depth. Empty values are
     * kept so a diff still shows that a secret was set or cleared.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = ($value === null || $value === '') ? $value : self::MASK;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    public static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach ((array) config('audit.redact', []) as $pattern) {
            if ($pattern !== '' && str_contains($key, strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }
}
