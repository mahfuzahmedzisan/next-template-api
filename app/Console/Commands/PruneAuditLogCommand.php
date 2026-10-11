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

        $deleted = Config::cleanActivityLogAction()->execute($days);

        if ($deleted > 0) {
            Audit::log(AuditEvent::AuditPruned, properties: [
                'deleted' => $deleted,
                'retention_days' => $days,
            ], subjectLabel: 'Audit log');
        }

        $this->components->info("Deleted {$deleted} audit entries older than {$days} days.");

        return self::SUCCESS;
    }
}
