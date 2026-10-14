<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditCategory;
use App\Audit\AuditSeverity;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Support\Query\BuildsApiListQuery;
use App\Support\Stats\DailyBuckets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The signed-in user's own sign-in, security and account history, plus
 * changes made to their user record. Admin-only categories never show here.
 */
class ProfileActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $category = (string) $request->query('category', '');

        $page = $this->personal($user)
            ->when(
                in_array($category, $this->categories(), true),
                fn (Builder $query) => $query->where('log_name', $category),
            )
            ->latest('id')
            ->paginate(BuildsApiListQuery::perPage($request, 15, 50))
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

    public function stats(Request $request, ApplicationSettings $settings): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $buckets = DailyBuckets::fromRequest($request);

        $latest = (int) Activity::query()->max('id');

        $data = Cache::remember($buckets->cacheKey('profile:activity-stats:'.$user->getKey().':'.$latest), 60, function () use ($buckets, $user): array {
            $severities = array_map(fn (AuditSeverity $severity): string => $severity->value, AuditSeverity::cases());

            return [
                ...$buckets->meta(),
                'daily' => $buckets->count($this->personal($user), 'log_name', $this->categories()),
                'severity_totals' => DailyBuckets::totals(
                    $buckets->count($this->personal($user), 'severity', $severities),
                    $severities,
                ),
            ];
        });

        return $this->apiSuccess('Activity stats retrieved successfully.', [...$data, 'retention_days' => $settings->auditRetentionDays()]);
    }

    /**
     * @return Builder<Activity>
     */
    private function personal(User $user): Builder
    {
        $personal = array_map(fn (AuditCategory $category): string => $category->value, AuditCategory::personal());

        return Activity::query()
            ->involving($user)
            ->where(function (Builder $query) use ($personal, $user): void {
                $query->whereIn('log_name', $personal)
                    ->orWhere(fn (Builder $q) => $q->where('log_name', AuditCategory::Users->value)
                        ->where('subject_type', $user->getMorphClass())
                        ->where('subject_id', $user->getKey()));
            });
    }

    /**
     * @return list<string>
     */
    private function categories(): array
    {
        return [
            ...array_map(fn (AuditCategory $category): string => $category->value, AuditCategory::personal()),
            AuditCategory::Users->value,
        ];
    }
}
