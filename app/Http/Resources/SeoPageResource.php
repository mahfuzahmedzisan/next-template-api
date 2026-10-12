<?php

namespace App\Http\Resources;

use App\Models\SeoPage;
use App\Services\Seo\SeoResolver;
use App\Support\Seo\SeoFields;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SeoPage
 */
class SeoPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SeoPage $page */
        $page = $this->resource;
        $resolved = app(SeoResolver::class)->forPage($page);
        $siteUrl = (string) $resolved['identity']['site_url'];

        return [
            'id' => $page->id,
            'path' => $page->path,
            'label' => $page->label,
            'page_type' => $page->page_type,
            'is_system' => $page->is_system,
            'fields' => [
                ...$page->only(SeoFields::FIELDS),
                'og_image_url' => SeoUrl::image($siteUrl, $page->og_image),
                'twitter_image_url' => SeoUrl::image($siteUrl, $page->twitter_image),
            ],
            'status' => [
                'code' => $page->last_status,
                'redirect_to' => $page->redirect_to,
                'checked_at' => $page->last_checked_at?->toIso8601String(),
            ],
            'resolved' => [
                'title' => $resolved['title'],
                'raw_title' => $resolved['raw_title'],
                'description' => $resolved['description'],
                'canonical' => $resolved['canonical'],
                'canonical_base' => $resolved['canonical_base'],
                'self_canonical' => $resolved['self_canonical'],
                'og_image' => $resolved['open_graph']['image'],
                'og_title' => $resolved['open_graph']['title'],
                'og_description' => $resolved['open_graph']['description'],
                'keywords' => $resolved['keywords'],
                'author' => $resolved['author'],
                'robots' => $resolved['robots'],
                'in_sitemap' => $resolved['sitemap']['include'] && $page->statusAllowsSitemap(),
            ],
            'completeness' => $this->completeness($page, $resolved),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Quick on-page checklist from the resolved values (the SEO health audit
     * checks the rendered HTML instead).
     *
     * @param  array<string, mixed>  $resolved
     * @return array{score: int, items: list<array{key: string, label: string, ok: bool}>}
     */
    private function completeness(SeoPage $page, array $resolved): array
    {
        $title = mb_strlen((string) $resolved['title']);
        $description = mb_strlen((string) $resolved['description']);

        $items = [
            ['key' => 'title', 'label' => 'Title 30–60 characters', 'ok' => $title >= 30 && $title <= 60],
            ['key' => 'description', 'label' => 'Description 70–160 characters', 'ok' => $description >= 70 && $description <= 160],
            ['key' => 'custom_title', 'label' => 'Own meta title', 'ok' => filled($page->meta_title)],
            ['key' => 'custom_description', 'label' => 'Own meta description', 'ok' => filled($page->meta_description)],
            ['key' => 'og_image', 'label' => 'Social image set', 'ok' => filled($page->og_image)],
            ['key' => 'keywords', 'label' => 'Keywords', 'ok' => $resolved['keywords'] !== []],
            ['key' => 'canonical', 'label' => 'Canonical points to itself', 'ok' => (bool) $resolved['self_canonical']],
        ];

        if ($page->robots_index) {
            $items[] = ['key' => 'sitemap', 'label' => 'Listed in the sitemap', 'ok' => $resolved['sitemap']['include'] && $page->statusAllowsSitemap()];
        }

        $ok = count(array_filter($items, static fn (array $item) => $item['ok']));

        return [
            'score' => (int) round($ok / count($items) * 100),
            'items' => $items,
        ];
    }
}
