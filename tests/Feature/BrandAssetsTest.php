<?php

use App\Models\SeoPage;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\Brand\FaviconSet;
use App\Services\Seo\SeoResolver;
use App\Services\Seo\SocialImage;
use App\Support\ImageBytes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Storage::fake('public');
});

function brandPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 120, 200));

    return ImageBytes::png($image);
}

function imageSize(string $bytes): array
{
    $info = getimagesizefromstring($bytes);

    return [$info[0], $info[1], $info['mime']];
}

it('generates a real favicon.ico and the PNG icons when a favicon is uploaded', function (): void {
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->post('/api/v1/admin/settings/favicon', [
        'file' => UploadedFile::fake()->createWithContent('favicon.png', brandPng(300, 200)),
    ])->assertOk();

    $set = app(ApplicationSettings::class)->get('general', 'favicon_set');
    $disk = Storage::disk('public');

    expect($set)->toHaveKeys(['favicon_ico', 'icon_192', 'icon_512', 'apple_touch_icon']);

    $entries = FaviconSet::icoEntries($disk->get($set['favicon_ico']));
    expect(array_column($entries, 'width'))->toBe([16, 32, 48])
        ->and(array_column($entries, 'png'))->each->toBeTrue()
        ->and(imageSize($disk->get($set['icon_192'])))->toBe([192, 192, 'image/png'])
        ->and(imageSize($disk->get($set['icon_512'])))->toBe([512, 512, 'image/png'])
        ->and(imageSize($disk->get($set['apple_touch_icon'])))->toBe([180, 180, 'image/png']);

    $this->getJson('/api/v1/settings/public')
        ->assertOk()
        ->assertJsonPath('data.settings.favicon_set.favicon_ico', fn (string $url) => str_ends_with($url, '/favicon.ico'));

    $disk->assertExists(app(ApplicationSettings::class)->get('general', 'favicon_path'));
});

it('keeps an uploaded ICO as the favicon and replaces the old set on a new upload', function (): void {
    $admin = User::factory()->admin()->create();
    $ico = FaviconSet::ico(ImageBytes::decode(brandPng(64, 64)));

    actingAsSanctum($admin)->post('/api/v1/admin/settings/favicon', [
        'file' => UploadedFile::fake()->createWithContent('favicon.ico', $ico),
    ])->assertOk();

    $first = app(ApplicationSettings::class)->get('general', 'favicon_set');
    expect(Storage::disk('public')->get($first['favicon_ico']))->toBe($ico)
        ->and($first)->toHaveKey('icon_512');

    actingAsSanctum($admin)->post('/api/v1/admin/settings/favicon', [
        'file' => UploadedFile::fake()->createWithContent('favicon.png', brandPng(96, 96)),
    ])->assertOk();

    $second = app(ApplicationSettings::class)->get('general', 'favicon_set');
    expect($second['favicon_ico'])->not->toBe($first['favicon_ico']);
    Storage::disk('public')->assertMissing($first['favicon_ico']);
});

it('leaves SVG favicons to the frontend and clears the set when the favicon is removed', function (): void {
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->post('/api/v1/admin/settings/favicon', [
        'file' => UploadedFile::fake()->createWithContent('favicon.png', brandPng(64, 64)),
    ])->assertOk();
    $set = app(ApplicationSettings::class)->get('general', 'favicon_set');

    actingAsSanctum($admin)->post('/api/v1/admin/settings/favicon', [
        'file' => UploadedFile::fake()->createWithContent('favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>'),
    ])->assertOk();

    expect(app(ApplicationSettings::class)->get('general', 'favicon_set'))->toBeNull();
    Storage::disk('public')->assertMissing($set['favicon_ico']);

    $this->getJson('/api/v1/settings/public')->assertJsonPath('data.settings.favicon_set', null);
});

it('crops share images to a 1200x628 variant and reports its size', function (): void {
    Storage::disk('public')->put('seo/cover.png', brandPng(1600, 1200));
    SeoPage::query()->create(['path' => '/about', 'label' => 'About', 'page_type' => 'about', 'og_image' => 'seo/cover.png']);

    $seo = app(SeoResolver::class)->forPath('/about');
    $variant = app(SocialImage::class)->variant('seo/cover.png');

    expect($seo['open_graph']['image'])->toEndWith('/api/storage/'.$variant['path'])
        ->and($seo['open_graph']['image_width'])->toBe(1200)
        ->and($seo['open_graph']['image_height'])->toBe(628)
        ->and($seo['open_graph']['image_type'])->toBe('image/jpeg')
        ->and($seo['twitter']['image'])->toBe($seo['open_graph']['image'])
        ->and(imageSize(Storage::disk('public')->get($variant['path'])))->toBe([1200, 628, 'image/jpeg']);
});

it('falls back to the generated card for SVG share images', function (): void {
    SeoPage::query()->create(['path' => '/about', 'label' => 'About', 'page_type' => 'about', 'og_image' => 'seo/cover.svg']);

    $seo = app(SeoResolver::class)->forPath('/about');

    expect($seo['open_graph']['image'])->toEndWith('/og')
        ->and($seo['open_graph']['image_width'])->toBe(1200)
        ->and($seo['open_graph']['image_height'])->toBe(628)
        ->and($seo['open_graph']['image_type'])->toBe('image/png');
});

it('keeps an unreadable share image without claiming a size', function (): void {
    Http::fake(['cdn.example/*' => Http::response('nope', 500)]);
    SeoPage::query()->create(['path' => '/about', 'label' => 'About', 'page_type' => 'about', 'og_image' => 'https://cdn.example/cover.png']);

    $seo = app(SeoResolver::class)->forPath('/about');

    expect($seo['open_graph']['image'])->toBe('https://cdn.example/cover.png')
        ->and($seo['open_graph']['image_width'])->toBeNull();
});
