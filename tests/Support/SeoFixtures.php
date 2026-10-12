<?php

use App\Models\SeoAudit;
use App\Models\SeoAuditPage;
use App\Models\SeoPage;
use App\Services\Seo\Audit\SeoAuditRunner;
use App\Services\Seo\SeoSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

const GOLDEN_ORIGIN = 'https://northwind.example';

function auditPage(string $path, array $attributes = []): SeoPage
{
    return SeoPage::withoutEvents(fn () => SeoPage::query()->create([
        'path' => $path,
        'label' => ucfirst(trim($path, '/')) ?: 'Home',
        'page_type' => $path === '/' ? 'home' : 'about',
        ...$attributes,
    ]));
}

function healthyText(): string
{
    return str_repeat('Northwind builds calm tools for sales teams and every page explains how the product helps people work with less noise. ', 42);
}

/**
 * A page that passes every strict check: lengths, unique copy per path,
 * 800+ words, H1-H2-H3 outline, 5 internal links, sized images, complete
 * Open Graph and X tags, and JSON-LD with breadcrumbs.
 */
function healthyHtml(string $origin, string $path, array $replace = []): string
{
    $label = ucfirst(trim($path, '/')) ?: 'Home';
    $url = $origin.$path;
    $text = healthyText();
    $schema = json_encode(['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'AboutPage', 'name' => $label, 'url' => $url],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $origin.'/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $label, 'item' => $url],
        ]],
    ]], JSON_UNESCAPED_SLASHES);

    $html = <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>{$label} page: how Northwind builds calm tools for sales teams</title>
      <meta name="description" content="{$label}: Northwind builds calm, fast tools for small sales teams. Meet the people, the ideas and the craft behind every release we ship to customers.">
      <meta name="keywords" content="crm, sales">
      <meta name="author" content="Northwind Team">
      <meta name="robots" content="index, follow">
      <link rel="canonical" href="{$url}">
      <meta property="og:title" content="{$label} at Northwind">
      <meta property="og:description" content="Who we are">
      <meta property="og:url" content="{$url}">
      <meta property="og:type" content="website">
      <meta property="og:site_name" content="Northwind">
      <meta property="og:locale" content="en_US">
      <meta property="og:image" content="{$origin}/og">
      <meta property="og:image:width" content="1200">
      <meta property="og:image:height" content="628">
      <meta property="og:image:alt" content="Northwind">
      <meta name="twitter:card" content="summary_large_image">
      <meta name="twitter:title" content="{$label} at Northwind">
      <meta name="twitter:image" content="{$origin}/og">
      <link rel="icon" href="/favicon.ico" type="image/x-icon" sizes="any">
      <link rel="apple-touch-icon" href="/apple-icon.png">
      <link rel="manifest" href="/manifest.webmanifest">
      <script type="application/ld+json">{$schema}</script>
    </head>
    <body>
      <main>
        <h1>{$label} Northwind</h1>
        <h2>Our story</h2>
        <h3>Where it started</h3>
        <p>{$text}</p>
        <h2>Our team</h2>
        <img src="/api/storage/team.jpg" alt="The team" width="800" height="600">
        <a href="/contact">Contact us</a>
        <a href="/services">Services</a>
        <a href="/projects">Projects</a>
        <a href="/blog">Blog</a>
        <a href="/privacy-policy">Privacy</a>
        <a href="https://github.com/northwind">GitHub</a>
      </main>
    </body>
    </html>
    HTML;

    return strtr($html, $replace);
}

/** The first bytes of a PNG, enough for the image probe to read its size. */
function pngBytes(int $width, int $height): string
{
    return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', $width, $height).str_repeat("\0", 5);
}

/**
 * An ICO directory listing the given square sizes.
 *
 * @param  list<int>  $sizes
 */
function icoBytes(array $sizes): string
{
    $entries = implode('', array_map(static fn (int $size) => chr($size % 256).chr($size % 256).str_repeat("\0", 14), $sizes));

    return "\x00\x00\x01\x00".pack('v', count($sizes)).$entries;
}

/**
 * @param  list<string>  $paths
 */
function sitemapXml(string $origin, array $paths): string
{
    $urls = implode('', array_map(static fn (string $path) => "<url><loc>{$origin}{$path}</loc><lastmod>2026-10-01</lastmod></url>", $paths));

    return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>';
}

/**
 * A complete, healthy site: the given pages plus a home page, icons, manifest,
 * robots.txt, sitemap, index and trailing-slash redirects and real 404s.
 * Overrides replace any default by path, or by full URL for other hosts.
 *
 * @param  array<string, mixed>  $pages  path => html|Closure
 * @param  list<string>|null  $sitemap  paths listed in the sitemap (default: every page that is not noindex)
 * @param  array<string, mixed>  $overrides  path|url => response|Closure
 */
function fakeSite(string $origin, array $pages, array $headers = [], ?array $ogImage = null, ?array $sitemap = null, array $overrides = []): void
{
    $html = static fn (string $body) => Http::response($body, 200, ['Content-Type' => 'text/html; charset=utf-8', ...$headers]);
    $sitemap ??= array_keys(array_filter($pages, static fn ($page) => ! is_string($page) || ! str_contains($page, 'noindex')));
    $host = (string) parse_url($origin, PHP_URL_HOST);

    $fakes = [
        '/robots.txt' => Http::response("User-agent: *\nDisallow: /admin\nAllow: /\n\nSitemap: {$origin}/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']),
        '/sitemap.xml' => Http::response(sitemapXml($origin, $sitemap), 200, ['Content-Type' => 'application/xml']),
        '/favicon.ico' => Http::response(icoBytes([48, 32, 16]), 200, ['Content-Type' => 'image/x-icon']),
        '/apple-icon.png' => Http::response(pngBytes(180, 180), 200, ['Content-Type' => 'image/png']),
        '/manifest.webmanifest' => Http::response(['name' => 'Northwind', 'icons' => [['src' => '/icon-192.png', 'sizes' => '192x192'], ['src' => '/icon-512.png', 'sizes' => '512x512']]], 200),
        '/og' => Http::response(pngBytes(...($ogImage ?? [1200, 628])), 200, ['Content-Type' => 'image/png']),
        '/api/storage/*' => Http::response('', 200),
        '/' => $html(healthyHtml($origin, '/')),
    ];

    foreach (['/contact', '/services', '/projects', '/blog', '/privacy-policy'] as $path) {
        $fakes[$path] = Http::response('', 200);
    }

    foreach ([...$pages, ...$overrides] as $path => $page) {
        $fakes[$path] = is_string($page) ? $html($page) : $page;
    }

    $routed = [];

    foreach ($fakes as $path => $response) {
        $routed[str_starts_with($path, 'http') ? $path : $origin.$path] = $response;
    }

    $explicit = array_keys($routed);

    $routed['*'] = function (Request $request) use ($origin, $host, $explicit) {
        $parts = parse_url($request->url());
        $path = $parts['path'] ?? '/';

        // Every stub runs for every request, so stay out of URLs another stub answers.
        if (in_array($request->url(), $explicit, true)) {
            return null;
        }

        if (($parts['host'] ?? '') !== $host) {
            throw new ConnectionException('Could not resolve host');
        }

        if (str_starts_with($origin, 'https://') && ($parts['scheme'] ?? '') === 'http') {
            return Http::response('', 301, ['Location' => 'https://'.$host.$path]);
        }

        if (in_array($path, ['/index.html', '/index.php', '/home'], true)) {
            return Http::response('', 301, ['Location' => '/']);
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            return Http::response('', 308, ['Location' => rtrim($path, '/')]);
        }

        return Http::response('Not found', 404);
    };

    Http::fake($routed);
}

function runAudit(bool $includeNoindex = false, string $scope = 'full'): SeoAudit
{
    $audit = app(SeoAuditRunner::class)->start(null, $includeNoindex, scope: $scope);

    return $audit->refresh();
}

function sectionScore(SeoAudit $audit, string $section): ?int
{
    return collect($audit->summary['sections'])->firstWhere('key', $section)['score'] ?? null;
}

function auditCheck(SeoAudit $audit, string $url, string $key): array
{
    return collect($audit->pages()->where('url', $url)->first()->checks)->firstWhere('key', $key) ?? [];
}

/**
 * A full audit of the healthy production site at GOLDEN_ORIGIN (an about
 * page plus the home page) with one change applied. "html" replacements
 * apply to both pages; "extra" adds more scanned pages.
 *
 * @param  array{origin?: string, html?: array<string, string>, extra?: array<string, string>, overrides?: array<string, mixed>, headers?: array<string, string>, og?: array{0: int, 1: int}, sitemap?: list<string>, config?: array<string, mixed>}  $case
 */
function goldenAudit(array $case = []): SeoAudit
{
    $origin = $case['origin'] ?? GOLDEN_ORIGIN;
    $replace = $case['html'] ?? [];
    $extra = $case['extra'] ?? [];

    config($case['config'] ?? []);
    app(SeoSettings::class)->update(['site_url' => $origin]);

    foreach (['/about', ...array_keys($extra)] as $path) {
        auditPage($path);
    }

    fakeSite(
        $origin,
        ['/' => healthyHtml($origin, '/', $replace), '/about' => healthyHtml($origin, '/about', $replace), ...$extra],
        $case['headers'] ?? ['Content-Encoding' => 'gzip', 'Strict-Transport-Security' => 'max-age=31536000'],
        $case['og'] ?? null,
        $case['sitemap'] ?? ['/about', ...array_keys($extra)],
        $case['overrides'] ?? [],
    );

    return runAudit();
}

/**
 * Every result row for a check: from each scanned page and the site,
 * robots.txt and sitemap reports.
 *
 * @return Collection<int, array<string, mixed>>
 */
function checkResults(SeoAudit $audit, string $key): Collection
{
    return $audit->pages()->get()
        ->flatMap(fn (SeoAuditPage $page) => $page->checks ?? [])
        ->merge($audit->site_checks['checks'] ?? [])
        ->merge($audit->robots_report['checks'] ?? [])
        ->merge($audit->sitemap_report['checks'] ?? [])
        ->where('key', $key)
        ->values();
}
