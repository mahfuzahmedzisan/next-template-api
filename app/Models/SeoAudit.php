<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One SEO health scan of the public site.
 *
 * @property array<string, mixed>|null $summary
 */
#[Fillable([
    'status', 'trigger', 'triggered_by', 'site_url', 'environment', 'include_noindex', 'score',
    'pages_total', 'pages_scanned', 'pages_failed', 'checks_passed', 'checks_failed',
    'summary', 'error', 'started_at', 'finished_at',
])]
class SeoAudit extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'include_noindex' => 'boolean',
            'score' => 'integer',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SeoAuditPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(SeoAuditPage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /**
     * Queued or running, and young enough that its job could still be alive.
     *
     * @param  Builder<SeoAudit>  $query
     */
    public function scopeInProgress(Builder $query): void
    {
        $query->whereIn('status', [self::QUEUED, self::RUNNING])
            ->where('created_at', '>=', now()->subSeconds((int) config('seo.audit.unique_for', 1200)));
    }
}
