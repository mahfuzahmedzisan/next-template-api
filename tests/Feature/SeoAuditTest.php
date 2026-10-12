<?php

use App\Jobs\Seo\RunSeoAudit;
use App\Models\SeoAudit;
use App\Models\SeoPage;
use App\Models\User;
use App\Services\Seo\Audit\RobotsRules;
use App\Services\Seo\Audit\SeoAuditRunner;
use App\Services\Seo\Audit\SeoCheckCatalog;
use App\Services\Seo\SeoSettings;
use Database\Seeders\SeoPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.name' => 'Northwind']);
    Http::preventStrayRequests();
});

function auditPage(string $path, array $attributes = []): SeoPage
{
    return SeoPage::withoutEvents(fn () => SeoPage::query()->create([
        'path' => $path,
        'label' => ucfirst(trim($path, '/')) ?: 'Home',
        'page_type' => $path === '/' ? 'home' : 'about',
        ...$attributes,
    ]));
}

function healthyHtml(string $origin, string $path, array $replace = []): string
{
    $html = <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>About the Northwind team and how we work</title>
      <meta name="description" content="Northwind builds calm, fast tools for small sales teams. Meet the people and the ideas behind them.">
      <meta name="keywords" content="crm, sales">
      <meta name="author" content="Northwind Team">
      <meta name="robots" content="index, follow">
      <link rel="canonical" href="{$origin}{$path}">
      <meta property="og:title" content="About Northwind">
      <meta property="og:description" content="Who we are">
      <meta property="og:url" content="{$origin}{$path}">
      <meta property="og:image" content="{$origin}/og">
      <meta name="twitter:card" content="summary_large_image">
      <script type="application/ld+json">{"@context":"https://schema.org","@type":"AboutPage","name":"About"}</script>
    </head>
    <body>
      <main>
        <h1>About Northwind</h1>
        <h2>Our story</h2>
        <p>We build software.</p>
        <img src="/api/storage/team.jpg" alt="The team">
        <a href="/contact">Contact us</a>
      </main>
    </body>
    </html>
    HTML;

    return strtr($html, $replace);
}

/**
 * @param  array<string, mixed>  $pages  path => html|Closure
 */
function fakeSite(string $origin, array $pages, array $headers = []): void
{
    $fakes = [
        $origin.'/robots.txt' => Http::response("User-agent: *\nDisallow: /admin\nAllow: /\n", 200),
        $origin.'/og' => Http::response('', 200),
        $origin.'/api/storage/*' => Http::response('', 200),
        $origin.'/contact' => Http::response('', 200),
    ];

    foreach ($pages as $path => $html) {
        $fakes[$origin.$path] = $html instanceof Closure ? $html : Http::response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', ...$headers]);
    }

    Http::fake($fakes);
}

function runAudit(bool $includeNoindex = false): SeoAudit
{
    $audit = app(SeoAuditRunner::class)->start(null, $includeNoindex);

    return $audit->refresh();
}

it('scores a healthy production page at 100 and ranks real issues', function (): void {
    app(SeoSettings::class)->update(['site_url' => 'https://northwind.example']);
    auditPage('/about');
    auditPage('/team');

    fakeSite('https://northwind.example', [
        '/about' => healthyHtml('https://northwind.example', '/about'),
        '/team' => healthyHtml('https://northwind.example', '/team', [
            '<meta name="description" content="Northwind builds calm, fast tools for small sales teams. Meet the people and the ideas behind them.">' => '',
            '<h1>About Northwind</h1>' => '<h1>One</h1><h1>Two</h1>',
        ]),
    ], ['Content-Encoding' => 'gzip', 'Strict-Transport-Security' => 'max-age=31536000']);

    $audit = runAudit();
    $pages = $audit->pages()->get()->keyBy('url');

    expect($audit->status)->toBe(SeoAudit::COMPLETED)
        ->and($audit->environment)->toBe('production')
        ->and($pages['https://northwind.example/about']->score)->toBe(100)
        ->and($pages['https://northwind.example/team']->score)->toBeLessThan(100)
        ->and($audit->score)->toBe((int) round(($pages['https://northwind.example/about']->score + $pages['https://northwind.example/team']->score) / 2));

    $issues = collect($audit->summary['issues'])->keyBy('key');

    expect($issues->keys()->all())->toContain('description_present', 'h1_single')
        ->and($issues['description_present']['pages'])->toBe(1)
        ->and($audit->summary['issues'][0]['priority'])->toBe('high')
        ->and(collect($audit->summary['categories'])->pluck('key')->all())->toContain('meta', 'content', 'configuration', 'performance');
});

it('flags a development scan and leaves performance and environment checks out of the score', function (): void {
    auditPage('/about');
    fakeSite('http://localhost:3000', ['/about' => healthyHtml('http://localhost:3000', '/about')]);

    $audit = runAudit();
    $page = $audit->pages()->first();
    $https = collect($page->checks)->firstWhere('key', 'https');
    $ttfb = collect($page->checks)->firstWhere('key', 'ttfb');

    expect($audit->environment)->toBe('development')
        ->and($https['passed'])->toBeFalse()
        ->and($https['counted'])->toBeFalse()
        ->and($ttfb['counted'])->toBeFalse()
        ->and($page->score)->toBe(100)
        ->and(collect($audit->summary['not_scored'])->pluck('key')->all())->toContain('https', 'compression', 'ttfb');
});

it('sends a warm-up request to every URL before a development scan', function (): void {
    auditPage('/about');
    fakeSite('http://localhost:3000', ['/about' => healthyHtml('http://localhost:3000', '/about')]);

    runAudit();

    $gets = Http::recorded(fn (Request $request) => $request->method() === 'GET' && $request->url() === 'http://localhost:3000/about');

    expect($gets)->toHaveCount(2);
});

it('runs content length only on detail pages and skips configured checks per page type', function (): void {
    $auth = SeoCheckCatalog::forPage('page', 'auth', true);
    $about = SeoCheckCatalog::forPage('page', 'about', true);
    $blog = SeoCheckCatalog::forPage('model', 'blog', true);

    expect($about)->not->toContain('content_length')
        ->and($blog)->toContain('content_length')
        ->and($auth)->not->toContain('h2_present', 'internal_links', 'og_image')
        ->and(SeoCheckCatalog::forPage('page', 'about', false))->toBe(SeoCheckCatalog::NOINDEX_CHECKS);

    foreach (['keywords_meta', 'author_meta'] as $key) {
        expect(SeoCheckCatalog::get($key))->toMatchArray(['weight' => 1, 'informational' => true]);
    }
});

it('keeps informational checks out of the top issues', function (): void {
    auditPage('/about');
    fakeSite('http://localhost:3000', ['/about' => healthyHtml('http://localhost:3000', '/about', [
        '<meta name="keywords" content="crm, sales">' => '',
    ])]);

    $audit = runAudit();

    expect(collect($audit->summary['issues'])->pluck('key')->all())->not->toContain('keywords_meta')
        ->and(collect($audit->summary['informational'])->firstWhere('key', 'keywords_meta')['failed'])->toBe(1);
});

it('skips noindex pages by default and never scores them when included', function (): void {
    $this->seed(SeoPageSeeder::class);
    $origin = 'http://localhost:3000';
    $noindex = healthyHtml($origin, '/login', ['<meta name="robots" content="index, follow">' => '<meta name="robots" content="noindex, nofollow">']);

    fakeSite($origin, [
        '/' => healthyHtml($origin, '/'),
        '/login' => $noindex,
        '/register' => $noindex,
        '/forgot-password' => $noindex,
        '/reset-password' => $noindex,
        '/two-factor-challenge' => $noindex,
    ]);

    $default = runAudit();

    expect($default->pages()->count())->toBe(1)
        ->and($default->pages()->first()->url)->toBe($origin.'/');

    $withNoindex = runAudit(includeNoindex: true);
    $login = $withNoindex->pages()->where('url', $origin.'/login')->first();

    expect($withNoindex->pages()->count())->toBe(6)
        ->and($login->indexable)->toBeFalse()
        ->and($login->score)->toBeNull()
        ->and(collect($login->checks)->pluck('key')->all())->toBe(SeoCheckCatalog::NOINDEX_CHECKS)
        ->and(collect($login->checks)->firstWhere('key', 'robots_meta')['passed'])->toBeTrue()
        ->and($withNoindex->score)->toBe($default->score);
});

it('stores a page that times out as an error without failing the audit', function (): void {
    auditPage('/about');
    auditPage('/slow');

    fakeSite('http://localhost:3000', [
        '/about' => healthyHtml('http://localhost:3000', '/about'),
        '/slow' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    $audit = runAudit();
    $slow = $audit->pages()->where('url', 'http://localhost:3000/slow')->first();

    expect($audit->status)->toBe(SeoAudit::COMPLETED)
        ->and($audit->pages_failed)->toBe(1)
        ->and($slow->error)->toContain('did not answer')
        ->and($slow->score)->toBeNull()
        ->and($audit->score)->toBe(100);
});

it('scans large sites in batch chunks', function (): void {
    config(['seo.audit.batch_threshold' => 1, 'seo.audit.chunk_size' => 1]);
    auditPage('/about');
    auditPage('/team');
    fakeSite('http://localhost:3000', [
        '/about' => healthyHtml('http://localhost:3000', '/about'),
        '/team' => healthyHtml('http://localhost:3000', '/team'),
    ]);

    $audit = runAudit();

    expect($audit->status)->toBe(SeoAudit::COMPLETED)
        ->and($audit->pages_scanned)->toBe(2)
        ->and($audit->score)->toBe(100);
});

it('queues the audit on the SEO connection with a single try and a unique lock', function (): void {
    Queue::fake();

    $audit = app(SeoAuditRunner::class)->start(null);

    Queue::assertPushed(RunSeoAudit::class, function (RunSeoAudit $job) use ($audit): bool {
        return $job->auditId === $audit->id
            && $job->connection === config('seo.queue.connection')
            && $job->queue === config('seo.queue.name')
            && $job->tries === 1
            && $job->failOnTimeout === true
            && $job->timeout === 900
            && $job->uniqueFor === 1200
            && $job->uniqueId() === 'seo-audit';
    });
});

it('lets an admin start one audit at a time and read the results', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->postJson('/api/v1/admin/seo/audits')
        ->assertAccepted()
        ->assertJsonPath('data.current.status', SeoAudit::QUEUED);

    actingAsSanctum($admin)->postJson('/api/v1/admin/seo/audits')->assertConflict();

    $this->assertDatabaseHas('activity_log', ['event' => 'seo.audit_requested']);
});

it('returns the latest completed audit, its history and page details', function (): void {
    $admin = User::factory()->admin()->create();
    auditPage('/about');
    fakeSite('http://localhost:3000', ['/about' => healthyHtml('http://localhost:3000', '/about')]);

    $audit = runAudit();
    $page = $audit->pages()->first();

    actingAsSanctum($admin)->getJson('/api/v1/admin/seo/audits/latest')
        ->assertOk()
        ->assertJsonPath('data.completed.id', $audit->id)
        ->assertJsonPath('data.completed.pages.0.url', 'http://localhost:3000/about')
        ->assertJsonPath('data.completed.summary.types.0.type', 'static');

    actingAsSanctum($admin)->getJson('/api/v1/admin/seo/audits')
        ->assertOk()
        ->assertJsonCount(1, 'data.audits');

    actingAsSanctum($admin)->getJson("/api/v1/admin/seo/audits/{$audit->id}/pages/{$page->id}")
        ->assertOk()
        ->assertJsonPath('data.page.checks.0.key', 'title_present');

    $member = User::factory()->member()->create();
    actingAsSanctum($member)->getJson('/api/v1/admin/seo/audits/latest')->assertForbidden();
    actingAsSanctum($member)->postJson('/api/v1/admin/seo/audits')->assertForbidden();
});

it('keeps only the latest audits', function (): void {
    config(['seo.audit.keep' => 3]);

    foreach (range(1, 5) as $i) {
        SeoAudit::query()->create(['status' => SeoAudit::COMPLETED]);
    }

    app(SeoAuditRunner::class)->prune();

    expect(SeoAudit::query()->count())->toBe(3)
        ->and(SeoAudit::query()->min('id'))->toBe(SeoAudit::query()->max('id') - 2);
});

it('matches robots.txt rules like Google', function (): void {
    $rules = RobotsRules::parse("User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin\nDisallow: /private\nAllow: /private/open\nDisallow: /*.pdf$\n");

    expect($rules->allows('/'))->toBeTrue()
        ->and($rules->allows('/admin/users'))->toBeFalse()
        ->and($rules->allows('/private/x'))->toBeFalse()
        ->and($rules->allows('/private/open/page'))->toBeTrue()
        ->and($rules->allows('/files/a.pdf'))->toBeFalse()
        ->and($rules->allows('/files/a.pdf?x=1'))->toBeTrue()
        ->and(RobotsRules::parse(null)->allows('/anything'))->toBeTrue();
});
