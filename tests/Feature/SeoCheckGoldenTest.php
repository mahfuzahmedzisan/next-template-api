<?php

use App\Services\Seo\Audit\RobotsRules;
use App\Services\Seo\Audit\SeoCheckCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.name' => 'Northwind']);
    Http::preventStrayRequests();
});

/** Checks that only describe the site and have no failing state. */
const GOLDEN_DESCRIPTIVE = ['robots_ai_policy'];

/**
 * The smallest change to the healthy golden site that must fail the check.
 *
 * @return array<string, mixed> a goldenAudit() case
 */
function goldenFailure(string $key): array
{
    $o = GOLDEN_ORIGIN;
    $robots = static fn (string $rules, bool $sitemap = true) => ['/robots.txt' => Http::response(
        $rules.($sitemap ? "\n\nSitemap: {$o}/sitemap.xml\n" : "\n"),
        200,
        ['Content-Type' => 'text/plain'],
    )];
    $sitemapFile = static fn (string $xml) => ['/sitemap.xml' => Http::response($xml, 200, ['Content-Type' => 'application/xml'])];
    $title = '<title>About page: how Northwind builds calm tools for sales teams</title>';

    return match ($key) {
        'title_present' => ['html' => [$title => '']],
        'title_length' => ['html' => [$title => '<title>About Northwind</title>']],
        'title_unique' => ['extra' => ['/team' => healthyHtml($o, '/team', ['<title>Team page' => '<title>About page'])]],
        'title_matches_content' => ['html' => [$title => '<title>Pricing plans and invoices for growing companies worldwide</title>']],
        'description_present' => ['html' => ['<meta name="description"' => '<meta name="summary"']],
        'description_length' => ['html' => [' Meet the people, the ideas and the craft behind every release we ship to customers.' => '']],
        'description_unique' => ['extra' => ['/team' => healthyHtml($o, '/team', ['content="Team: Northwind' => 'content="About: Northwind'])]],
        'canonical' => ['html' => ["<link rel=\"canonical\" href=\"{$o}/about\">" => "<link rel=\"canonical\" href=\"{$o}/\">"]],
        'robots_meta' => ['html' => ['content="index, follow"' => 'content="noindex, follow"']],
        'head_metadata' => ['overrides' => ['/about' => fn (Request $request) => Http::response(
            str_contains($request->header('User-Agent')[0] ?? '', 'Chrome')
                ? strtr(healthyHtml($o, '/about', [$title => '']), ['<body>' => '<body><title>About</title>'])
                : healthyHtml($o, '/about'),
            200,
            ['Content-Type' => 'text/html'],
        )]],
        'og_tags' => ['html' => ['<meta property="og:locale" content="en_US">' => '']],
        'og_image' => ['og' => [1200, 630]],
        'og_image_meta' => ['html' => ['<meta property="og:image:alt" content="Northwind">' => '']],
        'twitter_card' => ['html' => ['<meta name="twitter:card" content="summary_large_image">' => '']],
        'lang' => ['html' => ['<html lang="en">' => '<html>']],
        'viewport' => ['html' => ['<meta name="viewport" content="width=device-width, initial-scale=1">' => '']],
        'charset' => [
            'html' => ['<meta charset="utf-8">' => ''],
            'headers' => ['Content-Type' => 'text/html', ...GOLDEN_HEADERS],
        ],
        'structured_data' => ['html' => ['"@context":"https://schema.org"' => '"@context":"https://example.org"']],
        'breadcrumbs' => ['html' => ['"@type":"BreadcrumbList"' => '"@type":"ItemList"']],
        'keywords_meta' => ['html' => ['<meta name="keywords" content="crm, sales">' => '']],
        'author_meta' => ['html' => ['<meta name="author" content="Northwind Team">' => '']],
        'h1_single' => ['html' => ['<h2>Our story</h2>' => '<h1>Our story</h1>']],
        'headings' => ['html' => ['<h3>Where it started</h3>' => '']],
        'content_length' => ['html' => [healthyText() => 'A short note about the team.']],
        'text_ratio' => ['html' => ['</head>' => '<script>'.str_repeat('window.__data.push(1);', 4000).'</script></head>']],
        'internal_links' => ['html' => ['<a href="/blog">Blog</a>' => '', '<a href="/privacy-policy">Privacy</a>' => '']],
        'external_links' => ['html' => ['<a href="https://github.com/northwind">GitHub</a>' => '']],
        'image_alt' => ['html' => [' alt="The team"' => '']],
        'image_dimensions' => ['html' => [' width="800" height="600"' => '']],
        'broken_links' => ['overrides' => ['/contact' => Http::response('', 404)]],
        'broken_images' => ['html' => ['/api/storage/team.jpg' => '/missing/team.jpg']],
        'status_ok' => ['overrides' => ['/about' => Http::response('', 500)]],
        'robots_txt_allows' => ['overrides' => $robots("User-agent: *\nDisallow: /about")],
        'https' => ['origin' => 'http://northwind.example'],
        'hsts' => ['headers' => Arr::except(GOLDEN_HEADERS, 'Strict-Transport-Security')],
        'security_headers' => ['headers' => [
            ...Arr::except(GOLDEN_HEADERS, 'X-Frame-Options'),
            'Content-Security-Policy-Report-Only' => "frame-ancestors 'none'",
        ]],
        'compression' => ['headers' => Arr::except(GOLDEN_HEADERS, 'Content-Encoding')],
        'ttfb' => ['config' => ['seo.audit.ttfb_ms' => -1]],
        'html_size' => ['config' => ['seo.audit.max_html_kb' => 0]],

        'favicon_ico' => ['overrides' => ['/favicon.ico' => Http::response(pngBytes(48, 48), 200, ['Content-Type' => 'image/x-icon'])]],
        'favicon_links' => ['html' => ['<link rel="icon" href="/favicon.ico"' => '<link rel="icon" href="/missing.ico"']],
        'apple_touch_icon' => ['overrides' => ['/apple-icon.png' => Http::response(pngBytes(192, 192), 200, ['Content-Type' => 'image/png'])]],
        'web_manifest' => ['overrides' => ['/manifest.webmanifest' => Http::response(['name' => 'Northwind', 'icons' => [['src' => '/icon-192.png', 'sizes' => '192x192']]], 200)]],
        'index_redirects' => ['overrides' => ['/index.php' => Http::response('Forbidden', 403)]],
        'soft_404' => ['overrides' => ['/seo-health-missing-*' => healthyHtml($o, '/')]],
        'trailing_slash' => ['overrides' => ['/about/' => healthyHtml($o, '/about')]],
        'https_redirect' => ['overrides' => ['http://northwind.example/' => Http::response('ok', 200)]],
        'single_host' => ['overrides' => ['https://www.northwind.example/' => Http::response('ok', 200)]],

        'robots_available' => ['overrides' => ['/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200, ['Content-Type' => 'text/html'])]],
        'robots_size' => ['config' => ['seo.audit.robots.max_kb' => 0]],
        'robots_syntax' => ['overrides' => $robots("User-agent: *\nAllow: /\nNoindex: /drafts")],
        'robots_wildcard_group' => ['overrides' => $robots("User-agent: googlebot\nAllow: /")],
        'robots_not_blocking_site' => ['overrides' => $robots("User-agent: bingbot\nDisallow: /\n\nUser-agent: *\nAllow: /")],
        'robots_assets_allowed' => ['overrides' => $robots("User-agent: *\nDisallow: /_next/")],
        'robots_pages_allowed' => ['overrides' => $robots("User-agent: *\nDisallow: /about")],
        'robots_sitemap_declared' => ['overrides' => $robots("User-agent: *\nAllow: /", sitemap: false)],

        'sitemap_available' => ['overrides' => ['/sitemap.xml' => Http::response('Service Unavailable', 503)]],
        'sitemap_valid_xml' => ['overrides' => $sitemapFile("<?xml version=\"1.0\"?><urlset><url><loc>{$o}/about</loc></url></urlset>")],
        'sitemap_limits' => ['config' => ['seo.audit.sitemap.max_urls' => 0]],
        'sitemap_urls_format' => ['sitemap' => ['/about', '/about']],
        'sitemap_urls_ok' => ['sitemap' => ['/about', '/gone']],
        'sitemap_urls_indexable' => ['sitemap' => ['/about', '/hidden'], 'overrides' => ['/hidden' => healthyHtml($o, '/hidden', ['content="index, follow"' => 'content="noindex, follow"'])]],
        'sitemap_coverage' => ['sitemap' => []],
        'sitemap_images' => ['overrides' => $sitemapFile(
            '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
            ."<url><loc>{$o}/about</loc><image:image><image:loc>{$o}/missing.png</image:loc></image:image></url></urlset>",
        )],
    };
}

it('passes every check on the healthy golden site', function (): void {
    $audit = goldenAudit();
    $keys = array_keys(SeoCheckCatalog::CHECKS);
    $failing = collect($keys)
        ->flatMap(fn (string $key) => checkResults($audit, $key)->where('passed', false)->map(fn (array $row) => "{$key}: {$row['actual']}"))
        ->all();
    $missing = array_values(array_filter($keys, fn (string $key) => checkResults($audit, $key)->isEmpty()));

    expect($audit->environment)->toBe('production')
        ->and($missing)->toBe([])
        ->and($failing)->toBe([])
        ->and($audit->score)->toBe(100);
});

it('reports every robots.txt syntax problem and groups rules per RFC 9309', function (): void {
    $clean = RobotsRules::parse("User-agent: Googlebot\nUser-agent: Bingbot\nDisallow: /drafts\n\nUser-agent: *\nAllow: /\nSitemap: https://northwind.example/sitemap.xml\n");
    $broken = RobotsRules::parse("\xEF\xBB\xBFDisallow: /early\nUser-agent: *\nDisallow private\nNoindex: /x\nUser-agent: bad agent!\nAllow: drafts\n");

    expect($clean->problems())->toBe([])
        ->and($clean->allows('/drafts/a', 'bingbot'))->toBeFalse()
        ->and($clean->allows('/drafts/a', 'googlebot'))->toBeFalse()
        ->and($clean->allows('/drafts/a'))->toBeTrue()
        ->and($clean->allows('/robots.txt', 'googlebot'))->toBeTrue()
        ->and($clean->sitemaps())->toBe(['https://northwind.example/sitemap.xml'])
        ->and(implode("\n", $broken->problems()))
        ->toContain('byte order mark')
        ->toContain('before any User-agent')
        ->toContain('has no colon')
        ->toContain('unknown directive "noindex"')
        ->toContain('not a valid user-agent token')
        ->toContain('must start with / or *');
});

it('accepts an enforced CSP frame-ancestors in place of X-Frame-Options', function (): void {
    $audit = goldenAudit(['headers' => [
        ...Arr::except(GOLDEN_HEADERS, 'X-Frame-Options'),
        'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'",
    ]]);

    expect(checkResults($audit, 'security_headers')->pluck('passed')->unique()->all())->toBe([true]);
});

it('fails each check on its golden failure case', function (string $key): void {
    $rows = checkResults(goldenAudit(goldenFailure($key)), $key);

    expect($rows)->not->toBeEmpty()
        ->and($rows->contains('passed', false))->toBeTrue();
})->with(array_values(array_diff(array_keys(SeoCheckCatalog::CHECKS), GOLDEN_DESCRIPTIVE)));
