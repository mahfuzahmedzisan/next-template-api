<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\User;
use App\Support\Query\BuildsApiListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $personal = array_map(fn (AuditCategory $category): string => $category->value, AuditCategory::personal());
        $category = (string) $request->query('category', '');

        $page = Activity::query()
            ->involving($user)
            ->where(function (Builder $query) use ($personal, $user): void {
                $query->whereIn('log_name', $personal)
                    ->orWhere(fn (Builder $q) => $q->where('log_name', AuditCategory::Users->value)
                        ->where('subject_type', $user->getMorphClass())
                        ->where('subject_id', $user->getKey()));
            })
            ->when(
                in_array($category, [...$personal, AuditCategory::Users->value], true),
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
}
