<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\Audit;
use App\Audit\AuditCategory;
use App\Audit\AuditEvent;
use App\Audit\AuditSeverity;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityDetailResource;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Services\ApplicationSettings;
use App\Support\Query\BuildsApiListQuery;
use App\Support\Stats\DailyBuckets;
use App\Support\UserAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Activitylog\Enums\ActivityEvent;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The site-wide audit log. Filters use the `filter[...]` query syntax shared
 * by every admin list, so the CSV export accepts exactly the same URL.
 */
class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = BuildsApiListQuery::make(Activity::query(), $this->filters(), ['id', 'created_at'])
            ->defaultSort('-id')
            ->paginate(BuildsApiListQuery::perPage($request, 25, 100))
            ->withQueryString();

        return $this->apiSuccess('Activity retrieved successfully.', [
            'activities' => ActivityResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function overview(ApplicationSettings $settings): JsonResponse
    {
        $day = now()->subDay();
        $week = now()->subWeek();
        $elevated = AuditSeverity::elevated();
        $failedSignIns = [
            AuditEvent::LoginFailed->value,
            AuditEvent::LoginThrottled->value,
            AuditEvent::TwoFactorChallengeFailed->value,
        ];
        $in = fn (array $values): string => implode(', ', array_fill(0, count($values), '?'));

        // One scan with conditional sums instead of five COUNT queries over the whole log, cached briefly.
        $stats = Cache::remember('audit:overview-stats', 60, function () use ($day, $week, $elevated, $failedSignIns, $in): array {
            $counts = Activity::query()
                ->toBase()
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_24_hours', [$day])
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_7_days', [$week])
                ->selectRaw('SUM(CASE WHEN created_at >= ? AND severity IN ('.$in($elevated).') THEN 1 ELSE 0 END) as elevated_7_days', [$week, ...$elevated])
                ->selectRaw('SUM(CASE WHEN created_at >= ? AND event IN ('.$in($failedSignIns).') THEN 1 ELSE 0 END) as failed_sign_ins_24_hours', [$day, ...$failedSignIns])
                ->first();

            return [
                'total' => (int) ($counts->total ?? 0),
                'last_24_hours' => (int) ($counts->last_24_hours ?? 0),
                'last_7_days' => (int) ($counts->last_7_days ?? 0),
                'elevated_7_days' => (int) ($counts->elevated_7_days ?? 0),
                'failed_sign_ins_24_hours' => (int) ($counts->failed_sign_ins_24_hours ?? 0),
                'oldest_at' => Activity::query()->oldest('id')->first(['id', 'created_at'])?->created_at?->toIso8601String(),
            ];
        });

        return $this->apiSuccess('Audit overview.', [
            'stats' => $stats,
            'retention_days' => $settings->auditRetentionDays(),
            'options' => [
                'categories' => array_map(fn (AuditCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ], AuditCategory::cases()),
                'severities' => array_map(fn (AuditSeverity $severity): array => [
                    'value' => $severity->value,
                    'label' => $severity->label(),
                ], AuditSeverity::cases()),
                'events' => [
                    ...array_map(fn (AuditEvent $event): array => [
                        'value' => $event->value,
                        'label' => $event->label(),
                        'category' => $event->category()->value,
                    ], AuditEvent::cases()),
                    ...array_map(fn (ActivityEvent $event): array => [
                        'value' => $event->value,
                        'label' => 'Record '.$event->value,
                        'category' => null,
                    ], ActivityEvent::cases()),
                ],
                'subjects' => array_keys((array) config('audit.subjects', [])),
            ],
        ]);
    }

    public function trend(Request $request, ApplicationSettings $settings): JsonResponse
    {
        $buckets = DailyBuckets::fromRequest($request);

        // The newest id in the key lets a refresh after new entries skip the cache.
        $latest = (int) Activity::query()->max('id');

        $data = Cache::remember($buckets->cacheKey('audit:trend:'.$latest), 60, function () use ($buckets): array {
            $severities = array_map(fn (AuditSeverity $severity): string => $severity->value, AuditSeverity::cases());
            $categories = array_map(fn (AuditCategory $category): string => $category->value, AuditCategory::cases());
            $failedSignIns = [
                AuditEvent::LoginFailed->value,
                AuditEvent::LoginThrottled->value,
                AuditEvent::TwoFactorChallengeFailed->value,
            ];

            $daily = $buckets->count(Activity::query(), 'severity', $severities);
            $dailyCategories = $buckets->count(Activity::query(), 'log_name', $categories);
            $categoryTotals = DailyBuckets::totals($dailyCategories, $categories);

            $signIns = $buckets->count(
                Activity::query()->whereIn('event', [AuditEvent::LoginSucceeded->value, ...$failedSignIns]),
                "CASE WHEN event = ? THEN 'succeeded' ELSE 'failed' END",
                ['succeeded', 'failed'],
                [AuditEvent::LoginSucceeded->value],
            );

            return [
                ...$buckets->meta(),
                'daily' => $daily,
                'daily_categories' => $dailyCategories,
                'by_category' => array_map(fn (AuditCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                    'count' => $categoryTotals[$category->value],
                ], AuditCategory::cases()),
                'sign_ins' => $signIns,
            ];
        });

        return $this->apiSuccess('Audit trend.', [...$data, 'retention_days' => $settings->auditRetentionDays()]);
    }

    public function show(Activity $activity): JsonResponse
    {
        return $this->apiSuccess('Activity retrieved successfully.', [
            'activity' => (new ActivityDetailResource($activity))->resolve(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $max = max(1, (int) config('audit.export_max_rows', 50000));
        $query = QueryBuilder::for(Activity::query())
            ->allowedFilters(...$this->filters())
            ->getEloquentBuilder();

        $rows = min($max, (clone $query)->count());

        Audit::log(AuditEvent::AuditExported, properties: [
            'rows' => $rows,
            'truncated' => $rows === $max,
            'filters' => array_filter((array) $request->input('filter', []), fn ($value) => $value !== null && $value !== ''),
        ], subjectLabel: 'Audit log');

        $filename = 'audit-log-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $max): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID', 'Time (UTC)', 'Category', 'Event', 'Title', 'Severity',
                'Actor', 'Actor ID', 'Subject type', 'Subject ID', 'Subject',
                'IP address', 'Browser', 'Platform', 'Origin', 'Request ID',
                'Changed fields', 'Description',
            ]);

            $query->lazyByIdDesc(500)->take($max)->each(function (Activity $activity) use ($out): void {
                $device = UserAgent::parse($activity->user_agent);
                $changes = $activity->attribute_changes?->toArray() ?? [];

                fputcsv($out, array_map(self::csvSafe(...), [
                    $activity->id,
                    $activity->created_at?->utc()->format('Y-m-d H:i:s'),
                    $activity->category()?->label() ?? $activity->log_name,
                    $activity->event,
                    AuditEvent::tryFrom((string) $activity->event)?->label() ?? $activity->description,
                    $activity->severity,
                    $activity->causer_label,
                    $activity->causer_id,
                    Activity::aliasFor($activity->subject_type)
                        ?? ($activity->subject_type !== null ? class_basename($activity->subject_type) : null),
                    $activity->subject_id,
                    $activity->subject_label,
                    $activity->ip_address,
                    $device['browser'],
                    $device['platform'],
                    $activity->origin,
                    $activity->request_id,
                    implode(', ', array_keys((array) ($changes['attributes'] ?? []))),
                    $activity->description,
                ]));
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return list<AllowedFilter>
     */
    private function filters(): array
    {
        return [
            AllowedFilter::callback('q', function (Builder $query, mixed $value): void {
                $term = trim(is_array($value) ? implode(' ', $value) : (string) $value);

                if ($term !== '') {
                    $query->search(mb_substr($term, 0, 120));
                }
            }),
            AllowedFilter::callback('category', function (Builder $query, mixed $value): void {
                $values = $this->listValue($value, array_column(AuditCategory::cases(), 'value'));

                if ($values !== []) {
                    $query->forCategory($values);
                }
            }),
            AllowedFilter::callback('severity', function (Builder $query, mixed $value): void {
                $values = $this->listValue($value, array_column(AuditSeverity::cases(), 'value'));

                if ($values !== []) {
                    $query->whereIn('severity', $values);
                }
            }),
            AllowedFilter::callback('event', function (Builder $query, mixed $value): void {
                $values = $this->listValue($value);

                if ($values !== []) {
                    $query->whereIn('event', $values);
                }
            }),
            AllowedFilter::callback('causer', function (Builder $query, mixed $value): void {
                if (is_numeric($value)) {
                    $query->where('causer_id', (int) $value)->whereNotNull('causer_type');
                }
            }),
            AllowedFilter::callback('subject', function (Builder $query, mixed $value): void {
                [$alias, $id] = array_pad(explode(':', (string) $value, 2), 2, null);

                $query->forSubjectAlias((string) $alias, $id);
            }),
            AllowedFilter::callback('involving', function (Builder $query, mixed $value): void {
                if (is_numeric($value)) {
                    $query->where(function (Builder $query) use ($value): void {
                        $query->where(fn (Builder $q) => $q->whereNotNull('causer_type')->where('causer_id', (int) $value))
                            ->orWhere(fn (Builder $q) => $q->where('subject_type', Activity::classFor('user'))->where('subject_id', (int) $value));
                    });
                }
            }),
            AllowedFilter::exact('ip', 'ip_address'),
            AllowedFilter::exact('request_id'),
            AllowedFilter::exact('origin'),
            AllowedFilter::callback('from', function (Builder $query, mixed $value): void {
                $query->between($this->date($value, endOfDay: false), null);
            }),
            AllowedFilter::callback('to', function (Builder $query, mixed $value): void {
                $query->between(null, $this->date($value, endOfDay: true));
            }),
        ];
    }

    /**
     * @param  list<string>|null  $allowed
     * @return list<string>
     */
    private function listValue(mixed $value, ?array $allowed = null): array
    {
        $raw = is_array($value) ? $value : explode(',', (string) $value);

        return collect($raw)
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '' && ($allowed === null || in_array($item, $allowed, true)))
            ->unique()
            ->take(50)
            ->values()
            ->all();
    }

    /**
     * A bare date (2026-10-11) covers the whole day; a full timestamp is used as is.
     */
    private function date(mixed $value, bool $endOfDay): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            $date = $endOfDay ? $date->endOfDay() : $date->startOfDay();
        }

        return $date->utc()->toDateTimeString();
    }

    /**
     * Spreadsheet apps run cells that start with these characters as formulas.
     */
    private static function csvSafe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return Str::startsWith($value, ['=', '+', '-', '@', "\t", "\r"]) ? "'".$value : $value;
    }
}
