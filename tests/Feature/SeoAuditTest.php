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

it('scores a healthy production page at 100 and ranks real issues', function (): void {
    app(SeoSettings::class)->update(['site_url' => 'https://northwind.example']);
    auditPage('/about');
    auditPage('/team');

    fakeSite('https://northwind.example', [
        '/about' => healthyHtml('https://northwind.example', '/about'),
        '/team' => healthyHtml('https://northwind.example', '/team', [
            '<meta name="description"' => '<meta name="summary"',
            '<h1>Team Northwind</h1>' => '<h1>Team Northwind</h1><h1>Two</h1>',
        ]),
    ], ['Content-Encoding' => 'gzip', 'Strict-Transport-Security' => 'max-age=31536000']);

    $audit = runAudit();
    $pages = $audit->pages()->get()->keyBy('url');

    expect($audit->status)->toBe(SeoAudit::COMPLETED)
        ->and($audit->environment)->toBe('production')
        ->and($pages['https://northwind.example/about']->score)->toBe(100)
        ->and($pages['https://northwind.example/team']->score)->toBeLessThan(100)
        ->and(sectionScore($audit, 'pages'))->toBe((int) round(($pages['https://northwind.example/about']->score + $pages['https://northwind.example/team']->score) / 2))
        ->and(sectionScore($audit, 'site'))->toBe(100)
        ->and(sectionScore($audit, 'sitemap'))->toBe(100)
        ->and(sectionScore($audit, 'robots'))->toBe(100)
        ->and($audit->score)->toBe((int) round((sectionScore($audit, 'pages') * 70 + 3000) / 100));

    $issues = collect($audit->summary['issues'])->keyBy('key');

    expect($issues->keys()->all())->toContain('description_present', 'h1_single')
        ->and($issues['description_present']['pages'])->toBe(1)
        ->and($audit->summary['issues'][0]['priority'])->toBe('high')
        ->and(collect($audit->summary['categories'])->pluck('key')->all())->toContain('meta', 'content', 'configuration', 'performance');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://northwind.example/about'
        && str_contains($request->header('Accept-Encoding')[0] ?? '', 'gzip'));
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

    $gets = Http::recorded(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'http://localhost:3000/about'
        && str_contains($request->header('User-Agent')[0] ?? '', 'MahfuzSeoScanner'));

    // Warm-up, the sitemap's URL check, then the page scan.
    expect($gets)->toHaveCount(3)
        ->and(Http::recorded()->first()[0]->url())->toBe('http://localhost:3000/about');
});

it('runs content length on every page type and skips configured checks per page type', function (): void {
    $auth = SeoCheckCatalog::forPage('page', 'auth', true);
    $about = SeoCheckCatalog::forPage('page', 'about', true);
    $post = SeoCheckCatalog::forPage('model', 'post', true);

    expect($about)->toContain('content_length', 'headings', 'head_metadata', 'og_image_meta', 'breadcrumbs')
        ->not->toContain('title_unique', 'description_unique')
        ->and($post)->toContain('content_length')
        ->and($auth)->not->toContain('headings', 'internal_links', 'og_image', 'content_length')
        ->and(SeoCheckCatalog::minWords('home'))->toBe(800)
        ->and(SeoCheckCatalog::minWords('unknown'))->toBe(300)
        ->and(SeoCheckCatalog::get('title_length')['label'])->toBe('Title is 50–60 characters')
        ->and(SeoCheckCatalog::forPage('page', 'about', false))->toBe(SeoCheckCatalog::NOINDEX_CHECKS);

    foreach (['keywords_meta', 'author_meta'] as $key) {
        expect(SeoCheckCatalog::get($key))->toMatchArray(['weight' => 1, 'informational' => true]);
    }
});

it('fails a 1200x630 social image and size tags that do not match the image', function (): void {
    auditPage('/about');
    fakeSite('http://localhost:3000', ['/about' => healthyHtml('http://localhost:3000', '/about')], ogImage: [1200, 630]);

    $audit = runAudit();
    $image = auditCheck($audit, 'http://localhost:3000/about', 'og_image');
    $meta = auditCheck($audit, 'http://localhost:3000/about', 'og_image_meta');

    expect($image['passed'])->toBeFalse()
        ->and($image['actual'])->toContain('1200x630')
        ->and($meta['passed'])->toBeFalse()
        ->and($meta['actual'])->toBe('tags 1200x628, image 1200x630');
});

it('flags titles and descriptions shared by several pages', function (): void {
    auditPage('/about');
    auditPage('/team');
    $origin = 'http://localhost:3000';
    fakeSite($origin, [
        '/about' => healthyHtml($origin, '/about'),
        '/team' => healthyHtml($origin, '/team', ['<title>Team page' => '<title>About page']),
    ]);

    $audit = runAudit();
    $title = auditCheck($audit, $origin.'/team', 'title_unique');

    expect($title['passed'])->toBeFalse()
        ->and($title['actual'])->toBe('same as About')
        ->and(auditCheck($audit, $origin.'/team', 'description_unique')['passed'])->toBeTrue()
        ->and($audit->pages()->where('url', $origin.'/team')->first()->score)->toBeLessThan(100)
        ->and(collect($audit->summary['issues'])->pluck('key'))->toContain('title_unique');
});

it('catches metadata that Next.js streams into the body for browsers', function (): void {
    auditPage('/about');
    $origin = 'http://localhost:3000';
    $html = healthyHtml($origin, '/about');
    $streamed = strtr(preg_replace('#<title>.*?</title>#', '', $html), ['<body>' => '<body><title>About</title>']);

    fakeSite($origin, ['/about' => fn (Request $request) => Http::response(
        str_contains($request->header('User-Agent')[0] ?? '', 'Chrome') ? $streamed : $html,
        200,
        ['Content-Type' => 'text/html'],
    )]);

    $check = auditCheck(runAudit(), $origin.'/about', 'head_metadata');

    expect($check['passed'])->toBeFalse()
        ->and($check['actual'])->toContain('title')
        ->and($check['message'])->toContain('streamed into <body>');
});

it('requires the schema properties each type needs and a clean heading outline', function (): void {
    auditPage('/about');
    $origin = 'http://localhost:3000';
    fakeSite($origin, ['/about' => healthyHtml($origin, '/about', [
        '"@type":"AboutPage"' => '"@type":"Article"',
        '<h3>Where it started</h3>' => '<h3>Where it started</h3><h5>Too deep</h5>',
    ])]);

    $audit = runAudit();
    $schema = auditCheck($audit, $origin.'/about', 'structured_data');
    $headings = auditCheck($audit, $origin.'/about', 'headings');

    expect($schema['passed'])->toBeFalse()
        ->and($schema['actual'])->toContain('Article missing headline, image, datePublished, author')
        ->and($headings['passed'])->toBeFalse()
        ->and($headings['actual'])->toBe('H3 followed by H5');
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
    SeoPage::query()->whereNotIn('page_type', ['home', 'auth'])->delete();
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
        ->and(sectionScore($audit, 'pages'))->toBe(100)
        ->and(sectionScore($audit, 'sitemap'))->toBeLessThan(100);
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

it('scores every section at 100 for a healthy development site', function (): void {
    auditPage('/about');
    fakeSite('http://localhost:3000', ['/about' => healthyHtml('http://localhost:3000', '/about')]);

    $audit = runAudit();

    expect($audit->score)->toBe(100)
        ->and(collect($audit->summary['sections'])->pluck('score', 'key')->all())->toBe(['pages' => 100, 'site' => 100, 'sitemap' => 100, 'robots' => 100])
        ->and($audit->site_checks['checks'])->not->toBeEmpty()
        ->and($audit->sitemap_report['details']['urls'])->toBe(1);
});

it('runs only robots.txt, or robots.txt and the sitemap, for a scoped audit', function (): void {
    auditPage('/about');
    fakeSite('http://localhost:3000', ['/about' => healthyHtml('http://localhost:3000', '/about')], overrides: [
        '/robots.txt' => Http::response("User-agent: *\nDisallow: /\nNoindex: /x\n", 200, ['Content-Type' => 'text/plain']),
    ]);

    $robots = runAudit(scope: 'robots');

    expect($robots->scope)->toBe('robots')
        ->and($robots->pages()->count())->toBe(0)
        ->and($robots->sitemap_report)->toBeNull()
        ->and($robots->site_checks)->toBeNull()
        ->and($robots->score)->toBe($robots->robots_report['score'])
        ->and(collect($robots->robots_report['checks'])->where('passed', false)->pluck('key')->all())
        ->toContain('robots_syntax', 'robots_not_blocking_site', 'robots_pages_allowed', 'robots_sitemap_declared');

    $sitemap = runAudit(scope: 'sitemap');

    expect($sitemap->sitemap_report)->not->toBeNull()
        ->and($sitemap->site_checks)->toBeNull()
        ->and(collect($sitemap->summary['sections'])->pluck('score', 'key')->only(['pages', 'site'])->filter()->all())->toBe([]);
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
    actingAsSanctum($admin)->postJson('/api/v1/admin/seo/audits', ['scope' => 'everything'])->assertUnprocessable();

    SeoAudit::query()->update(['status' => SeoAudit::COMPLETED]);

    actingAsSanctum($admin)->postJson('/api/v1/admin/seo/audits', ['scope' => 'sitemap'])
        ->assertAccepted()
        ->assertJsonPath('data.current.scope', 'sitemap');

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
        ->assertJsonPath('data.completed.summary.types.0.type', 'static')
        ->assertJsonPath('data.limits', ['ttfb_ms' => 800, 'max_html_kb' => 512]);

    actingAsSanctum($admin)->getJson('/api/v1/admin/seo/audits')
        ->assertOk()
        ->assertJsonCount(1, 'data.audits')
        ->assertJsonPath('data.audits.0.sections', [
            ['key' => 'pages', 'score' => 100],
            ['key' => 'site', 'score' => 100],
            ['key' => 'sitemap', 'score' => 100],
            ['key' => 'robots', 'score' => 100],
        ]);

    $robots = runAudit(scope: 'robots');

    actingAsSanctum($admin)->getJson('/api/v1/admin/seo/audits')
        ->assertJsonPath('data.audits.0.id', $robots->id)
        ->assertJsonPath('data.audits.0.sections.0', ['key' => 'pages', 'score' => null])
        ->assertJsonPath('data.audits.0.sections.3', ['key' => 'robots', 'score' => $robots->robots_report['score']]);

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
