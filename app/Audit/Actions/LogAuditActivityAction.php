<?php

namespace App\Audit\Actions;

use App\Audit\AuditEvent;
use App\Audit\AuditSeverity;
use App\Audit\Support\AuditContext;
use App\Audit\Support\Redactor;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Masks secrets and stamps every activity with its severity, readable labels
 * for the causer and subject, and the request context.
 */
class LogAuditActivityAction extends LogActivityAction
{
    protected function transformChanges(Model $activity): void
    {
        $activity->setAttribute('attribute_changes', collect(
            Redactor::redact($activity->getAttribute('attribute_changes')?->toArray() ?? []),
        ));

        $activity->setAttribute('properties', collect(
            Redactor::redact($activity->getAttribute('properties')?->toArray() ?? []),
        ));
    }

    protected function beforeActivityLogged(Model $activity): void
    {
        parent::beforeActivityLogged($activity);

        $activity->setAttribute('severity', $activity->getAttribute('severity') ?: $this->severityFor($activity->getAttribute('event')));
        $activity->setAttribute('causer_label', $activity->getAttribute('causer_label') ?? $this->label($activity->getAttribute('causer')));
        $activity->setAttribute('subject_label', $activity->getAttribute('subject_label') ?? $this->label($activity->getAttribute('subject')));
        $activity->setAttribute('ip_address', $activity->getAttribute('ip_address') ?? AuditContext::ip());
        $activity->setAttribute('user_agent', $activity->getAttribute('user_agent') ?? AuditContext::userAgent());
        $activity->setAttribute('request_id', $activity->getAttribute('request_id') ?? AuditContext::requestId());
        $activity->setAttribute('origin', $activity->getAttribute('origin') ?? AuditContext::origin());
    }

    private function severityFor(?string $event): string
    {
        $known = $event === null ? null : AuditEvent::tryFrom($event);

        if ($known !== null) {
            return $known->severity()->value;
        }

        return $event === ActivityEvent::Deleted->value
            ? AuditSeverity::Notice->value
            : AuditSeverity::Info->value;
    }

    private function label(mixed $model): ?string
    {
        if (! $model instanceof Model) {
            return null;
        }

        $label = method_exists($model, 'auditLabel')
            ? $model->auditLabel()
            : ($model->getAttribute('name') ?? $model->getAttribute('title') ?? class_basename($model).' #'.$model->getKey());

        return is_string($label) ? mb_substr($label, 0, 255) : null;
    }
}
