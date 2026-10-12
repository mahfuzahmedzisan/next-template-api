<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Published blog posts for the public site, read by the Next.js BFF.
 */
class PostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'category' => ['sometimes', 'string', 'max:80'],
        ]);

        $page = Post::query()
            ->seoPublished()
            ->when($validated['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 12));

        return $this->apiSuccess('Posts.', [
            'posts' => PostResource::collection($page->items())->resolve(),
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
        $post = Post::query()->seoPublished()->with('seo')->where('slug', $slug)->first();

        if ($post === null) {
            return ApiResponse::notFound('Post not found.');
        }

        return $this->apiSuccess('Post.', [
            'post' => PostResource::make($post)->detailed()->resolve(),
        ]);
    }
}
