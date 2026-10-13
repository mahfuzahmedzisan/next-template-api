<?php

namespace App\Models;

use App\Audit\AuditCategory;
use App\Audit\Concerns\Auditable;
use App\Contracts\Scout\DefinesSearchIndex;
use App\Enums\MediaKind;
use App\Enums\VideoStatus;
use App\Models\Concerns\ConfiguresScoutSearch;
use App\Support\Scout\SearchIndexDefinition;
use App\Support\Scout\SearchIndexField;
use Database\Factories\MediaItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property VideoStatus $status
 * @property MediaKind $kind
 * @property list<string>|null $keywords
 */
#[Fillable([
    'ulid',
    'title',
    'slug',
    'kind',
    'alt',
    'keywords',
    'search_text',
    'status',
    'progress',
    'error_message',
    'mime',
    'extension',
    'size',
    'duration_seconds',
    'width',
    'height',
    'hls_path',
    'tus_id',
    'uploaded_by',
])]
class MediaItem extends Model implements DefinesSearchIndex, HasMedia
{
    /** @use HasFactory<MediaItemFactory> */
    use Auditable, ConfiguresScoutSearch, HasFactory, InteractsWithMedia;

    protected AuditCategory $auditCategory = AuditCategory::Media;

    /**
     * Only what an admin edits. Upload and transcoding progress is audited
     * through dedicated media events instead of every status save.
     *
     * @var list<string>
     */
    protected array $auditInclude = ['title', 'slug', 'kind', 'alt', 'keywords'];

    protected $attributes = [
        'status' => 'awaiting_upload',
        'progress' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (MediaItem $item): void {
            $item->search_text = $item->compiledSearchText();
        });

        static::creating(function (MediaItem $item): void {
            if (! $item->ulid) {
                $item->ulid = (string) Str::ulid();
            }
        });

        static::deleting(function (MediaItem $item): void {
            if ($item->ulid) {
                Storage::disk('public')->deleteDirectory('media/'.$item->ulid);
                Storage::disk('public')->deleteDirectory('videos/'.$item->ulid);
            }

            if ($item->tus_id) {
                $directory = rtrim((string) config('media-hls.upload_dir'), DIRECTORY_SEPARATOR);
                @unlink($directory.DIRECTORY_SEPARATOR.$item->tus_id);
                @unlink($directory.DIRECTORY_SEPARATOR.$item->tus_id.'.info');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'keywords' => 'array',
            'status' => VideoStatus::class,
            'progress' => 'integer',
            'size' => 'integer',
            'duration_seconds' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('source')
            ->singleFile()
            ->useDisk('public');

        $this->addMediaCollection('poster')
            ->singleFile()
            ->useDisk('public');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        return static::nextSlugs($title, 1, $ignoreId)[0];
    }

    /**
     * Next free slugs for one shared name.
     * "New image" becomes new-image, then new-image-1, new-image-2.
     * A slug already stored is skipped and the count continues.
     *
     * @return list<string>
     */
    public static function nextSlugs(string $title, int $count, ?int $ignoreId = null): array
    {
        $count = max(1, $count);
        $base = Str::slug($title) ?: 'media';
        $existing = static::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where(function ($query) use ($base): void {
                $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%');
            })
            ->pluck('slug');

        $taken = [];

        foreach ($existing as $slug) {
            if ($slug === $base) {
                $taken[0] = true;

                continue;
            }

            if (preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', (string) $slug, $matches) === 1) {
                $taken[(int) $matches[1]] = true;
            }
        }

        $slugs = [];
        $index = 0;

        while (count($slugs) < $count) {
            if (! isset($taken[$index])) {
                $slugs[] = $index === 0 ? $base : $base.'-'.$index;
                $taken[$index] = true;
            }

            $index++;
        }

        return $slugs;
    }

    /**
     * @param  list<string>  $keywords
     */
    public static function normalizeKeywords(array $keywords): array
    {
        $clean = [];

        foreach ($keywords as $keyword) {
            $value = trim($keyword);

            if ($value === '') {
                continue;
            }

            $clean[mb_strtolower($value)] = $value;
        }

        return array_values($clean);
    }

    public function hlsDirectory(): string
    {
        return 'media/'.$this->ulid.'/hls';
    }

    public function streamUrl(): ?string
    {
        if (! $this->kind->streamsAsHls() || $this->status !== VideoStatus::Ready || ! $this->hls_path) {
            return null;
        }

        return Storage::disk('public')->url($this->hls_path);
    }

    public function fileUrl(): ?string
    {
        if ($this->kind->streamsAsHls()) {
            return $this->streamUrl();
        }

        $url = $this->getFirstMediaUrl('source');

        return $url !== '' ? $url : null;
    }

    public function posterUrl(): ?string
    {
        $url = $this->getFirstMediaUrl('poster');

        return $url !== '' ? $url : null;
    }

    public function compiledSearchText(): string
    {
        $keywords = is_array($this->keywords) ? implode(' ', $this->keywords) : '';
        $path = $this->getFirstMedia('source')?->getPathRelativeToRoot() ?? '';
        $text = trim(implode(' ', array_filter([
            $this->title,
            $this->slug,
            $this->alt,
            $keywords,
            $path,
            $this->fileUrl(),
        ])));

        return mb_strtolower($text);
    }

    public static function searchIndexDefinition(): SearchIndexDefinition
    {
        return new SearchIndexDefinition([
            SearchIndexField::string('title'),
            SearchIndexField::string('slug'),
            SearchIndexField::string('url'),
            SearchIndexField::string('keywords'),
            SearchIndexField::string('kind', filterable: true, searchable: false),
            SearchIndexField::int64('created_at', sortable: true),
        ]);
    }

    /**
     * @param  Builder<MediaItem>  $query
     * @return Builder<MediaItem>
     */
    protected function makeAllSearchableUsing($query)
    {
        return $query->with('media');
    }

    /**
     * @return array<string, mixed>
     */
    public function searchableDocument(): array
    {
        $keywords = is_array($this->keywords) ? implode(' ', $this->keywords) : '';
        $path = $this->getFirstMedia('source')?->getPathRelativeToRoot() ?? '';

        return [
            'title' => (string) $this->title,
            'slug' => (string) $this->slug,
            'url' => $path !== '' ? $path : (string) ($this->fileUrl() ?? ''),
            'keywords' => $keywords,
            'kind' => $this->kind instanceof MediaKind ? $this->kind->value : (string) $this->kind,
            'created_at' => $this->created_at?->getTimestamp() ?? 0,
        ];
    }
}
