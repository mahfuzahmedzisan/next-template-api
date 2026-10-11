<?php

namespace App\Audit;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Writes hand-written audit events. Failures are reported, never thrown, so an
 * audit write can't break the action being audited.
 */
final class Audit
{
    /**
     * @param  Model|int|null  $causer  null uses the signed-in user, if any
     * @param  array<string, mixed>  $properties
     * @param  array{old?: array<string, mixed>, attributes?: array<string, mixed>}|null  $changes
     * @param  string|null  $subjectLabel  shown when there is no subject model (e.g. a backup file)
     */
    public static function log(
        AuditEvent $event,
        ?Model $subject = null,
        Model|int|null $causer = null,
        array $properties = [],
        ?array $changes = null,
        ?string $description = null,
        ?string $subjectLabel = null,
    ): ?Activity {
        try {
            $logger = activity($event->category()->value)
                ->event($event->value)
                ->withProperties($properties)
                ->tap(function (Model $activity) use ($event, $subjectLabel): void {
                    $activity->setAttribute('severity', $event->severity()->value);

                    if ($subjectLabel !== null) {
                        $activity->setAttribute('subject_label', mb_substr($subjectLabel, 0, 255));
                    }
                });

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            if (is_int($causer)) {
                $causer = User::query()->find($causer);

                if ($causer === null) {
                    $logger->causedByAnonymous();
                }
            }

            if ($causer instanceof Model) {
                $logger->causedBy($causer);
            }

            if ($changes !== null && $changes !== []) {
                $logger->withChanges($changes);
            }

            $activity = $logger->log($description ?? $event->description());

            return $activity instanceof Activity ? $activity : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
