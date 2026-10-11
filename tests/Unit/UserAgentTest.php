<?php

use App\Support\UserAgent;

it('labels common browsers and platforms', function (string $agent, ?string $browser, ?string $platform, string $type): void {
    expect(UserAgent::parse($agent))->toBe([
        'browser' => $browser,
        'platform' => $platform,
        'device_type' => $type,
    ]);
})->with([
    'chrome on windows' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
        'Chrome 141', 'Windows', 'desktop',
    ],
    'edge on windows' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0',
        'Edge 141', 'Windows', 'desktop',
    ],
    'safari on iphone' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        'Safari 18', 'iOS', 'mobile',
    ],
    'firefox on mac' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:131.0) Gecko/20100101 Firefox/131.0',
        'Firefox 131', 'macOS', 'desktop',
    ],
    'chrome on android phone' => [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
        'Chrome 141', 'Android', 'mobile',
    ],
    'android tablet' => [
        'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
        'Chrome 141', 'Android', 'tablet',
    ],
    'samsung internet' => [
        'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
        'Samsung Internet 26', 'Android', 'mobile',
    ],
    'ipad' => [
        'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'Safari 17', 'iPadOS', 'tablet',
    ],
    'unknown client' => ['curl/8.5.0', null, null, 'unknown'],
]);

it('treats an empty user agent as unknown', function (): void {
    expect(UserAgent::parse(null))->toBe([
        'browser' => null,
        'platform' => null,
        'device_type' => 'unknown',
    ]);
});
