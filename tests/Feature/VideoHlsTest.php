<?php

use App\Contracts\EncodesHls;
use App\Enums\MediaKind;
use App\Enums\VideoStatus;
use App\Jobs\GenerateHlsJob;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\HlsEncodeResult;
use App\Services\HlsTranscodeService;
use App\Services\StoreFinishedUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function mediaPayload(string $token, int $mediaId, array $overrides = []): array
{
    $upload = array_merge([
        'ID' => 'tus-upload-1',
        'Size' => 32,
        'SizeIsDeferred' => false,
        'Offset' => 0,
        'MetaData' => [
            'filename' => 'clip.mp4',
            'filetype' => 'video/mp4',
            'media_id' => (string) $mediaId,
        ],
        'Storage' => [
            'Path' => '',
        ],
    ], $overrides);

    return [
        'Type' => 'pre-create',
        'Event' => [
            'Upload' => $upload,
            'HTTPRequest' => [
                'Header' => [
                    'Authorization' => ['Bearer '.$token],
                ],
            ],
        ],
    ];
}

function mp4Bytes(): string
{
    return hex2bin('000000186674797069736f6d0000000069736f6d').'video';
}

function createMedia(array $extra = []): array
{
    $admin = User::factory()->admin()->create();

    $created = actingAsSanctum($admin)->postJson('/api/v1/admin/media', array_merge([
        'title' => 'Clip',
        'filename' => 'clip.mp4',
        'size' => strlen(mp4Bytes()),
        'mime' => 'video/mp4',
    ], $extra))->assertCreated();

    return [
        'admin' => $admin,
        'id' => (int) $created->json('data.media_id'),
        'token' => (string) $created->json('data.upload_token'),
    ];
}

it('creates a media item and an upload token without accepting a file body', function (): void {
    $admin = User::factory()->admin()->create();

    $response = actingAsSanctum($admin)->postJson('/api/v1/admin/media', [
        'title' => 'Launch film',
        'filename' => 'launch.mp4',
        'size' => 2048,
        'mime' => 'video/mp4',
        'alt' => 'Launch',
        'keywords' => ['launch', 'film'],
    ])->assertCreated()
        ->assertJsonPath('data.media.status', 'awaiting_upload')
        ->assertJsonPath('data.media.kind', 'video')
        ->assertJsonPath('data.media.stream_url', null)
        ->assertJsonStructure([
            'data' => ['media_id', 'tus_endpoint', 'upload_token', 'media'],
        ]);

    expect($response->json('data.upload_token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.tus_endpoint'))->toEndWith('/tus/');
    expect($response->json('data.tus_endpoint'))->not->toStartWith('https://');
    expect($response->json('data.media'))->not->toHaveKey('source_url');

    $this->assertDatabaseHas('media_items', [
        'id' => $response->json('data.media_id'),
        'status' => 'awaiting_upload',
        'kind' => 'video',
        'uploaded_by' => $admin->id,
    ]);
});

it('returns an https tus endpoint when the proxy terminated tls', function (): void {
    $admin = User::factory()->admin()->create();

    $endpoint = actingAsSanctum($admin)
        ->postJson('/api/v1/admin/media', [
            'title' => 'Launch film',
            'filename' => 'launch.mp4',
            'size' => 2048,
            'mime' => 'video/mp4',
        ], [
            'X-Forwarded-Proto' => 'https',
        ])
        ->assertCreated()
        ->json('data.tus_endpoint');

    expect($endpoint)->toStartWith('https://')->toEndWith('/tus/');
});

it('rejects a multipart file on media create', function (): void {
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->post('/api/v1/admin/media', [
        'title' => 'Launch film',
        'filename' => 'launch.mp4',
        'size' => 2048,
        'mime' => 'video/mp4',
        'file' => UploadedFile::fake()->create('launch.mp4', 20, 'video/mp4'),
    ])->assertUnprocessable();
});

it('forbids a regular user and a missing bff secret from creating media', function (): void {
    $member = User::factory()->member()->create();

    actingAsSanctum($member)->postJson('/api/v1/admin/media', [
        'title' => 'Nope',
        'filename' => 'nope.mp4',
        'size' => 10,
        'mime' => 'video/mp4',
    ])->assertForbidden();

    $this->defaultHeaders = ['Accept' => 'application/json'];

    $this->postJson('/api/v1/admin/media', [
        'title' => 'Nope',
        'filename' => 'nope.mp4',
        'size' => 10,
        'mime' => 'video/mp4',
    ])->assertForbidden()
        ->assertJsonPath('message', 'This API only accepts requests from the trusted application.');
});

it('rejects a pre-create hook with a bad token or a disallowed type', function (): void {
    $created = createMedia();

    $this->withHeaders(['X-Tus-Hook-Secret' => 'test-tus-secret'])
        ->postJson('/internal/tus/pre-create', mediaPayload('not-the-token', $created['id']))
        ->assertForbidden();

    $this->withHeaders(['X-Tus-Hook-Secret' => 'test-tus-secret'])
        ->postJson('/internal/tus/pre-create', mediaPayload($created['token'], $created['id'], [
            'MetaData' => [
                'filename' => 'notes.zip',
                'filetype' => 'application/zip',
                'media_id' => (string) $created['id'],
            ],
        ]))
        ->assertUnprocessable();
});

it('stores the source and dispatches the media job when tus finishes', function (): void {
    Storage::fake('public');
    Bus::fake();

    $created = createMedia();
    $path = tempnam(sys_get_temp_dir(), 'tus');
    $bytes = mp4Bytes();
    file_put_contents($path, $bytes);

    $this->withHeaders(['X-Tus-Hook-Secret' => 'test-tus-secret'])
        ->postJson('/internal/tus/pre-create', mediaPayload($created['token'], $created['id'], [
            'Size' => strlen($bytes),
            'Storage' => ['Path' => $path],
        ]))
        ->assertOk();

    $this->withHeaders(['X-Tus-Hook-Secret' => 'test-tus-secret'])
        ->postJson('/internal/tus/pre-finish', mediaPayload($created['token'], $created['id'], [
            'Size' => strlen($bytes),
            'Offset' => strlen($bytes),
            'Storage' => ['Path' => $path],
        ]))
        ->assertOk();

    $item = MediaItem::query()->findOrFail($created['id']);

    expect($item->status)->toBe(VideoStatus::Uploaded);
    expect($item->getFirstMedia('source'))->not->toBeNull();

    Bus::assertDispatched(GenerateHlsJob::class, function (GenerateHlsJob $job) use ($created): bool {
        return $job->mediaId === $created['id'] && $job->queue === 'media';
    });
});

it('imports a finished tus file even when the library row has no tus id', function (): void {
    Storage::fake('public');
    Bus::fake();

    $created = createMedia();
    $bytes = mp4Bytes();
    $tusId = '37d1569e85db142ebe0566417ed4848a';
    $directory = storage_path('app/tus');

    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $path = $directory.DIRECTORY_SEPARATOR.$tusId;
    file_put_contents($path, $bytes);
    file_put_contents($path.'.info', json_encode([
        'ID' => $tusId,
        'Size' => strlen($bytes),
        'Offset' => 0,
        'MetaData' => [
            'filename' => 'clip.mp4',
            'filetype' => 'video/mp4',
            'media_id' => (string) $created['id'],
        ],
    ]));

    $item = MediaItem::query()->findOrFail($created['id']);

    expect($item->tus_id)->toBeNull();

    app(StoreFinishedUpload::class)->adoptIfComplete($item);

    $item->refresh();

    expect($item->status)->toBe(VideoStatus::Uploaded)
        ->and($item->tus_id)->toBe($tusId)
        ->and($item->getFirstMedia('source'))->not->toBeNull();

    Bus::assertDispatched(GenerateHlsJob::class);
});

it('marks a video ready from a mocked encoder and deletes the raw source', function (): void {
    Storage::fake('public');

    $this->app->instance(EncodesHls::class, new class implements EncodesHls
    {
        public function export(
            string $disk,
            string $sourceRelativePath,
            string $playlistRelativePath,
            ?callable $onProgress = null,
            string $kind = 'video',
        ): HlsEncodeResult {
            if ($onProgress !== null) {
                $onProgress(40);
            }

            Storage::disk($disk)->put($playlistRelativePath, "#EXTM3U\n");

            return new HlsEncodeResult(9, 1280, 720);
        }
    });

    $item = MediaItem::factory()->uploaded()->create();
    $source = tempnam(sys_get_temp_dir(), 'src');
    file_put_contents($source, 'source-bytes');
    $item->addMedia($source)->usingFileName('secret-source.mp4')->toMediaCollection('source');

    (new GenerateHlsJob($item->id))->handle(app(HlsTranscodeService::class));

    $item->refresh();

    expect($item->status)->toBe(VideoStatus::Ready);
    expect($item->hls_path)->toEndWith('master.m3u8');
    expect($item->progress)->toBe(100);
    expect($item->getFirstMedia('source'))->toBeNull();
    Storage::disk('public')->assertExists($item->hls_path);

    $admin = User::factory()->admin()->create();

    $payload = actingAsSanctum($admin)
        ->getJson('/api/v1/admin/media/'.$item->id)
        ->assertOk()
        ->json('data.media');

    expect($payload['stream_url'])->toContain('master.m3u8');
    expect(json_encode($payload))->not->toContain('secret-source');
});

it('keeps the source when encoding fails', function (): void {
    Storage::fake('public');

    $this->app->instance(EncodesHls::class, new class implements EncodesHls
    {
        public function export(
            string $disk,
            string $sourceRelativePath,
            string $playlistRelativePath,
            ?callable $onProgress = null,
            string $kind = 'video',
        ): HlsEncodeResult {
            throw new RuntimeException('encoder blew up');
        }
    });

    $item = MediaItem::factory()->uploaded()->audio()->create();
    $source = tempnam(sys_get_temp_dir(), 'src');
    file_put_contents($source, 'source-bytes');
    $item->addMedia($source)->usingFileName('secret-source.mp3')->toMediaCollection('source');

    $job = new GenerateHlsJob($item->id);

    expect(fn () => $job->handle(app(HlsTranscodeService::class)))->toThrow(RuntimeException::class);

    $job->failed(new RuntimeException('encoder blew up'));
    $item->refresh();

    expect($item->status)->toBe(VideoStatus::Failed);
    expect($item->kind)->toBe(MediaKind::Audio);
    expect($item->getFirstMedia('source'))->not->toBeNull();
});

it('deletes the media item, its files, and the hls directory', function (): void {
    Storage::fake('public');

    $admin = User::factory()->admin()->create();
    $item = MediaItem::factory()->ready()->create(['uploaded_by' => $admin->id]);
    Storage::disk('public')->put($item->hls_path, "#EXTM3U\n");
    $source = tempnam(sys_get_temp_dir(), 'src');
    file_put_contents($source, 'source-bytes');
    $item->addMedia($source)->usingFileName('secret-source.mp4')->toMediaCollection('source');

    actingAsSanctum($admin)
        ->deleteJson('/api/v1/admin/media/'.$item->id)
        ->assertOk();

    $this->assertDatabaseMissing('media_items', ['id' => $item->id]);
    $this->assertDatabaseMissing('media', ['model_id' => $item->id, 'model_type' => MediaItem::class]);
    Storage::disk('public')->assertMissing($item->hls_path);
});

it('rejects tus hooks that omit the shared secret', function (): void {
    $this->postJson('/internal/tus/pre-create', mediaPayload('token', 1))
        ->assertForbidden()
        ->assertJsonPath('message', 'Invalid tus hook secret.');
});

it('finds media by keyword', function (): void {
    $admin = User::factory()->admin()->create();
    MediaItem::factory()->create([
        'uploaded_by' => $admin->id,
        'title' => 'Studio take',
        'slug' => 'studio-take',
        'keywords' => ['interview', 'podcast'],
        'kind' => MediaKind::Audio,
        'status' => VideoStatus::Ready,
    ]);
    MediaItem::factory()->create([
        'uploaded_by' => $admin->id,
        'title' => 'Other',
        'slug' => 'other-file',
        'keywords' => ['poster'],
    ]);

    actingAsSanctum($admin)
        ->getJson('/api/v1/admin/media?filter[q]=podcast&filter[kind]=audio')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.media.0.slug', 'studio-take');
});

it('keeps media when the admin who uploaded it is deleted', function (): void {
    $uploader = User::factory()->admin()->create();
    $item = MediaItem::factory()->create(['uploaded_by' => $uploader->id]);

    $uploader->delete();

    expect($item->fresh())->not->toBeNull()
        ->and($item->fresh()->uploaded_by)->toBeNull();
});

it('refuses to retry a conversion that is still making progress', function (): void {
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $item = MediaItem::factory()->create([
        'kind' => MediaKind::Video,
        'status' => VideoStatus::Processing,
    ]);

    actingAsSanctum($admin)->postJson("/api/v1/admin/media/{$item->id}/retry")->assertUnprocessable();

    Bus::assertNotDispatched(GenerateHlsJob::class);
});
