<?php

namespace App\Jobs\Seo;

use App\Models\SeoAudit;
use App\Services\Seo\Audit\SeoAuditRunner;
use App\Support\Seo\SeoQueue;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Scans every indexable page and record of the public site. Small sites are
 * scanned inline; above the batch threshold the URLs are split into chunk
 * jobs and the audit is scored when the batch finishes.
 */
class RunSeoAudit implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public int $uniqueFor;

    public function __construct(public int $auditId)
    {
        $this->timeout = (int) config('seo.audit.job_timeout', 900);
        $this->uniqueFor = (int) config('seo.audit.unique_for', 1200);
        $this->onConnection(SeoQueue::connection());
        $this->onQueue(SeoQueue::name());
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'seo-audit';
    }

    public function handle(SeoAuditRunner $runner): void
    {
        $audit = SeoAudit::query()->find($this->auditId);

        if ($audit === null || $audit->status !== SeoAudit::QUEUED) {
            return;
        }

        $siteUrl = $runner->siteUrl();
        $targets = $runner->targets($audit->include_noindex);
        $development = $runner->isDevelopment($siteUrl);

        $audit->forceFill([
            'status' => SeoAudit::RUNNING,
            'site_url' => $siteUrl,
            'pages_total' => count($targets),
            'started_at' => now(),
        ])->save();

        if ($targets === []) {
            $runner->finalize($audit);

            return;
        }

        if ($development) {
            $runner->warmUp(array_column($targets, 'url'));
        } else {
            $sample = $runner->warmUp([$targets[0]['url']]);
            $development = $runner->isDevelopment($siteUrl, $sample);
        }

        $audit->forceFill(['environment' => $development ? 'development' : 'production'])->save();
        $robots = $runner->robots($siteUrl);

        if (count($targets) <= (int) config('seo.audit.batch_threshold', 25)) {
            $runner->scanTargets($audit, $targets, $robots);
            $runner->finalize($audit);

            return;
        }

        $auditId = $audit->id;
        $jobs = array_map(
            fn (array $chunk) => new ScanSeoAuditChunk($auditId, $chunk),
            array_chunk($targets, max(1, (int) config('seo.audit.chunk_size', 10))),
        );

        Bus::batch($jobs)
            ->name('seo-audit-'.$auditId)
            ->onConnection(SeoQueue::connection())
            ->onQueue(SeoQueue::name())
            ->allowFailures()
            ->finally(function (Batch $batch) use ($auditId): void {
                $audit = SeoAudit::query()->find($auditId);

                if ($audit !== null && $audit->status === SeoAudit::RUNNING) {
                    app(SeoAuditRunner::class)->finalize($audit);
                }
            })
            ->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        $audit = SeoAudit::query()->find($this->auditId);

        if ($audit !== null && $audit->isActive()) {
            app(SeoAuditRunner::class)->fail($audit, $exception?->getMessage() ?: 'The audit job stopped.');
        }
    }
}
