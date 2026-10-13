<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Each case is an issue external SEO checkers reported on the live site. The
 * scanner must catch it (fail case) and pass once it is fixed (golden site).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.name' => 'Northwind']);
    Http::preventStrayRequests();
});

/**
 * @return array<string, array{0: string, 1: Closure(): array<string, mixed>, 2: string}> issue => [check, goldenAudit case, expected actual]
 */
function checkerIssues(): array
{
    $o = GOLDEN_ORIGIN;
    $title = '<title>About page: how Northwind builds calm tools for sales teams</title>';
    $description = 'About: Northwind builds calm, fast tools for small sales teams. Meet the people, the ideas and the craft behind every release we ship to customers.';
    $svg = fn () => Http::response('<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"></svg>', 200, ['Content-Type' => 'image/svg+xml']);

    return [
        'favicon.ico is a PNG renamed to .ico' => ['favicon_ico', fn () => ['overrides' => [
            '/favicon.ico' => Http::response(pngBytes(32, 32), 200, ['Content-Type' => 'image/png']),
        ]], 'png file served as image/png'],
        '/favicon.ico redirects to another file' => ['favicon_ico', fn () => ['overrides' => [
            '/favicon.ico' => Http::response('', 307, ['Location' => '/icon.svg']),
        ]], 'redirects'],
        'icon link claims image/x-icon but serves SVG' => ['favicon_links', fn () => ['overrides' => ['/favicon.ico' => $svg()]], 'claims image/x-icon but is svg'],
        'no apple-touch-icon' => ['apple_touch_icon', fn () => ['html' => ['<link rel="apple-touch-icon" href="/apple-icon.png">' => '']], 'none'],
        'no web app manifest' => ['web_manifest', fn () => ['html' => ['<link rel="manifest" href="/manifest.webmanifest">' => '']], 'none'],
        'social image is 1200x630, not 1.91:1' => ['og_image', fn () => ['og' => [1200, 630]], '1200x630'],
        'social image is an SVG' => ['og_image', fn () => ['overrides' => ['/og' => $svg()]], 'svg'],
        'og:image:width and height are missing' => ['og_image_meta', fn () => ['html' => [
            '<meta property="og:image:width" content="1200">' => '',
            '<meta property="og:image:height" content="628">' => '',
        ]], 'missing og:image:width, og:image:height'],
        'title is 46 characters' => ['title_length', fn () => ['html' => [$title => '<title>About Northwind: calm CRM tool for small teams</title>']], '46 characters'],
        'meta description is 134 characters' => ['description_length', fn () => ['html' => [$description => rtrim(mb_substr($description, 0, 133)).'.']], '134 characters'],
        'only about 100 words' => ['content_length', fn () => ['html' => [healthyText() => str_repeat('Northwind builds calm tools for sales teams. ', 10)]], 'words'],
        'three H2 and no H3' => ['headings', fn () => ['html' => ['<h3>Where it started</h3>' => '<h2>Our craft</h2>']], '3 H2, 0 H3'],
        'two internal links' => ['internal_links', fn () => ['html' => [
            '<a href="/projects">Projects</a>' => '',
            '<a href="/blog">Blog</a>' => '',
            '<a href="/privacy-policy">Privacy</a>' => '',
        ]], '2 internal links'],
        'no external links' => ['external_links', fn () => ['html' => ['<a href="https://github.com/northwind">GitHub</a>' => '']], 'none'],
        'text-to-HTML ratio around 1%' => ['text_ratio', fn () => ['html' => ['</head>' => '<script>'.str_repeat('window.__data.push([1,"chunk"]);', 12000).'</script></head>']], '%'],
        'metadata streamed into <body> for browsers' => ['head_metadata', fn () => ['overrides' => ['/about' => fn (Request $request) => Http::response(
            str_contains($request->header('User-Agent')[0] ?? '', 'Chrome')
                ? strtr(healthyHtml($o, '/about', [$title => '']), ['<body>' => '<body><title>About</title>'])
                : healthyHtml($o, '/about'),
            200,
            ['Content-Type' => 'text/html'],
        )]], 'missing in <head> for browsers: title'],
        '/index.php answers 403' => ['index_redirects', fn () => ['overrides' => ['/index.php' => Http::response('Forbidden', 403)]], '/index.php (403)'],
        '/index.html answers 404' => ['index_redirects', fn () => ['overrides' => ['/index.html' => Http::response('Not found', 404)]], '/index.html (404)'],
        'sitemap answers 503' => ['sitemap_available', fn () => ['overrides' => ['/sitemap.xml' => Http::response('Service Unavailable', 503)]], 'HTTP 503'],
    ];
}

it('passes every checker issue on the fixed site', function (): void {
    $audit = goldenAudit();
    $keys = array_values(array_unique(array_column(checkerIssues(), 0)));

    foreach ($keys as $key) {
        $rows = checkResults($audit, $key);

        expect($rows)->not->toBeEmpty()
            ->and($rows->every(fn (array $row) => $row['passed']))->toBeTrue("{$key} should pass on the fixed site");
    }
});

it('catches the checker issue', function (string $issue): void {
    [$key, $case, $actual] = checkerIssues()[$issue];
    $failed = checkResults(goldenAudit($case()), $key)->firstWhere('passed', false);

    expect($failed)->not->toBeNull()
        ->and($failed['actual'])->toContain($actual);
})->with(fn () => array_keys(checkerIssues()));

it('leaves the Next.js RSC payload out of the text-to-code ratio but reports the raw figure', function (): void {
    $flight = '<script>self.__next_f.push([1,"'.str_repeat('chunk', 12000).'"])</script>';
    $row = checkResults(goldenAudit(['html' => ['</body>' => $flight.'</body>']]), 'text_ratio')->first();

    expect($row['passed'])->toBeTrue()
        ->and($row['actual'])->toMatch('/^[\d.]+% \([\d.]+% with Next\.js data\)$/');
});

it('fails real checks when the body content sits behind a client-side streaming swap', function (): void {
    // Reproduces Next.js's streaming SSR shape for a page with a root
    // `loading.tsx`: the visible shell only has a 2-word loading fallback,
    // and every real heading/paragraph/link is inert inside `hidden`, only
    // reachable once a client script moves it into place.
    $audit = goldenAudit(['html' => [
        '<body>' => '<body><div class="route-fallback" role="status"><span class="sr-only">Loading page</span></div><div hidden id="S:0">',
        '</body>' => '</div><script>$RC("B:0","S:0")</script></body>',
    ]]);

    expect(checkResults($audit, 'h1_single')->firstWhere('passed', false)['actual'] ?? null)->toBe('no H1')
        ->and(checkResults($audit, 'content_length')->every(fn (array $row) => ! $row['passed']))->toBeTrue()
        ->and(checkResults($audit, 'internal_links')->every(fn (array $row) => ! $row['passed']))->toBeTrue()
        ->and(checkResults($audit, 'text_ratio')->every(fn (array $row) => ! $row['passed']))->toBeTrue();

    // <head> metadata is resolved before streaming starts, so it is
    // unaffected - matching what real non-JS checkers report in practice.
    expect(checkResults($audit, 'title_present')->every(fn (array $row) => $row['passed']))->toBeTrue();
});
