<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Published projects for the public site, read by the Next.js BFF.
 */
class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'featured' => ['sometimes', 'boolean'],
        ]);

        $page = Project::query()
            ->seoPublished()
            ->when($request->boolean('featured'), fn ($query) => $query->where('featured', true))
            ->orderBy('sort')
            ->orderByDesc('published_at')
            ->paginate((int) ($validated['per_page'] ?? 12));

        return $this->apiSuccess('Projects.', [
            'projects' => ProjectResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $project = Project::query()->seoPublished()->with('seo')->where('slug', $slug)->first();

        if ($project === null) {
            return ApiResponse::notFound('Project not found.');
        }

        return $this->apiSuccess('Project.', [
            'project' => ProjectResource::make($project)->detailed()->resolve(),
        ]);
    }
}
