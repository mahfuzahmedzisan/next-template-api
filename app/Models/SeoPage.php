<?php

namespace App\Models;

use App\Audit\AuditCategory;
use App\Audit\Concerns\Auditable;
use App\Events\SeoInvalidated;
use App\Support\Seo\SeoFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SEO for one static or listing page, keyed by its path ("/", "/about").
 *
 * @property string $path
 * @property string $label
 * @property string $page_type
 * @property bool $is_system
 * @property list<string>|null $keywords
 * @property bool $robots_index
 * @property bool $in_sitemap
 * @property int|null $last_status
 * @property string|null $redirect_to
 * @property Carbon|null $last_checked_at
 */
#[Fillable([
    'path',
    'label',
    'page_type',
    'is_system',
    ...SeoFields::FIELDS,
])]
class SeoPage extends Model
{
    use Auditable;

    protected AuditCategory $auditCategory = AuditCategory::Settings;

    /** @var list<string> */
    protected array $auditIgnoreChanges = ['last_status', 'redirect_to', 'last_checked_at'];

    protected $attributes = [
        'page_type' => 'other',
        'is_system' => false,
        'robots_index' => true,
        'robots_follow' => true,
        'max_image_preview' => 'large',
        'in_sitemap' => true,
        'title_affix_mode' => 'inherit',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => SeoInvalidated::dispatch());
        static::deleted(fn () => SeoInvalidated::dispatch());
    }

    protected function casts(): array
    {
        return [
            ...SeoFields::CASTS,
            'is_system' => 'boolean',
            'last_status' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return 'SEO page '.$this->path;
    }

    /**
     * Only a confirmed 404/410, a redirect, or a canonical pointing elsewhere
     * keeps a page out of the sitemap. Unchecked pages, server errors and
     * timeouts (0) stay in, so a brief outage never drops pages.
     */
    public function statusAllowsSitemap(): bool
    {
        $status = $this->last_status;

        if ($status === null) {
            return true;
        }

        return ! in_array($status, [404, 410], true) && ! ($status >= 300 && $status < 400);
    }
}
