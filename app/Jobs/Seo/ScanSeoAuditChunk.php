<?php

namespace App\Jobs\Seo;

use App\Models\SeoAudit;
use App\Services\Seo\Audit\SeoAuditRunner;
use App\Support\Seo\SeoQueue;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Scans one chunk of an audit's URLs inside its batch.
 */
class ScanSeoAuditChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    public function __construct(public int $auditId, public array $targets)
    {
        $this->timeout = (int) config('seo.audit.job_timeout', 900);
        $this->onConnection(SeoQueue::connection());
        $this->onQueue(SeoQueue::name());
    }

    public function handle(SeoAuditRunner $runner): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $audit = SeoAudit::query()->find($this->auditId);

        if ($audit === null || $audit->status !== SeoAudit::RUNNING) {
            return;
        }

        $runner->scanTargets($audit, $this->targets, $runner->robots((string) $audit->site_url));
    }
}
