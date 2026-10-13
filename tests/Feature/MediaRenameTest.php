<?php

use App\Enums\MediaKind;
use App\Enums\VideoStatus;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function storedImage(string $slug, string $fileName): MediaItem
{
    $item = MediaItem::factory()->create([
        'title' => 'Logo',
        'slug' => $slug,
        'kind' => MediaKind::Image,
        'extension' => 'svg',
        'status' => VideoStatus::Ready,
    ]);
    $source = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($source, '<svg xmlns="http://www.w3.org/2000/svg"/>');
    $item->addMedia($source)->usingFileName($fileName)->toMediaCollection('source');

    return $item;
}

it('renames the stored file to the slug on save', function (): void {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $item = storedImage('logo', '01-logo-mark.svg');
    $mediaId = $item->getFirstMedia('source')->id;

    $response = actingAsSanctum($admin)->patchJson("/api/v1/admin/media/{$item->id}", [
        'title' => 'Site logo',
        'slug' => 'site-logo',
    ]);

    $response->assertOk();
    expect($response->json('data.media.url'))->toEndWith("/{$mediaId}/site-logo.svg");
    Storage::disk('public')->assertExists("{$mediaId}/site-logo.svg");
    Storage::disk('public')->assertMissing("{$mediaId}/01-logo-mark.svg");
});

it('keeps the file name when it already matches the slug', function (): void {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $item = storedImage('logo', 'logo.svg');
    $mediaId = $item->getFirstMedia('source')->id;

    actingAsSanctum($admin)->patchJson("/api/v1/admin/media/{$item->id}", [
        'title' => 'Logo',
        'slug' => 'logo',
        'alt' => 'Site logo',
    ])->assertOk();

    Storage::disk('public')->assertExists("{$mediaId}/logo.svg");
});

it('resolves a media folder to its current file for old links', function (): void {
    Storage::fake('public');
    $item = storedImage('logo', 'logo.svg');
    $mediaId = $item->getFirstMedia('source')->id;

    $this->getJson("/api/v1/media/files/{$mediaId}")
        ->assertOk()
        ->assertJsonPath('data.path', "{$mediaId}/logo.svg");

    $this->getJson('/api/v1/media/files/999999')->assertNotFound();
});

it('suggests the current slug again when the item already owns it', function (): void {
    $admin = User::factory()->admin()->create();
    $item = MediaItem::factory()->create(['title' => 'Logo', 'slug' => 'logo']);

    actingAsSanctum($admin)->getJson('/api/v1/admin/media/slug-preview?title=Logo')
        ->assertJsonPath('data.slugs.0', 'logo-1');

    actingAsSanctum($admin)->getJson("/api/v1/admin/media/slug-preview?title=Logo&except={$item->id}")
        ->assertJsonPath('data.slugs.0', 'logo');
});
