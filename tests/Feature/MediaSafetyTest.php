<?php

use App\Enums\MediaKind;
use App\Enums\VideoStatus;
use App\Jobs\AdoptFinishedUpload;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\AllowedMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;

uses(RefreshDatabase::class);

function finishUpload(string $filename, string $mime, string $bytes, string $kindTitle = 'Sample'): array
{
    $admin = User::factory()->admin()->create();
    $created = actingAsSanctum($admin)->postJson('/api/v1/admin/media', [
        'title' => $kindTitle,
        'filename' => $filename,
        'size' => strlen($bytes),
        'mime' => $mime,
    ]);

    if ($created->status() !== 201) {
        return ['response' => $created, 'id' => null];
    }

    $id = (int) $created->json('data.media_id');
    $token = (string) $created->json('data.upload_token');
    $path = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($path, $bytes);

    $payload = [
        'Type' => 'post-finish',
        'Event' => [
            'Upload' => [
                'ID' => 'tus-scan',
                'Size' => strlen($bytes),
                'SizeIsDeferred' => false,
                'Offset' => strlen($bytes),
                'MetaData' => [
                    'filename' => $filename,
                    'filetype' => $mime,
                    'media_id' => (string) $id,
                ],
                'Storage' => ['Path' => $path],
            ],
            'HTTPRequest' => [
                'Header' => [
                    'Authorization' => ['Bearer '.$token],
                ],
            ],
        ],
    ];

    $finished = test()->withHeaders(['X-Tus-Hook-Secret' => 'test-tus-secret'])
        ->postJson('/internal/tus/post-finish', $payload);

    return ['response' => $finished, 'id' => $id, 'create' => $created];
}

it('rejects a php payload renamed as a jpeg', function (): void {
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->postJson('/api/v1/admin/media', [
        'title' => 'Photo',
        'filename' => 'shell.php.jpg',
        'size' => 32,
        'mime' => 'image/jpeg',
    ])->assertUnprocessable();
});

it('rejects an archive before it is stored', function (): void {
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->postJson('/api/v1/admin/media', [
        'title' => 'Bundle',
        'filename' => 'crack.zip',
        'size' => 32,
        'mime' => 'application/zip',
    ])->assertUnprocessable();
});

it('rejects a jpeg whose bytes are php', function (): void {
    $result = finishUpload('photo.jpg', 'image/jpeg', "<?php echo 'no';");

    expect($result['response']->status())->toBe(422);
    expect(MediaItem::query()->findOrFail($result['id'])->status)->toBe(VideoStatus::Failed);
    expect(MediaItem::query()->findOrFail($result['id'])->getFirstMedia('source'))->toBeNull();
});

it('numbers a shared name and skips slugs that already exist', function (): void {
    $admin = User::factory()->admin()->create();
    MediaItem::factory()->create(['title' => 'New image', 'slug' => 'new-image']);

    actingAsSanctum($admin)
        ->getJson('/api/v1/admin/media/slug-preview?title=New%20image&count=2')
        ->assertOk()
        ->assertJsonPath('data.slugs.0', 'new-image-1')
        ->assertJsonPath('data.slugs.1', 'new-image-2');
});

it('finds media by name when the search index has no hits', function (): void {
    config(['scout.driver' => 'typesense']);
    app(EngineManager::class)->forgetDrivers()->extend('typesense', fn () => new NullEngine);

    $admin = User::factory()->admin()->create();
    MediaItem::factory()->create(['title' => 'bangladesh-map', 'slug' => 'bangladesh-map', 'kind' => MediaKind::Image]);
    MediaItem::factory()->create(['title' => 'Share Title', 'slug' => 'share-title', 'kind' => MediaKind::Image]);

    actingAsSanctum($admin)
        ->getJson('/api/v1/admin/media?filter[q]=map&filter[kind]=image')
        ->assertOk()
        ->assertJsonCount(1, 'data.media')
        ->assertJsonPath('data.media.0.slug', 'bangladesh-map');
});

it('accepts an svg and stores it without script', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle r="4"/></svg>';
    $result = finishUpload('mark.svg', 'image/svg+xml', $svg, 'Mark');

    expect($result['response']->status())->toBe(200);
    $item = MediaItem::query()->findOrFail($result['id']);
    expect($item->status)->toBe(VideoStatus::Ready);
    $path = $item->getFirstMediaPath('source');
    expect($path)->not->toBeNull();
    $stored = strtolower((string) file_get_contents((string) $path));
    expect($stored)->not->toContain('<script')->toContain('circle');
});

it('stores a raster image as webp', function (): void {
    if (! function_exists('imagewebp')) {
        test()->markTestSkipped('GD WebP is not available.');
    }

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $result = finishUpload('photo.png', 'image/png', $png, 'Photo');

    expect($result['response']->status())->toBe(200);
    $item = MediaItem::query()->findOrFail($result['id']);
    expect($item->extension)->toBe('webp');
    expect($item->mime)->toBe('image/webp');
    $path = (string) $item->getFirstMediaPath('source');
    expect($path)->toEndWith('.webp');
    $header = (string) file_get_contents($path, false, null, 0, 12);
    expect($header)->toStartWith('RIFF')->toContain('WEBP');
});

it('rejects a file whose magic bytes do not match the extension', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $result = finishUpload('photo.jpg', 'image/jpeg', $png);

    expect($result['response']->status())->toBe(422);
    expect(MediaItem::query()->findOrFail($result['id'])->getFirstMedia('source'))->toBeNull();
});

it('lists media with a flat number of queries', function (): void {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();

    $seed = function (int $count): void {
        foreach (range(1, $count) as $index) {
            $item = MediaItem::factory()->create([
                'kind' => MediaKind::Image,
                'extension' => 'png',
                'status' => VideoStatus::Ready,
            ]);
            $source = tempnam(sys_get_temp_dir(), 'img');
            file_put_contents($source, 'bytes-'.$index);
            $item->addMedia($source)->usingFileName("image-{$index}.png")->toMediaCollection('source');
        }
    };

    $count = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        actingAsSanctum($admin)->getJson('/api/v1/admin/media?per_page=50')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $seed(1);
    $one = $count();
    $seed(10);
    $eleven = $count();

    expect($eleven)->toBe($one);
});

it('queues adoption instead of importing an unfinished upload during a read', function (): void {
    Bus::fake([AdoptFinishedUpload::class]);
    $admin = User::factory()->admin()->create();
    $item = MediaItem::factory()->create(['status' => VideoStatus::AwaitingUpload]);

    actingAsSanctum($admin)->getJson('/api/v1/admin/media')->assertOk();
    actingAsSanctum($admin)->getJson("/api/v1/admin/media/{$item->id}")->assertOk();

    Bus::assertDispatched(AdoptFinishedUpload::class, fn (AdoptFinishedUpload $job): bool => $job->mediaId === $item->id);
});

it('caps image uploads well below the video limit', function (): void {
    config([
        'media-hls.max_bytes' => 8 * 1024 * 1024 * 1024,
        'media-hls.max_bytes_by_kind.image' => 50 * 1024 * 1024,
    ]);

    expect(AllowedMedia::rejectionReason('photo.png', 'image/png', 60 * 1024 * 1024))
        ->toBe('This file is larger than the upload limit.')
        ->and(AllowedMedia::rejectionReason('clip.mp4', 'video/mp4', 60 * 1024 * 1024))
        ->toBeNull();
});

it('answers a duplicate slug race with 422 instead of a server error', function (): void {
    $admin = User::factory()->admin()->create();
    MediaItem::factory()->create(['slug' => 'taken-slug']);

    Route::middleware('api')->post('/api/v1/__test/unique', function () {
        MediaItem::factory()->create(['slug' => 'taken-slug']);
    });

    actingAsSanctum($admin)->postJson('/api/v1/__test/unique')->assertUnprocessable();
});
