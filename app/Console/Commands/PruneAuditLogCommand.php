<?php

namespace App\Console\Commands;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Audit\Support\AuditContext;
use App\Services\ApplicationSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Activitylog\Support\Config;

#[Signature('app:audit-prune {--days= : Override the retention set under Application settings}')]
#[Description('Delete audit log entries older than the retention period')]
class PruneAuditLogCommand extends Command
{
    private const BATCH_SIZE = 5000;

    public function handle(ApplicationSettings $settings): int
    {
        $override = $this->option('days');
        $min = (int) config('audit.retention.min_days');
        $max = (int) config('audit.retention.max_days');

        if ($override !== null && (filter_var($override, FILTER_VALIDATE_INT) === false || (int) $override < $min || (int) $override > $max)) {
            $this->components->error("The days option must be a whole number between {$min} and {$max}.");

            return self::INVALID;
        }

        $days = $override !== null ? (int) $override : $settings->auditRetentionDays();

        AuditContext::markSystem();

        $deleted = $this->pruneInBatches($days);

        if ($deleted > 0) {
            Audit::log(AuditEvent::AuditPruned, properties: [
                'deleted' => $deleted,
                'retention_days' => $days,
            ], subjectLabel: 'Audit log');
        }

        $this->components->info("Deleted {$deleted} audit entries older than {$days} days.");

        return self::SUCCESS;
    }

    /**
     * One DELETE over months of rows holds a long table lock that blocks
     * every request writing an audit entry, so delete a slice at a time.
     */
    private function pruneInBatches(int $days): int
    {
        $model = Config::activityModelInstance();
        $cutOff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = $model::query()
                ->where('created_at', '<', $cutOff)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += $model::query()->whereKey($ids->all())->delete();
            }
        } while ($ids->count() === self::BATCH_SIZE);

        return $deleted;
    }
}
