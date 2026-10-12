<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Audit\Support\ChangeSet;
use App\Events\SeoInvalidated;
use App\Http\Controllers\Controller;
use App\Http\Resources\SeoPageResource;
use App\Jobs\Seo\ProbeSeoPath;
use App\Models\SeoPage;
use App\Rules\SafeSiteUrl;
use App\Services\Seo\Audit\SeoCheckCatalog;
use App\Services\Seo\SeoFileGenerator;
use App\Services\Seo\SeoSettings;
use App\Support\ApiResponse;
use App\Support\Seo\SeoFields;
use App\Support\Seo\SeoPath;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Application settings > SEO: global defaults, static pages, content-type
 * defaults and the generated sitemap/robots/llms files.
 */
class SeoController extends Controller
{
    private const SETTING_KEYS = [
        'site_url', 'title_prefix', 'title_suffix', 'default_description', 'default_keywords',
        'default_author', 'default_og_image', 'og_locale', 'twitter_site', 'twitter_creator',
        'robots_disallow', 'robots_extra', 'allow_ai_crawlers', 'llms_enabled',
    ];

    public function __construct(
        private readonly SeoSettings $settings,
        private readonly SeoFileGenerator $files,
    ) {}

    public function index(): JsonResponse
    {
        return $this->apiSuccess('SEO settings.', $this->payload());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $handle = ['nullable', 'string', 'max:16', 'regex:/^@?[A-Za-z0-9_]{1,15}$/'];

        $validated = $request->validate([
            'site_url' => ['sometimes', 'nullable', 'string', 'max:255', new SafeSiteUrl],
            'title_prefix' => ['sometimes', 'nullable', 'string', 'max:120'],
            'title_suffix' => ['sometimes', 'nullable', 'string', 'max:120'],
            'default_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_keywords' => ['sometimes', 'nullable', 'array', 'max:30'],
            'default_keywords.*' => ['string', 'max:60'],
            'default_author' => ['sometimes', 'nullable', 'string', 'max:120'],
            'default_og_image' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'og_locale' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            'twitter_site' => ['sometimes', ...$handle],
            'twitter_creator' => ['sometimes', ...$handle],
            'robots_disallow' => ['sometimes', 'nullable', 'array', 'max:50'],
            'robots_disallow.*' => ['string', 'max:255', 'starts_with:/'],
            'robots_extra' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'allow_ai_crawlers' => ['sometimes', 'boolean'],
            'llms_enabled' => ['sometimes', 'boolean'],
        ]);

        $before = Arr::only($this->settings->all(), self::SETTING_KEYS);
        $siteUrlBefore = $this->settings->siteUrl();

        $this->settings->update(Arr::only($validated, self::SETTING_KEYS));

        $after = Arr::only($this->settings->all(), self::SETTING_KEYS);
        $changes = ChangeSet::between($before, $after);

        if ($changes !== []) {
            Audit::log(AuditEvent::SeoSettingsUpdated, changes: $changes, subjectLabel: 'SEO settings');
            SeoInvalidated::dispatch();
        }

        if ($this->settings->siteUrl() !== $siteUrlBefore) {
            ProbeSeoPath::dispatchForAll();
        }

        return $this->apiSuccess('SEO settings saved.', $this->payload());
    }

    public function storePage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:255'],
            'label' => ['required', 'string', 'max:120'],
            'page_type' => ['required', 'string', Rule::in((array) config('seo.page_types'))],
            ...SeoFields::rules(),
        ]);

        $path = $this->uniquePath($validated['path']);

        $page = SeoPage::query()->create([
            'path' => $path,
            'label' => trim($validated['label']),
            'page_type' => $validated['page_type'],
            ...SeoFields::clean($validated),
        ]);

        ProbeSeoPath::dispatch($page->id);

        return $this->apiCreated('Page added.', [
            'page' => SeoPageResource::make($page)->resolve(),
        ]);
    }

    public function updatePage(Request $request, SeoPage $seoPage): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['sometimes', 'string', 'max:255'],
            'label' => ['sometimes', 'string', 'max:120'],
            'page_type' => ['sometimes', 'string', Rule::in((array) config('seo.page_types'))],
            ...SeoFields::rules(),
        ]);

        $attributes = SeoFields::clean($validated);

        if (array_key_exists('label', $validated)) {
            $attributes['label'] = trim($validated['label']);
        }

        if (array_key_exists('page_type', $validated)) {
            $attributes['page_type'] = $validated['page_type'];
        }

        $pathChanged = false;

        if (array_key_exists('path', $validated)) {
            $path = SeoPath::normalize($validated['path']);

            if ($path !== $seoPage->path) {
                if ($seoPage->is_system) {
                    throw ValidationException::withMessages(['path' => 'The path of a built-in page cannot change.']);
                }

                $attributes['path'] = $this->uniquePath($validated['path'], $seoPage->id);
                $attributes['last_status'] = null;
                $attributes['redirect_to'] = null;
                $attributes['last_checked_at'] = null;
                $pathChanged = true;
            }
        }

        $seoPage->fill(Arr::except($attributes, ['last_status', 'redirect_to', 'last_checked_at']));
        $seoPage->forceFill(Arr::only($attributes, ['last_status', 'redirect_to', 'last_checked_at']));
        $seoPage->save();

        if ($pathChanged) {
            ProbeSeoPath::dispatch($seoPage->id);
        }

        return $this->apiSuccess('Page SEO saved.', [
            'page' => SeoPageResource::make($seoPage->refresh())->resolve(),
        ]);
    }

    public function destroyPage(SeoPage $seoPage): JsonResponse
    {
        if ($seoPage->is_system) {
            return $this->apiUnprocessable('Built-in pages cannot be removed.');
        }

        $seoPage->delete();

        return $this->apiSuccess('Page removed.', $this->payload());
    }

    public function probePage(SeoPage $seoPage): JsonResponse
    {
        ProbeSeoPath::dispatch($seoPage->id);

        return $this->apiSuccess('Check queued.', [
            'page' => SeoPageResource::make($seoPage)->resolve(),
        ], SymfonyResponse::HTTP_ACCEPTED);
    }

    public function updateType(Request $request, string $type): JsonResponse
    {
        if (! array_key_exists($type, (array) config('seo.seoables', []))) {
            return ApiResponse::notFound('Unknown content type.');
        }

        $validated = $request->validate([
            'title_template' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'og_type' => ['nullable', 'string', Rule::in(SeoFields::OG_TYPES)],
            'schema_type' => ['nullable', 'string', Rule::in((array) config('seo.schema_types'))],
            'sitemap_priority' => ['nullable', 'numeric', 'between:0,1'],
            'sitemap_changefreq' => ['nullable', 'string', Rule::in(SeoFields::CHANGE_FREQUENCIES)],
        ]);

        $before = $this->settings->typeTemplate($type);
        $template = array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $validated);

        if (isset($template['sitemap_priority'])) {
            $template['sitemap_priority'] = round((float) $template['sitemap_priority'], 1);
        }

        $this->settings->putTypeTemplate($type, $template);
        $changes = ChangeSet::between($before, $this->settings->typeTemplate($type));

        if ($changes !== []) {
            Audit::log(AuditEvent::SeoTypeDefaultsUpdated, changes: $changes, subjectLabel: 'SEO defaults: '.$type);
            SeoInvalidated::dispatch();
        }

        return $this->apiSuccess('Content-type defaults saved.', $this->payload());
    }

    public function regenerate(string $file): JsonResponse
    {
        $this->files->generate($file);

        Audit::log(AuditEvent::SeoFilesRegenerated, properties: ['file' => $file], subjectLabel: $file === 'all' ? 'SEO files' : $this->files->publicName($file));

        return $this->apiSuccess($file === 'all' ? 'SEO files regenerated.' : 'File regenerated.', [
            'files' => $this->filesPayload(),
        ]);
    }

    public function download(string $file): Response
    {
        $name = $this->files->publicName($file);
        $contents = $this->files->read($name);

        if ($contents === null) {
            abort(SymfonyResponse::HTTP_NOT_FOUND, 'This file is turned off.');
        }

        return response($contents, SymfonyResponse::HTTP_OK, [
            'Content-Type' => $file === 'sitemap' ? 'application/xml; charset=UTF-8' : 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $types = [];

        foreach ((array) config('seo.seoables', []) as $alias => $class) {
            $types[] = [
                'type' => $alias,
                'label' => Str::headline($alias),
                'template' => $this->settings->typeTemplate($alias),
            ];
        }

        return [
            'settings' => $this->settings->adminPayload(),
            'pages' => SeoPageResource::collection(SeoPage::query()->orderByDesc('is_system')->orderBy('path')->get())->resolve(),
            'files' => $this->filesPayload(),
            'types' => $types,
            'options' => [
                'page_types' => (array) config('seo.page_types'),
                'schema_types' => (array) config('seo.schema_types'),
                'og_types' => SeoFields::OG_TYPES,
                'twitter_cards' => SeoFields::TWITTER_CARDS,
                'change_frequencies' => SeoFields::CHANGE_FREQUENCIES,
                'image_previews' => SeoFields::IMAGE_PREVIEW,
                'affix_modes' => SeoFields::AFFIX_MODES,
                'placeholders' => SeoSettings::PLACEHOLDERS,
                'lengths' => collect(['title', 'description'])
                    ->mapWithKeys(fn (string $name) => [$name => array_combine(['min', 'max'], SeoCheckCatalog::range($name))])
                    ->all(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filesPayload(): array
    {
        $meta = $this->settings->files();
        $siteUrl = $this->settings->siteUrl();
        $settings = $this->settings->all();
        $list = [];

        foreach (SeoFileGenerator::FILES as $file) {
            $name = $this->files->publicName($file);
            $list[] = [
                'file' => $file,
                'name' => $name,
                'url' => SeoUrl::join($siteUrl, '/'.$name),
                'enabled' => $file !== 'llms' || $settings['llms_enabled'],
                'generated_at' => $meta[$file]['generated_at'] ?? null,
                'size' => $meta[$file]['size'] ?? null,
                'urls' => $meta[$file]['urls'] ?? null,
                'files' => $meta[$file]['files'] ?? null,
            ];
        }

        return $list;
    }

    private function uniquePath(string $value, ?int $ignoreId = null): string
    {
        $path = SeoPath::normalize($value);

        if ($path === null) {
            throw ValidationException::withMessages(['path' => 'Enter a path such as /about (letters, numbers, - _ . / only).']);
        }

        if (preg_match('#^/(admin|api)(/|$)#', $path) === 1) {
            throw ValidationException::withMessages(['path' => 'Admin and API paths are never indexed.']);
        }

        $taken = SeoPage::query()
            ->where('path', $path)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['path' => 'This path is already registered.']);
        }

        return $path;
    }
}
