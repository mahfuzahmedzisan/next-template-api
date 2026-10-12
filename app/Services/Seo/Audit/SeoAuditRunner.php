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
    ) {}

    /**
     * Queues a new audit, or returns null while another one is in progress.
     */
    public function start(?User $user, bool $includeNoindex = false, string $trigger = 'manual'): ?SeoAudit
    {
        return DB::transaction(function () use ($user, $includeNoindex, $trigger): ?SeoAudit {
            if (SeoAudit::query()->inProgress()->lockForUpdate()->exists()) {
                return null;
            }

            $audit = SeoAudit::query()->create([
                'status' => SeoAudit::QUEUED,
                'trigger' => $trigger,
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
     * Site, category and per-type scores plus the top issues, from the stored pages.
     */
    public function finalize(SeoAudit $audit): void
    {
        $pages = $audit->pages()->get();
        $scored = $pages->filter(fn (SeoAuditPage $page) => $page->indexable && $page->error === null && $page->score !== null);

        $categories = [];
        $issues = [];
        $environment = [];
        $informational = [];
        $passedChecks = 0;
        $failedChecks = 0;

        foreach ($pages as $page) {
            foreach ((array) $page->checks as $check) {
                $check['passed'] ? $passedChecks++ : $failedChecks++;

                if (! $page->indexable) {
                    continue;
                }

                if (! $check['counted']) {
                    $bucket = $check['category'] === 'performance' ? 'performance' : 'environment';
                    $environment[$check['key']] ??= ['key' => $check['key'], 'label' => $check['label'], 'category' => $bucket, 'passed' => 0, 'failed' => 0];
                    $environment[$check['key']][$check['passed'] ? 'passed' : 'failed']++;

                    continue;
                }

                $category = $check['category'];
                $categories[$category] ??= ['total' => 0, 'passed' => 0];
                $categories[$category]['total'] += (int) $check['weight'];
                $categories[$category]['passed'] += $check['passed'] ? (int) $check['weight'] : 0;

                if ($check['informational']) {
                    $informational[$check['key']] ??= ['key' => $check['key'], 'label' => $check['label'], 'passed' => 0, 'failed' => 0];
                    $informational[$check['key']][$check['passed'] ? 'passed' : 'failed']++;

                    continue;
                }

                if (! $check['passed']) {
                    $issues[$check['key']] ??= [
                        'key' => $check['key'],
                        'label' => $check['label'],
                        'category' => $category,
                        'priority' => $check['priority'],
                        'weight' => (int) $check['weight'],
                        'fix_minutes' => (int) $check['fix_minutes'],
                        'message' => $check['message'],
                        'pages' => 0,
                    ];
                    $issues[$check['key']]['pages']++;
                }
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

        $audit->forceFill([
            'status' => SeoAudit::COMPLETED,
            'score' => $scored->isEmpty() ? null : (int) round($scored->avg('score')),
            'pages_failed' => $pages->whereNotNull('error')->count(),
            'checks_passed' => $passedChecks,
            'checks_failed' => $failedChecks,
            'summary' => [
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
