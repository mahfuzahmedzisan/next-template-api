<?php

namespace App\Services\Seo\Audit;

use App\Contracts\Seo\Seoable;
use App\Jobs\Seo\RunSeoAudit;
use App\Models\SeoAudit;
use App\Models\SeoAuditPage;
use App\Models\SeoPage;
use App\Models\User;
use App\Services\Seo\SeoResolver;
use App\Services\Seo\SeoSettings;
use App\Support\Seo\SeoUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Starts, runs and scores SEO health audits. The job calls into this class so
 * the same code runs inline (small sites) and in batch chunks (large ones).
 */
final class SeoAuditRunner
{
    public function __construct(
        private readonly SeoSettings $settings,
        private readonly SeoResolver $resolver,
        private readonly SeoPageScanner $scanner,
        private readonly SiteScanner $site,
        private readonly RobotsTxtScanner $robotsScanner,
        private readonly SitemapScanner $sitemap,
    ) {}

    /**
     * Queues a new audit, or returns null while another one is in progress.
     */
    public function start(?User $user, bool $includeNoindex = false, string $trigger = 'manual', string $scope = 'full'): ?SeoAudit
    {
        return DB::transaction(function () use ($user, $includeNoindex, $trigger, $scope): ?SeoAudit {
            if (SeoAudit::query()->inProgress()->lockForUpdate()->exists()) {
                return null;
            }

            $audit = SeoAudit::query()->create([
                'status' => SeoAudit::QUEUED,
                'trigger' => $trigger,
                'scope' => in_array($scope, SeoAudit::SCOPES, true) ? $scope : 'full',
                'triggered_by' => $user?->id,
                'include_noindex' => $includeNoindex,
            ]);

            RunSeoAudit::dispatch($audit->id);

            return $audit;
        });
    }

    /**
     * Pages and records to scan. Indexable ones count toward the score;
     * noindex ones are only added on request and are never scored.
     *
     * @return list<array{kind: string, page_type: string, label: string, url: string, seo_page_id: ?int, seoable_type: ?string, seoable_id: ?int, indexable: bool}>
     */
    public function targets(bool $includeNoindex): array
    {
        $this->settings->refresh();
        $this->resolver->forgetMemo();
        $targets = [];

        SeoPage::query()->orderByDesc('is_system')->orderBy('path')->get()->each(function (SeoPage $page) use (&$targets, $includeNoindex): void {
            $resolved = $this->resolver->forPage($page);
            $indexable = (bool) $resolved['robots']['index'];

            if ($indexable || $includeNoindex) {
                $targets[] = [
                    'kind' => 'page',
                    'page_type' => $page->page_type,
                    'label' => $page->label,
                    'url' => (string) $resolved['canonical_base'],
                    'seo_page_id' => $page->id,
                    'seoable_type' => null,
                    'seoable_id' => null,
                    'indexable' => $indexable,
                ];
            }
        });

        $limit = max(1, (int) config('seo.audit.limit_per_type', 200));

        foreach ((array) config('seo.seoables', []) as $alias => $class) {
            /** @var class-string<Model&Seoable> $class */
            $class::query()->seoPublished()->with('seo')->latest('id')->limit($limit)->get()
                ->each(function (Model&Seoable $model) use (&$targets, $alias, $includeNoindex): void {
                    $resolved = $this->resolver->resolveModel($model);
                    $indexable = (bool) $resolved['robots']['index'];

                    if ($indexable || $includeNoindex) {
                        $targets[] = [
                            'kind' => 'model',
                            'page_type' => (string) $alias,
                            'label' => (string) $resolved['raw_title'],
                            'url' => (string) $resolved['canonical_base'],
                            'seo_page_id' => null,
                            'seoable_type' => (string) $alias,
                            'seoable_id' => (int) $model->getKey(),
                            'indexable' => $indexable,
                        ];
                    }
                });
        }

        return $targets;
    }

    /**
     * A scan of `next dev`, or of a localhost URL, can't judge speed or TLS.
     */
    public function isDevelopment(string $siteUrl, ?string $sampleHtml = null): bool
    {
        $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));

        if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return true;
        }

        return $sampleHtml !== null && preg_match('/hmr-client|webpack-hmr|react-refresh/', $sampleHtml) === 1;
    }

    /**
     * One GET per URL with a long timeout, so `next dev` compiles each route
     * before it is timed.
     *
     * @param  list<string>  $urls
     */
    public function warmUp(array $urls): ?string
    {
        $timeout = (int) config('seo.audit.warmup_timeout', 60);
        $sample = null;

        foreach (array_unique($urls) as $url) {
            try {
                $body = $this->scanner->client($timeout)->get($url)->body();
                $sample ??= $body;
            } catch (Throwable) {
                // The scan records the error for this URL.
            }
        }

        return $sample;
    }

    public function robots(string $siteUrl): RobotsRules
    {
        try {
            $response = Http::timeout(10)
                ->withUserAgent((string) config('seo.audit.user_agent'))
                ->get(SeoUrl::join($siteUrl, '/robots.txt'));

            return RobotsRules::parse($response->successful() ? $response->body() : null);
        } catch (Throwable) {
            return RobotsRules::parse(null);
        }
    }

    /**
     * Runs the robots.txt scan, then (by scope) the sitemap and site scans,
     * and stores their reports. Returns the robots rules for the page scan.
     *
     * @param  list<array<string, mixed>>  $targets
     */
    public function scanSections(SeoAudit $audit, array $targets): RobotsRules
    {
        $siteUrl = (string) $audit->site_url;
        $development = $audit->environment === 'development';
        $indexable = array_values(array_filter($targets, static fn (array $target) => $target['indexable']));
        $paths = array_map(static fn (array $target) => (string) (parse_url($target['url'], PHP_URL_PATH) ?: '/'), $indexable);

        $robots = $this->robotsScanner->scan($siteUrl, $paths, $development);
        $reports = ['robots_report' => $robots['report']];

        if (in_array($audit->scope, ['full', 'sitemap'], true)) {
            $reports['sitemap_report'] = $this->sitemap->scan($siteUrl, $robots['rules']->sitemaps(), array_column($indexable, 'url'), $robots['rules'], $development);
        }

        if ($audit->scope === 'full') {
            $sample = collect($paths)->first(static fn (string $path) => $path !== '/');
            $reports['site_checks'] = $this->site->scan($siteUrl, $sample, $development);
        }

        $audit->forceFill($reports)->save();

        return $robots['rules'];
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    public function scanTargets(SeoAudit $audit, array $targets, RobotsRules $robots): void
    {
        $development = $audit->environment === 'development';

        foreach ($targets as $target) {
            try {
                $result = $this->scanner->scan($target, $robots, $development);
            } catch (Throwable $exception) {
                $result = [
                    'status_code' => null, 'ttfb_ms' => null, 'html_bytes' => null, 'checks' => [],
                    'score' => null, 'passed' => 0, 'failed' => 0,
                    'error' => mb_substr('Scan failed: '.$exception->getMessage(), 0, 500),
                ];
            }

            SeoAuditPage::query()->create([
                'seo_audit_id' => $audit->id,
                ...array_intersect_key($target, array_flip(['kind', 'page_type', 'label', 'url', 'seo_page_id', 'seoable_type', 'seoable_id', 'indexable'])),
                'label' => mb_substr((string) $target['label'], 0, 255),
                ...$result,
            ]);

            $audit->newQuery()->whereKey($audit->id)->increment('pages_scanned');
        }
    }

    /**
     * Section, category and per-type scores plus the top issues, from the
     * stored pages and the site, robots.txt and sitemap reports.
     */
    public function finalize(SeoAudit $audit): void
    {
        $pages = $audit->pages()->get();
        $this->applyUniqueness($pages);
        $scored = $pages->filter(fn (SeoAuditPage $page) => $page->indexable && $page->error === null && $page->score !== null);

        $categories = [];
        $issues = [];
        $environment = [];
        $informational = [];
        $passedChecks = 0;
        $failedChecks = 0;

        $collect = function (array $check, string $section) use (&$categories, &$issues, &$environment, &$informational): void {
            if (! $check['counted']) {
                $bucket = $check['category'] === 'performance' ? 'performance' : 'environment';
                $environment[$check['key']] ??= ['key' => $check['key'], 'label' => $check['label'], 'category' => $bucket, 'passed' => 0, 'failed' => 0];
                $environment[$check['key']][$check['passed'] ? 'passed' : 'failed']++;

                return;
            }

            if ($section === 'pages') {
                $categories[$check['category']] ??= ['total' => 0, 'passed' => 0];
                $categories[$check['category']]['total'] += (int) $check['weight'];
                $categories[$check['category']]['passed'] += $check['passed'] ? (int) $check['weight'] : 0;
            }

            if ($check['informational']) {
                $informational[$check['key']] ??= ['key' => $check['key'], 'label' => $check['label'], 'passed' => 0, 'failed' => 0];
                $informational[$check['key']][$check['passed'] ? 'passed' : 'failed']++;

                return;
            }

            if (! $check['passed']) {
                $issues[$check['key']] ??= [
                    'key' => $check['key'],
                    'label' => $check['label'],
                    'category' => $check['category'],
                    'section' => $section,
                    'priority' => $check['priority'],
                    'weight' => (int) $check['weight'],
                    'fix_minutes' => (int) $check['fix_minutes'],
                    'message' => $check['message'],
                    'pages' => 0,
                ];

                if ($section === 'pages') {
                    $issues[$check['key']]['pages']++;
                }
            }
        };

        foreach ($pages as $page) {
            foreach ((array) $page->checks as $check) {
                $check['passed'] ? $passedChecks++ : $failedChecks++;

                if ($page->indexable) {
                    $collect($check, 'pages');
                }
            }
        }

        $reports = ['site' => $audit->site_checks, 'sitemap' => $audit->sitemap_report, 'robots' => $audit->robots_report];

        foreach ($reports as $section => $report) {
            foreach ((array) ($report['checks'] ?? []) as $check) {
                $check['passed'] ? $passedChecks++ : $failedChecks++;
                $collect($check, $section);
            }
        }

        $issues = array_values($issues);
        usort($issues, static fn (array $a, array $b) => [SeoCheckCatalog::PRIORITY_RANK[$b['priority']] * $b['weight'], $b['pages']]
            <=> [SeoCheckCatalog::PRIORITY_RANK[$a['priority']] * $a['weight'], $a['pages']]);

        $types = [];

        foreach ($scored->groupBy(fn (SeoAuditPage $page) => $page->kind === 'page' ? 'static' : $page->page_type) as $type => $group) {
            $types[] = [
                'type' => (string) $type,
                'label' => $type === 'static' ? 'Static pages' : str((string) $type)->headline()->plural()->toString(),
                'pages' => $group->count(),
                'score' => (int) round($group->avg('score')),
            ];
        }

        $categoryScores = [];

        foreach (SeoCheckCatalog::CATEGORIES as $key => $label) {
            if (isset($categories[$key]) && $categories[$key]['total'] > 0) {
                $categoryScores[] = [
                    'key' => $key,
                    'label' => $label,
                    'score' => (int) round($categories[$key]['passed'] / $categories[$key]['total'] * 100),
                ];
            }
        }

        $sectionScores = [
            'pages' => $scored->isEmpty() ? null : (int) round($scored->avg('score')),
            'site' => $reports['site']['score'] ?? null,
            'sitemap' => $reports['sitemap']['score'] ?? null,
            'robots' => $reports['robots']['score'] ?? null,
        ];
        $sections = [];
        $weighted = 0;
        $weights = 0;

        foreach (SeoCheckCatalog::SECTIONS as $key => $label) {
            $score = $sectionScores[$key];
            $weight = SeoCheckCatalog::SECTION_WEIGHTS[$key];
            $sections[] = ['key' => $key, 'label' => $label, 'score' => $score, 'weight' => $weight];

            if ($score !== null) {
                $weighted += $score * $weight;
                $weights += $weight;
            }
        }

        $audit->forceFill([
            'status' => SeoAudit::COMPLETED,
            'score' => $weights === 0 ? null : (int) round($weighted / $weights),
            'pages_failed' => $pages->whereNotNull('error')->count(),
            'checks_passed' => $passedChecks,
            'checks_failed' => $failedChecks,
            'summary' => [
                'sections' => $sections,
                'categories' => $categoryScores,
                'types' => $types,
                'issues' => array_slice($issues, 0, 20),
                'informational' => array_values($informational),
                'not_scored' => array_values($environment),
            ],
            'finished_at' => now(),
        ])->save();

        $this->prune();
    }

    /**
     * Adds title_unique and description_unique to every indexable page that has
     * a title or description, comparing across the whole audit, then rescores.
     *
     * @param  Collection<int, SeoAuditPage>  $pages
     */
    private function applyUniqueness(Collection $pages): void
    {
        $candidates = $pages->filter(fn (SeoAuditPage $page) => $page->indexable && $page->error === null && $page->status_code === 200);
        $added = [];

        foreach (['title_unique' => 'title_present', 'description_unique' => 'description_present'] as $key => $source) {
            $values = [];

            foreach ($candidates as $page) {
                $check = collect((array) $page->checks)->firstWhere('key', $source);

                if (is_array($check) && $check['passed'] && is_string($check['actual'] ?? null)) {
                    $values[$page->id] = mb_strtolower(trim($check['actual']));
                }
            }

            $groups = [];

            foreach ($values as $id => $value) {
                $groups[$value][] = $id;
            }

            foreach ($values as $id => $value) {
                $others = array_values(array_diff($groups[$value], [$id]));
                $labels = $candidates->whereIn('id', $others)->pluck('label')->take(3)->implode(', ');
                $what = $key === 'title_unique' ? 'title' : 'description';

                $added[$id][] = SeoCheckCatalog::row($key, $others === []
                    ? ['passed' => true, 'actual' => 'unique', 'expected' => 'unique', 'message' => 'No other page uses this '.$what.'.']
                    : ['passed' => false, 'actual' => 'same as '.$labels.(count($others) > 3 ? ' and '.(count($others) - 3).' more' : ''), 'expected' => 'unique', 'message' => 'Several pages share this '.$what.'. Search engines may show only one of them.'],
                    true);
            }
        }

        foreach ($candidates as $page) {
            if (! isset($added[$page->id])) {
                continue;
            }

            $checks = [...array_values(array_filter((array) $page->checks, static fn (array $check) => ! in_array($check['key'], ['title_unique', 'description_unique'], true))), ...$added[$page->id]];

            $page->forceFill([
                'checks' => $checks,
                'score' => SeoCheckCatalog::score($checks) ?? 0,
                'passed' => count(array_filter($checks, static fn (array $check) => $check['passed'])),
                'failed' => count(array_filter($checks, static fn (array $check) => ! $check['passed'])),
            ])->save();
        }
    }

    public function fail(SeoAudit $audit, string $message): void
    {
        $audit->forceFill([
            'status' => SeoAudit::FAILED,
            'error' => mb_substr($message, 0, 1000),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Keeps the latest N audits; their pages go with them.
     */
    public function prune(): void
    {
        $keep = max(1, (int) config('seo.audit.keep', 20));
        $ids = SeoAudit::query()->latest('id')->skip($keep)->take(PHP_INT_MAX)->pluck('id');

        if ($ids->isNotEmpty()) {
            SeoAudit::query()->whereKey($ids)->delete();
        }
    }

    public function siteUrl(): string
    {
        $this->settings->refresh();

        return $this->settings->siteUrl();
    }
}
