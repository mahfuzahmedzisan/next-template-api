<?php

namespace App\Support;

/**
 * Just enough user-agent parsing to label a device in the sign-in history.
 * Order matters: Edge, Opera and Samsung Internet all claim to be Chrome,
 * and Chrome claims to be Safari.
 */
final class UserAgent
{
    private const BROWSERS = [
        'Edge' => '/Edg(?:e|A|iOS)?\/(\d+)/',
        'Opera' => '/(?:OPR|Opera)\/(\d+)/',
        'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
        'Firefox' => '/(?:Firefox|FxiOS)\/(\d+)/',
        'Chrome' => '/(?:Chrome|CriOS)\/(\d+)/',
        'Safari' => '/Version\/(\d+)(?:\.\d+)*.*Safari\//',
    ];

    private const PLATFORMS = [
        'iPadOS' => '/iPad/',
        'iOS' => '/iPhone|iPod/',
        'Android' => '/Android/',
        'ChromeOS' => '/CrOS/',
        'Windows' => '/Windows/',
        'macOS' => '/Macintosh|Mac OS X/',
        'Linux' => '/Linux/',
    ];

    /**
     * @return array{browser: string|null, platform: string|null, device_type: string}
     */
    public static function parse(?string $userAgent): array
    {
        $agent = (string) $userAgent;

        return [
            'browser' => self::browser($agent),
            'platform' => self::match(self::PLATFORMS, $agent),
            'device_type' => self::deviceType($agent),
        ];
    }

    private static function browser(string $agent): ?string
    {
        foreach (self::BROWSERS as $name => $pattern) {
            if (preg_match($pattern, $agent, $matches) === 1) {
                return $name.' '.$matches[1];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function match(array $patterns, string $agent): ?string
    {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $agent) === 1) {
                return $name;
            }
        }

        return null;
    }

    private static function deviceType(string $agent): string
    {
        if ($agent === '') {
            return 'unknown';
        }

        if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $agent) === 1) {
            return 'tablet';
        }

        if (preg_match('/Mobi|iPhone|iPod|Android/i', $agent) === 1) {
            return 'mobile';
        }

        return self::match(self::PLATFORMS, $agent) === null ? 'unknown' : 'desktop';
    }
}
