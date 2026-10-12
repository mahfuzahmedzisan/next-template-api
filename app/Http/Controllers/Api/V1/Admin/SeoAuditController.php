<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\SeoAudit;
use App\Models\SeoAuditPage;
use App\Services\Seo\Audit\SeoAuditRunner;
use App\Services\Seo\Audit\SeoCheckCatalog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Application settings > SEO health: start a scan, poll it, and read results.
 */
class SeoAuditController extends Controller
{
    public function __construct(private readonly SeoAuditRunner $runner) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'include_noindex' => ['sometimes', 'boolean'],
            'scope' => ['sometimes', 'string', Rule::in(SeoAudit::SCOPES)],
        ]);

        $audit = $this->runner->start($request->user(), (bool) ($validated['include_noindex'] ?? false), scope: $validated['scope'] ?? 'full');

        if ($audit === null) {
            return ApiResponse::error('An audit is already running.', Response::HTTP_CONFLICT, $this->latestPayload());
        }

        Audit::log(
            AuditEvent::SeoAuditRequested,
            properties: ['include_noindex' => $audit->include_noindex, 'scope' => $audit->scope],
            subjectLabel: 'SEO audit #'.$audit->id,
        );

        return $this->apiSuccess('Audit started.', $this->latestPayload(), Response::HTTP_ACCEPTED);
    }

    public function latest(): JsonResponse
    {
        return $this->apiSuccess('Latest SEO audit.', $this->latestPayload());
    }

    public function index(): JsonResponse
    {
        $audits = SeoAudit::query()->latest('id')->limit((int) config('seo.audit.keep', 20))->get();

        return $this->apiSuccess('SEO audit history.', [
            'audits' => $audits->map(fn (SeoAudit $audit) => $this->auditRow($audit))->values(),
        ]);
    }

    public function page(SeoAudit $audit, SeoAuditPage $page): JsonResponse
    {
        abort_unless($page->seo_audit_id === $audit->id, Response::HTTP_NOT_FOUND);

        return $this->apiSuccess('Audit page.', [
            'page' => [
                ...$this->pageRow($page),
                'checks' => array_values((array) $page->checks),
            ],
        ]);
    }

    /**
     * The newest audit (any status), the newest completed full audit with its
     * pages, and the newest report of each section, which a sitemap- or
     * robots-only run can refresh on its own.
     *
     * @return array<string, mixed>
     */
    private function latestPayload(): array
    {
        $current = SeoAudit::query()->latest('id')->first();
        $completed = SeoAudit::query()->where('status', SeoAudit::COMPLETED)->where('scope', 'full')->latest('id')->first();
        $sections = [];

        foreach (['site' => 'site_checks', 'sitemap' => 'sitemap_report', 'robots' => 'robots_report'] as $section => $column) {
            $source = SeoAudit::query()->where('status', SeoAudit::COMPLETED)->whereNotNull($column)->latest('id')->first(['id', 'scope', 'environment', 'finished_at', $column]);

            $sections[$section] = $source === null ? null : [
                ...(array) $source->{$column},
                'audit_id' => $source->id,
                'scope' => $source->scope,
                'environment' => $source->environment,
                'finished_at' => $source->finished_at?->toIso8601String(),
            ];
        }

        return [
            'current' => $current ? $this->auditRow($current) : null,
            'completed' => $completed ? [
                ...$this->auditRow($completed),
                'summary' => $completed->summary,
                'pages' => $completed->pages()->orderByRaw('score is null')->orderBy('score')->orderBy('id')->get()
                    ->map(fn (SeoAuditPage $page) => $this->pageRow($page))->values(),
            ] : null,
            'sections' => $sections,
            'categories' => SeoCheckCatalog::CATEGORIES,
            'limits' => [
                'ttfb_ms' => (int) config('seo.audit.ttfb_ms'),
                'max_html_kb' => (int) config('seo.audit.max_html_kb'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditRow(SeoAudit $audit): array
    {
        return [
            'id' => $audit->id,
            'status' => $audit->status,
            'trigger' => $audit->trigger,
            'scope' => $audit->scope,
            'site_url' => $audit->site_url,
            'environment' => $audit->environment,
            'include_noindex' => $audit->include_noindex,
            'score' => $audit->score,
            'pages_total' => $audit->pages_total,
            'pages_scanned' => $audit->pages_scanned,
            'pages_failed' => $audit->pages_failed,
            'checks_passed' => $audit->checks_passed,
            'checks_failed' => $audit->checks_failed,
            'error' => $audit->error,
            'sections' => $this->sectionScores($audit),
            'created_at' => $audit->created_at?->toIso8601String(),
            'started_at' => $audit->started_at?->toIso8601String(),
            'finished_at' => $audit->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Section scores for the history trend. A sitemap- or robots-only run
     * scores just its own sections; the others stay null.
     *
     * @return list<array{key: string, score: int|null}>
     */
    private function sectionScores(SeoAudit $audit): array
    {
        $stored = collect($audit->summary['sections'] ?? [])->pluck('score', 'key');
        $reports = ['site' => $audit->site_checks, 'sitemap' => $audit->sitemap_report, 'robots' => $audit->robots_report];

        return collect(['pages', 'site', 'sitemap', 'robots'])
            ->map(fn (string $key): array => [
                'key' => $key,
                'score' => isset($stored[$key]) ? (int) $stored[$key] : (isset($reports[$key]['score']) ? (int) $reports[$key]['score'] : null),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function pageRow(SeoAuditPage $page): array
    {
        return [
            'id' => $page->id,
            'audit_id' => $page->seo_audit_id,
            'kind' => $page->kind,
            'page_type' => $page->page_type,
            'label' => $page->label,
            'url' => $page->url,
            'seo_page_id' => $page->seo_page_id,
            'seoable_type' => $page->seoable_type,
            'seoable_id' => $page->seoable_id,
            'indexable' => $page->indexable,
            'status_code' => $page->status_code,
            'score' => $page->score,
            'passed' => $page->passed,
            'failed' => $page->failed,
            'ttfb_ms' => $page->ttfb_ms,
            'html_bytes' => $page->html_bytes,
            'error' => $page->error,
        ];
    }
}
