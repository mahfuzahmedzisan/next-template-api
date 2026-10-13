<?php

use App\Actions\Seo\SyncSeoMeta;
use App\Events\SeoInvalidated;
use App\Jobs\Seo\NotifyFrontendRevalidate;
use App\Jobs\Seo\ProbeSeoPath;
use App\Jobs\Seo\RegenerateSeoFiles;
use App\Models\SeoPage;
use App\Models\User;
use App\Rules\SafeSiteUrl;
use App\Services\ApplicationSettings;
use App\Services\Seo\SeoCache;
use App\Services\Seo\SeoFileGenerator;
use App\Services\Seo\SeoResolver;
use App\Services\Seo\SeoSettings;
use Database\Seeders\SeoPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Support\TestSeoable;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.name' => 'Northwind']);
    Http::preventStrayRequests();
});

function seoPage(array $attributes = []): SeoPage
{
    return SeoPage::withoutEvents(fn () => SeoPage::query()->create([
        'path' => '/about',
        'label' => 'About',
        'page_type' => 'about',
        ...$attributes,
    ]));
}

it('applies the global suffix, placeholders and the fallback chain', function (): void {
    app(SeoSettings::class)->update([
        'default_description' => 'Tools for {{site_name}} teams.',
        'default_keywords' => ['crm', 'sales'],
        'default_author' => 'Northwind Team',
    ]);
    seoPage(['meta_title' => 'About {{site_short_name}}', 'og_description' => 'Social copy']);

    $seo = app(SeoResolver::class)->forPath('/about/');

    expect($seo['title'])->toBe('About Northwind - Northwind')
        ->and($seo['description'])->toBe('Tools for Northwind teams.')
        ->and($seo['keywords'])->toBe(['crm', 'sales'])
        ->and($seo['author'])->toBe('Northwind Team')
        ->and($seo['canonical'])->toBe('http://localhost:3000/about')
        ->and($seo['open_graph']['title'])->toBe('About Northwind - Northwind')
        ->and($seo['open_graph']['description'])->toBe('Social copy')
        ->and($seo['twitter']['description'])->toBe('Social copy')
        ->and($seo['open_graph']['image'])->toBe('http://localhost:3000/og')
        ->and($seo['schema']['type'])->toBe('AboutPage')
        ->and($seo['schema']['json_ld']['@type'])->toBe('AboutPage');
});

it('supports custom and no affixes and never repeats the site name', function (): void {
    seoPage(['meta_title' => 'Pricing', 'title_affix_mode' => 'custom', 'title_prefix' => 'New:', 'title_suffix' => '| {{site_name}} {{year}}']);
    seoPage(['path' => '/plain', 'meta_title' => 'Plain', 'title_affix_mode' => 'none']);
    seoPage(['path' => '/', 'label' => 'Home', 'page_type' => 'home', 'meta_title' => 'Northwind']);

    $resolver = app(SeoResolver::class);

    expect($resolver->forPath('/about')['title'])->toBe('New: Pricing | Northwind '.now()->year)
        ->and($resolver->forPath('/plain')['title'])->toBe('Plain')
        ->and($resolver->forPath('/')['title'])->toBe('Northwind');
});

it('caches registered paths only', function (): void {
    seoPage();
    $cache = app(SeoCache::class);

    app(SeoResolver::class)->forPath('/about');
    app(SeoResolver::class)->forPath('/random-'.uniqid());

    expect(Cache::has($cache->pageKey('/about')))->toBeTrue()
        ->and(Cache::has($cache->pageKey('/random')))->toBeFalse();

    $this->getJson('/api/v1/seo/page?path=/does-not-exist')
        ->assertOk()
        ->assertJsonPath('data.seo.registered', false)
        ->assertJsonPath('data.seo.title', 'Does Not Exist - Northwind');

    expect(Cache::has($cache->pageKey('/does-not-exist')))->toBeFalse();
});

it('makes old cache keys unreachable with a version bump and re-seeds a lost version with a timestamp', function (): void {
    $cache = app(SeoCache::class);
    $before = $cache->pageKey('/about');

    $cache->bump();

    expect($cache->pageKey('/about'))->not->toBe($before);

    Cache::forget('seo:version');

    expect($cache->version())->toBeGreaterThan(1_000_000_000);
});

it('invalidates SEO when general settings change', function (): void {
    Event::fake([SeoInvalidated::class]);
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->putJson('/api/v1/admin/settings/general', [
        'name' => 'Contoso',
        'registration_enabled' => true,
    ])->assertOk();

    Event::assertDispatched(SeoInvalidated::class);
});

it('queues one file rebuild and a frontend revalidation on the SEO connection', function (): void {
    Queue::fake();
    config(['seo.queue.connection' => 'redis-seo', 'seo.queue.name' => 'seo']);

    $page = seoPage();
    $page->update(['meta_title' => 'One']);
    $page->update(['meta_title' => 'Two']);
    $page->update(['meta_title' => 'Three']);

    Queue::assertPushed(RegenerateSeoFiles::class, 1);
    Queue::assertPushed(RegenerateSeoFiles::class, fn ($job) => $job->connection === 'redis-seo' && $job->queue === 'seo');
    Queue::assertPushed(NotifyFrontendRevalidate::class, fn ($job) => $job->tags === ['seo'] && $job->connection === 'redis-seo');
});

it('does not queue SEO jobs when the transaction rolls back', function (): void {
    Queue::fake();

    try {
        DB::transaction(function (): void {
            SeoPage::query()->create(['path' => '/rolled-back', 'label' => 'Rolled back']);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNothingPushed();
});

it('never calls the frontend while saving a page, and queues the path probe', function (): void {
    Queue::fake();
    Http::fake();
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->postJson('/api/v1/admin/seo/pages', [
        'path' => 'pricing/',
        'label' => 'Pricing',
        'page_type' => 'landing',
        'meta_title' => 'Pricing plans for every team',
        'keywords' => ['pricing', 'Pricing', ' plans '],
    ])->assertCreated()
        ->assertJsonPath('data.page.path', '/pricing')
        ->assertJsonPath('data.page.fields.keywords', ['pricing', 'plans']);

    Http::assertNothingSent();
    Queue::assertPushed(ProbeSeoPath::class);
});

it('rejects admin and duplicate paths', function (): void {
    seoPage();
    $admin = User::factory()->admin()->create();

    foreach (['/about', '/admin/users', '/api', '../etc'] as $path) {
        actingAsSanctum($admin)->postJson('/api/v1/admin/seo/pages', [
            'path' => $path, 'label' => 'X', 'page_type' => 'other',
        ])->assertUnprocessable()->assertJsonValidationErrors(['path']);
    }
});

it('stores the probe status and keeps only reachable, final pages in the sitemap', function (): void {
    Http::fake([
        'localhost:3000/gone' => Http::response('', 404),
        'localhost:3000/moved' => Http::response('', 301, ['Location' => '/new-home']),
        'localhost:3000/broken' => Http::response('', 503),
        'localhost:3000/about' => Http::response('', 200),
    ]);

    foreach (['/gone', '/moved', '/broken', '/about', '/never-checked'] as $path) {
        $page = seoPage(['path' => $path, 'label' => ltrim($path, '/')]);

        if ($path !== '/never-checked') {
            (new ProbeSeoPath($page->id))->handle(app(SeoSettings::class));
        }
    }

    expect(SeoPage::query()->where('path', '/moved')->value('redirect_to'))->toBe('http://localhost:3000/new-home')
        ->and(SeoPage::query()->where('path', '/gone')->value('last_status'))->toBe(404);

    $urls = collect(app(SeoFileGenerator::class)->entries())->pluck('path')->all();

    expect($urls)->toContain('/broken', '/about', '/never-checked')
        ->not->toContain('/gone', '/moved');
});

it('leaves pages whose canonical points elsewhere out of the sitemap', function (): void {
    seoPage(['path' => '/copy', 'label' => 'Copy', 'canonical_url' => '/about']);
    seoPage();

    $urls = collect(app(SeoFileGenerator::class)->entries())->pluck('path')->all();

    expect($urls)->toBe(['/about']);
});

it('writes robots.txt with the sitemap line and the AI crawler block', function (): void {
    app(SeoSettings::class)->update(['allow_ai_crawlers' => false, 'robots_disallow' => ['/private']]);

    $robots = $this->get('/api/v1/seo/robots.txt')->assertOk()->getContent();

    expect($robots)->toContain("User-agent: *\n")
        ->toContain('Disallow: /admin')
        ->toContain('Disallow: /private')
        ->toContain('Allow: /api/storage/')
        ->toContain("User-agent: GPTBot\n")
        ->toContain('Sitemap: http://localhost:3000/sitemap.xml');
});

it('generates a missing file on its first request and leaves out lastmod on static pages', function (): void {
    seoPage();
    $generator = app(SeoFileGenerator::class);

    expect($generator->disk()->exists('seo/sitemap.xml'))->toBeFalse();

    $xml = $this->get('/api/v1/seo/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->getContent();

    expect($generator->disk()->exists('seo/sitemap.xml'))->toBeTrue()
        ->and($xml)->toContain('<loc>http://localhost:3000/about</loc>')
        ->not->toContain('<lastmod>');
});

it('returns 404 for llms.txt when it is turned off', function (): void {
    $this->get('/api/v1/seo/llms.txt')->assertOk();

    app(SeoSettings::class)->update(['llms_enabled' => false]);
    app(SeoFileGenerator::class)->generate('llms');

    $this->get('/api/v1/seo/llms.txt')->assertNotFound();
});

it('skips the frontend call when no revalidate URL is set and does not retry a 404', function (): void {
    Http::fake(['*' => Http::sequence()->push('', 404)->push('', 500)]);

    config(['services.frontend.revalidate_url' => '']);
    (new NotifyFrontendRevalidate)->handle();
    Http::assertNothingSent();

    config(['services.frontend.revalidate_url' => 'http://localhost:3000/api/internal/revalidate']);
    (new NotifyFrontendRevalidate)->handle();
    Http::assertSent(fn ($request) => $request->hasHeader('X-BFF-Secret') && $request['tags'] === ['seo']);

    expect(fn () => (new NotifyFrontendRevalidate)->handle())->toThrow(RuntimeException::class);
});

it('validates the site URL as a public https origin in production', function (): void {
    $check = fn (string $url, bool $production) => Validator::make(['url' => $url], ['url' => [new SafeSiteUrl($production)]])->passes();

    expect($check('http://localhost:3000', false))->toBeTrue()
        ->and($check('https://example.com/about', false))->toBeFalse()
        ->and($check('http://example.com', true))->toBeFalse()
        ->and($check('https://localhost', true))->toBeFalse()
        ->and($check('https://127.0.0.1', true))->toBeFalse()
        ->and($check('https://10.0.0.5', true))->toBeFalse()
        ->and($check('https://169.254.169.254', true))->toBeFalse()
        ->and($check('https://8.8.8.8', true))->toBeTrue();
});

it('never resolves the site URL to a local origin in production', function (): void {
    config(['services.frontend.urls' => ['http://localhost:3000', 'https://shop.example'], 'services.frontend.revalidate_url' => 'https://shop.example/api/internal/revalidate']);
    $settings = app(SeoSettings::class);

    expect($settings->siteUrl())->toBe('http://localhost:3000')
        ->and($settings->siteUrlWarning())->toBeNull();

    app()['env'] = 'production';

    try {
        expect($settings->siteUrl())->toBe('https://shop.example')
            ->and($settings->siteUrlWarning())->toBeNull();

        $settings->update(['site_url' => 'http://127.0.0.1:3000']);
        expect($settings->siteUrl())->toBe('https://shop.example');

        config(['services.frontend.urls' => ['http://localhost:3000'], 'app.url' => 'http://localhost']);
        expect($settings->siteUrlWarning())->toContain('local address');
    } finally {
        app()['env'] = 'testing';
    }
});

it('warns in production when the revalidate hook is not configured', function (): void {
    Log::spy();
    config(['services.frontend.revalidate_url' => '', 'services.frontend.urls' => ['https://shop.example']]);
    app()['env'] = 'production';

    try {
        (new NotifyFrontendRevalidate)->handle();
        expect(app(SeoSettings::class)->siteUrlWarning())->toContain('FRONTEND_REVALIDATE_URL');
    } finally {
        app()['env'] = 'testing';
    }

    Log::shouldHaveReceived('warning')->once();
});

it('seeds built-in pages without overwriting admin edits', function (): void {
    $this->seed(SeoPageSeeder::class);

    SeoPage::query()->where('path', '/')->first()->update(['meta_title' => 'Edited by admin']);

    $this->seed(SeoPageSeeder::class);

    expect(SeoPage::query()->where('path', '/')->value('meta_title'))->toBe('Edited by admin')
        ->and(SeoPage::query()->count())->toBe(13)
        ->and(SeoPage::query()->where('path', '/login')->value('robots_index'))->toBeFalse();
});

it('fills empty starter copy on existing pages and keeps edited fields', function (): void {
    seoPage(['path' => '/', 'label' => 'Home', 'page_type' => 'home']);
    seoPage(['path' => '/login', 'label' => 'Sign in', 'page_type' => 'auth', 'meta_title' => 'Log in', 'robots_index' => false]);
    Event::fake([SeoInvalidated::class]);

    $this->seed(SeoPageSeeder::class);

    $resolver = app(SeoResolver::class);
    $home = $resolver->forPath('/');
    $login = $resolver->forPath('/login');

    expect($home['title'])->toBe('Next Template: Next.js and Laravel Web Studio for Teams')
        ->and(mb_strlen($home['description']))->toBeGreaterThanOrEqual(140)->toBeLessThanOrEqual(160)
        ->and($home['keywords'])->toContain('web studio')
        ->and($login['title'])->toBe('Log in - Northwind')
        ->and($login['description'])->toContain('Sign in to your Northwind workspace')
        ->and($resolver->forPath('/register')['title'])->toBe('Create your account - Northwind');

    Event::assertDispatchedTimes(SeoInvalidated::class, 1);

    $this->seed(SeoPageSeeder::class);

    Event::assertDispatchedTimes(SeoInvalidated::class, 1);
});

it('seeds every public page with strict-length, unique SEO copy', function (): void {
    $this->seed(SeoPageSeeder::class);

    $resolver = app(SeoResolver::class);
    $pages = collect((new SeoPageSeeder)->publicPages())->keys()->mapWithKeys(fn (string $path) => [$path => $resolver->forPath($path)]);

    expect($pages->keys()->all())->toBe(['/', '/about', '/services', '/projects', '/blog', '/contact', '/privacy-policy', '/terms']);

    foreach ($pages as $path => $seo) {
        expect(mb_strlen($seo['title']))->toBeBetween(50, 60, $path.' title: '.$seo['title'])
            ->and(mb_strlen($seo['description']))->toBeBetween(140, 160, $path.' description: '.$seo['description'])
            ->and($seo['keywords'])->not->toBeEmpty()
            ->and($seo['sitemap']['include'])->toBeTrue();
    }

    expect($pages->pluck('title')->unique())->toHaveCount(8)
        ->and($pages->pluck('description')->unique())->toHaveCount(8)
        ->and($pages['/about']['schema']['type'])->toBe('AboutPage')
        ->and($pages['/projects']['schema']['type'])->toBe('CollectionPage')
        ->and($pages['/contact']['schema']['type'])->toBe('ContactPage')
        ->and(SeoPage::query()->where('path', '/terms')->value('page_type'))->toBe('legal');
});

it('upgrades home copy an older seeder wrote but keeps admin edits', function (): void {
    seoPage([
        'path' => '/',
        'label' => 'Home',
        'page_type' => 'home',
        'meta_title' => '{{site_name}} — Private workspace and portfolio',
        'meta_description' => 'A description the admin wrote for the home page by hand.',
    ]);

    $this->seed(SeoPageSeeder::class);

    $home = SeoPage::query()->where('path', '/')->first();

    expect($home->meta_title)->toBe('Next Template: Next.js and Laravel Web Studio for Teams')
        ->and($home->title_affix_mode)->toBe('none')
        ->and($home->meta_description)->toBe('A description the admin wrote for the home page by hand.');
});

it('replaces the personal copy and author an older seeder wrote', function (): void {
    seoPage([
        'path' => '/about',
        'label' => 'About',
        'page_type' => 'about',
        'meta_title' => 'About Mahfuz Ahmed, Full-Stack Web Developer in Dhaka',
        'meta_description' => 'Meet Mahfuz Ahmed, a full-stack developer with ten years of Next.js, React and Laravel experience who turns messy processes into simple, reliable web apps.',
        'keywords' => ['about Mahfuz Ahmed', 'full-stack developer Dhaka', 'Next.js', 'Laravel', 'freelance web developer'],
    ]);
    seoPage([
        'path' => '/contact',
        'label' => 'Contact',
        'page_type' => 'contact',
        'meta_title' => 'Contact Mahfuz Ahmed to Discuss Your Next Web Project',
        'keywords' => ['contact', 'reach the team'],
    ]);
    app(SeoSettings::class)->update(['default_author' => 'Mahfuz Ahmed']);

    $this->seed(SeoPageSeeder::class);

    $about = SeoPage::query()->where('path', '/about')->first();
    $contact = SeoPage::query()->where('path', '/contact')->first();

    expect($about->meta_title)->toBe('About Next Template, a Next.js and Laravel Web Studio')
        ->and($about->meta_description)->toStartWith('Meet Next Template')
        ->and($about->keywords)->toContain('about Next Template')
        ->and($contact->meta_title)->toBe('Contact Next Template to Discuss Your Next Web Project')
        ->and($contact->keywords)->toBe(['contact', 'reach the team'])
        ->and(app(SeoSettings::class)->all()['default_author'])->toBe('Next Template');
});

it('keeps a default author the admin chose', function (): void {
    app(SeoSettings::class)->update(['default_author' => 'Jane Editor']);

    $this->seed(SeoPageSeeder::class);

    expect(app(SeoSettings::class)->all()['default_author'])->toBe('Jane Editor');
});

it('keeps built-in pages from being removed or moved', function (): void {
    $this->seed(SeoPageSeeder::class);
    $admin = User::factory()->admin()->create();
    $home = SeoPage::query()->where('path', '/')->first();

    actingAsSanctum($admin)->deleteJson("/api/v1/admin/seo/pages/{$home->id}")->assertUnprocessable();
    actingAsSanctum($admin)->putJson("/api/v1/admin/seo/pages/{$home->id}", ['path' => '/home'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['path']);
});

it('refuses SEO admin endpoints to members', function (): void {
    $member = User::factory()->member()->create();

    actingAsSanctum($member)->getJson('/api/v1/admin/seo')->assertForbidden();
    actingAsSanctum($member)->putJson('/api/v1/admin/seo/settings', [])->assertForbidden();
    actingAsSanctum($member)->postJson('/api/v1/admin/seo/files/all/regenerate')->assertForbidden();
});

it('lets an admin save SEO settings, regenerate and download files', function (): void {
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->putJson('/api/v1/admin/seo/settings', [
        'title_suffix' => '| {{site_name}}',
        'twitter_site' => 'northwind',
        'default_keywords' => ['crm'],
    ])->assertOk()
        ->assertJsonPath('data.settings.title_suffix', '| {{site_name}}')
        ->assertJsonPath('data.settings.twitter_site', 'northwind');

    $this->assertDatabaseHas('activity_log', ['event' => 'seo.settings_updated']);

    actingAsSanctum($admin)->postJson('/api/v1/admin/seo/files/all/regenerate')
        ->assertOk()
        ->assertJsonPath('data.files.0.file', 'sitemap');

    actingAsSanctum($admin)->get('/api/v1/admin/seo/files/robots/download')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="robots.txt"');
});

it('resolves detail-page SEO from the record, its template and its own fields', function (): void {
    TestSeoable::register();
    app(SeoSettings::class)->putTypeTemplate('test_seoable', ['title_template' => '{{page_title}} | Blog', 'sitemap_priority' => 0.6]);

    $post = TestSeoable::query()->create(['title' => 'Shipping faster', 'slug' => 'shipping-faster', 'excerpt' => 'How we ship.']);
    TestSeoable::query()->create(['title' => 'Draft', 'slug' => 'draft', 'published' => false]);

    $seo = app(SeoResolver::class)->forModel($post);

    expect($seo['title'])->toBe('Shipping faster | Blog')
        ->and($seo['description'])->toBe('How we ship.')
        ->and($seo['author'])->toBe('Jane Writer')
        ->and($seo['open_graph']['type'])->toBe('article')
        ->and($seo['schema']['json_ld']['@type'])->toBe('Article')
        ->and($seo['sitemap']['priority'])->toBe(0.6)
        ->and($seo['sitemap']['lastmod'])->not->toBeNull();

    app(SyncSeoMeta::class)->handle($post, ['meta_title' => 'Ship faster', 'robots_index' => true]);

    $this->assertDatabaseHas('seo_meta', ['seoable_type' => 'test_seoable', 'seoable_id' => $post->id, 'meta_title' => 'Ship faster']);
    expect(app(SeoResolver::class)->forModel($post->fresh())['title'])->toBe('Ship faster | Blog');

    $entries = collect(app(SeoFileGenerator::class)->entries())->pluck('path')->all();
    expect($entries)->toBe(['/blogs/shipping-faster']);

    app(SeoFileGenerator::class)->generate('sitemap');
    expect(app(SeoFileGenerator::class)->read('sitemap.xml'))->toContain('<lastmod>');
});

it('returns the default suffix until an admin clears it', function (): void {
    expect(app(SeoSettings::class)->all()['title_suffix'])->toBe('- {{site_name}}');

    app(SeoSettings::class)->update(['title_suffix' => '']);
    app(ApplicationSettings::class)->flush();

    expect(app(SeoSettings::class)->all()['title_suffix'])->toBe('');
});
