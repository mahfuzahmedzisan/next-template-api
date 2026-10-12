<?php

namespace App\Services\Seo;

use App\Contracts\Seo\Seoable;
use App\Models\SeoPage;
use App\Support\Seo\SeoUrl;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\SitemapIndex;
use Spatie\Sitemap\Tags\Url;

/**
 * Builds sitemap.xml, robots.txt and llms.txt from the resolved SEO of every
 * registered page and detail record, and stores them on disk so the public
 * endpoints only read a file.
 */
final class SeoFileGenerator
{
    public const FILES = ['sitemap', 'robots', 'llms'];

    private const DIRECTORY = 'seo';

    public function __construct(
        private readonly SeoSettings $settings,
        private readonly SeoResolver $resolver,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function generate(string $file = 'all'): array
    {
        $this->settings->refresh();
        $this->resolver->forgetMemo();

        $files = $file === 'all' ? self::FILES : [$file];
        $entries = null;
        $meta = [];

        foreach ($files as $name) {
            $meta[$name] = match ($name) {
                'sitemap' => $this->writeSitemap($entries ??= $this->entries()),
                'robots' => $this->writeRobots(),
                'llms' => $this->writeLlms($entries ??= $this->entries()),
                default => throw new \InvalidArgumentException("Unknown SEO file [{$name}]."),
            };

            $this->settings->recordFile($name, $meta[$name]);
        }

        return $meta;
    }

    /**
     * Contents of a public file, generating it on first request. The lock
     * keeps concurrent first requests from generating the same file twice.
     */
    public function read(string $name): ?string
    {
        $file = $this->fileFor($name);

        if ($file === null) {
            return null;
        }

        if ($file === 'llms' && ! $this->settings->all()['llms_enabled']) {
            return null;
        }

        $path = $this->path($name);

        if (! $this->disk()->exists($path)) {
            Cache::lock('seo:generate:'.$file, 60)->block(15, function () use ($file, $path): void {
                if (! $this->disk()->exists($path)) {
                    $this->generate($file);
                }
            });
        }

        $contents = $this->disk()->get($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Indexable canonical URLs: registered static pages that answer, plus
     * published detail records, both only when they are their own canonical.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $entries = [];

        SeoPage::query()->orderBy('path')->get()->each(function (SeoPage $page) use (&$entries): void {
            if (! $page->statusAllowsSitemap()) {
                return;
            }

            $resolved = $this->resolver->forPage($page);

            if ($resolved['sitemap']['include']) {
                $entries[] = $resolved;
            }
        });

        foreach ($this->seoableClasses() as $class) {
            /** @var class-string<Model&Seoable> $class */
            $class::query()->seoPublished()->with('seo')->lazyById(500)->each(function (Model&Seoable $model) use (&$entries): void {
                $resolved = $this->resolver->resolveModel($model);

                if ($resolved['sitemap']['include']) {
                    $entries[] = $resolved;
                }
            });
        }

        return $entries;
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('seo.files_disk', 'local'));
    }

    /**
     * "sitemap.xml", "robots.txt", "llms.txt" or "sitemaps/sitemap-2.xml".
     */
    public function path(string $name): string
    {
        return self::DIRECTORY.'/'.$name;
    }

    public function fileFor(string $name): ?string
    {
        return match (true) {
            $name === 'sitemap.xml', preg_match('#^sitemaps/sitemap-\d+\.xml$#', $name) === 1 => 'sitemap',
            $name === 'robots.txt' => 'robots',
            $name === 'llms.txt' => 'llms',
            default => null,
        };
    }

    public function publicName(string $file): string
    {
        return match ($file) {
            'sitemap' => 'sitemap.xml',
            'robots' => 'robots.txt',
            'llms' => 'llms.txt',
            default => throw new \InvalidArgumentException("Unknown SEO file [{$file}]."),
        };
    }

    /**
     * @return list<class-string>
     */
    private function seoableClasses(): array
    {
        return array_values(array_filter(
            (array) config('seo.seoables', []),
            static fn ($class) => is_string($class) && class_exists($class) && is_subclass_of($class, Seoable::class),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function writeSitemap(array $entries): array
    {
        $max = max(1, (int) config('seo.sitemap.max_urls_per_file', 50000));
        $siteUrl = $this->settings->siteUrl();
        $chunks = array_chunk($entries, $max);
        $disk = $this->disk();

        foreach ($disk->files(self::DIRECTORY.'/sitemaps') as $stale) {
            $disk->delete($stale);
        }

        if (count($chunks) <= 1) {
            $xml = $this->sitemapFor($chunks[0] ?? [])->render();
            $disk->put($this->path('sitemap.xml'), $xml);

            return $this->meta($xml, count($entries), 1);
        }

        $index = SitemapIndex::create();

        foreach ($chunks as $i => $chunk) {
            $name = 'sitemaps/sitemap-'.($i + 1).'.xml';
            $disk->put($this->path($name), $this->sitemapFor($chunk)->render());
            $index->add(SeoUrl::join($siteUrl, '/'.$name));
        }

        $xml = $index->render();
        $disk->put($this->path('sitemap.xml'), $xml);

        return $this->meta($xml, count($entries), count($chunks) + 1);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function sitemapFor(array $entries): Sitemap
    {
        $sitemap = Sitemap::create();

        foreach ($entries as $entry) {
            $url = Url::create((string) $entry['canonical'])
                ->setPriority((float) $entry['sitemap']['priority'])
                ->setChangeFrequency((string) $entry['sitemap']['changefreq']);

            if (is_string($entry['sitemap']['lastmod'] ?? null)) {
                $url->setLastModificationDate(Carbon::parse($entry['sitemap']['lastmod']));
            }

            $sitemap->add($url);
        }

        return $sitemap;
    }

    /**
     * @return array<string, mixed>
     */
    private function writeRobots(): array
    {
        $settings = $this->settings->all();
        $siteUrl = $settings['site_url'];
        $lines = ['User-agent: *'];

        foreach ((array) config('seo.robots.always_allow', []) as $path) {
            $lines[] = 'Allow: '.$path;
        }

        foreach ([...(array) config('seo.robots.always_disallow', []), ...$settings['robots_disallow']] as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = 'Allow: /';

        if (! $settings['allow_ai_crawlers']) {
            $lines[] = '';

            foreach ((array) config('seo.robots.ai_crawlers', []) as $bot) {
                $lines[] = 'User-agent: '.$bot;
            }

            $lines[] = 'Disallow: /';
        }

        $extra = trim((string) $settings['robots_extra']);

        if ($extra !== '') {
            $lines[] = '';
            $lines[] = $extra;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.SeoUrl::join($siteUrl, '/sitemap.xml');

        $text = implode("\n", $lines)."\n";
        $this->disk()->put($this->path('robots.txt'), $text);

        return $this->meta($text, null, 1);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function writeLlms(array $entries): array
    {
        $settings = $this->settings->all();

        if (! $settings['llms_enabled']) {
            $this->disk()->delete($this->path('llms.txt'));

            return ['enabled' => false, 'generated_at' => now()->toIso8601String(), 'size' => 0, 'urls' => 0, 'files' => 0];
        }

        $identity = $this->settings->identity();
        $lines = ['# '.$identity['name'], ''];

        if ($settings['default_description'] !== '') {
            $lines[] = '> '.$settings['default_description'];
            $lines[] = '';
        }

        $lines[] = '## Pages';
        $lines[] = '';

        foreach ($entries as $entry) {
            $line = '- ['.str_replace(['[', ']'], '', (string) $entry['raw_title']).']('.$entry['canonical'].')';

            if (is_string($entry['description']) && $entry['description'] !== '') {
                $line .= ': '.$entry['description'];
            }

            $lines[] = $line;
        }

        $text = implode("\n", $lines)."\n";
        $this->disk()->put($this->path('llms.txt'), $text);

        return $this->meta($text, count($entries), 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $contents, ?int $urls, int $files): array
    {
        return [
            'enabled' => true,
            'generated_at' => now()->toIso8601String(),
            'size' => strlen($contents),
            'urls' => $urls,
            'files' => $files,
        ];
    }
}
