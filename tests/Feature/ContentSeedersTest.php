<?php

use App\Models\Post;
use App\Models\Project;
use App\Services\Seo\SeoFileGenerator;
use Database\Seeders\PostSeeder;
use Database\Seeders\ProjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Storage::fake('public');
    Storage::fake('local');
});

function bodyWords(string $body): int
{
    $text = preg_replace('/^(#{1,6}|-)\s+/m', '', $body);

    return count(preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY));
}

it('seeds published projects and posts with enough copy, covers and strict-length SEO', function (string $seeder, string $model, int $minWords): void {
    $this->seed($seeder);

    $records = $model::query()->with('seo')->get();
    expect($records)->toHaveCount(6);

    foreach ($records as $record) {
        $seo = $record->resolvedSeo();
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($record->cover));

        expect(bodyWords($record->body))->toBeGreaterThanOrEqual($minWords, $record->slug.' body is too short')
            ->and(mb_strlen($seo['title']))->toBeBetween(50, 60, $record->slug.' title: '.$seo['title'])
            ->and(mb_strlen($seo['description']))->toBeBetween(140, 160, $record->slug.' description: '.$seo['description'])
            ->and($seo['title'])->toBe($record->seo->meta_title)
            ->and([$width, $height])->toBe([1200, 628])
            ->and($seo['open_graph']['image_width'])->toBe(1200)
            ->and($seo['sitemap']['include'])->toBeTrue()
            ->and($record->seoIsPublished())->toBeTrue();
    }

    $resolved = $records->map->resolvedSeo();
    expect($resolved->pluck('title')->unique())->toHaveCount(6)
        ->and($resolved->pluck('description')->unique())->toHaveCount(6);
})->with([
    'projects' => [ProjectSeeder::class, Project::class, 400],
    'posts' => [PostSeeder::class, Post::class, 600],
]);

it('keeps edited records when the seeders run again', function (): void {
    $this->seed([ProjectSeeder::class, PostSeeder::class]);

    $project = Project::query()->firstOrFail();
    $project->update(['title' => 'Edited title']);
    $project->seo->update(['meta_title' => 'An edited SEO title that the admin wrote by hand ok']);

    $this->seed([ProjectSeeder::class, PostSeeder::class]);

    expect($project->fresh()->title)->toBe('Edited title')
        ->and($project->fresh()->seo->meta_title)->toBe('An edited SEO title that the admin wrote by hand ok')
        ->and(Project::query()->count())->toBe(6)
        ->and(Post::query()->count())->toBe(6);
});

it('serves published projects and posts with their resolved SEO', function (): void {
    $this->seed([ProjectSeeder::class, PostSeeder::class]);
    Post::query()->where('slug', 'scoping-projects')->update(['published_at' => now()->addDay()]);

    $this->getJson('/api/v1/projects?featured=1')
        ->assertOk()
        ->assertJsonCount(4, 'data.projects')
        ->assertJsonPath('data.meta.total', 4)
        ->assertJsonMissingPath('data.projects.0.body');

    $this->getJson('/api/v1/projects/ledgerly-finance-dashboard')
        ->assertOk()
        ->assertJsonPath('data.project.path', '/projects/ledgerly-finance-dashboard')
        ->assertJsonPath('data.project.seo.schema.type', 'CreativeWork')
        ->assertJsonPath('data.project.seo.title', 'Ledgerly Finance Dashboard Case Study for Small Agencies');

    $this->getJson('/api/v1/posts')->assertOk()->assertJsonPath('data.meta.total', 5);

    $this->getJson('/api/v1/posts/nextjs-seo-checklist')
        ->assertOk()
        ->assertJsonPath('data.post.seo.schema.json_ld.@type', 'Article')
        ->assertJsonPath('data.post.seo.schema.json_ld.author.name', 'Mahfuz Ahmed');

    $this->getJson('/api/v1/posts/scoping-projects')->assertNotFound();
    $this->getJson('/api/v1/projects/missing-project')->assertNotFound();
});

it('lists every published project and post in the sitemap', function (): void {
    $this->seed([ProjectSeeder::class, PostSeeder::class]);

    $xml = app(SeoFileGenerator::class)->generate('sitemap') ? app(SeoFileGenerator::class)->read('sitemap.xml') : '';

    foreach ([...Project::query()->pluck('slug')->map(fn ($slug) => '/projects/'.$slug), ...Post::query()->pluck('slug')->map(fn ($slug) => '/blog/'.$slug)] as $path) {
        expect($xml)->toContain($path.'</loc>');
    }
});
