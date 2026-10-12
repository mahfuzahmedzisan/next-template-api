<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The result for one URL in an audit. `checks` holds every check that applied.
 *
 * @property list<array<string, mixed>>|null $checks
 */
#[Fillable([
    'seo_audit_id', 'kind', 'page_type', 'label', 'url', 'seo_page_id', 'seoable_type', 'seoable_id',
    'indexable', 'status_code', 'score', 'passed', 'failed', 'ttfb_ms', 'html_bytes', 'checks', 'error',
])]
class SeoAuditPage extends Model
{
    protected function casts(): array
    {
        return [
            'indexable' => 'boolean',
            'status_code' => 'integer',
            'score' => 'integer',
            'passed' => 'integer',
            'failed' => 'integer',
            'ttfb_ms' => 'integer',
            'html_bytes' => 'integer',
            'checks' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SeoAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(SeoAudit::class, 'seo_audit_id');
    }
}
