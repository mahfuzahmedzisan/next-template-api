<?php

namespace App\Audit\Concerns;

use App\Audit\AuditCategory;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Logs created, updated and deleted events with a diff of the changed
 * attributes. Optional properties on the model tune it:
 *
 *  - protected array $auditInclude        attributes to log (default: $fillable)
 *  - protected array $auditExclude        attributes never logged
 *  - protected array $auditIgnoreChanges  changes that alone don't create an entry
 *  - protected AuditCategory $auditCategory  (default: System)
 *
 * Secrets in config('activitylog.default_except_attributes') are always
 * excluded, and keys matching config('audit.redact') are masked.
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        $name = $this->auditSubjectName();

        $options = LogOptions::defaults()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName($this->auditCategory())
            ->logExcept($this->auditSetting('auditExclude'))
            ->dontLogIfAttributesChangedOnly(['updated_at', ...$this->auditSetting('auditIgnoreChanges')])
            ->setDescriptionForEvent(static fn (string $event): string => "{$name} {$event}");

        $include = $this->auditSetting('auditInclude');

        return $include === [] ? $options->logFillable() : $options->logOnly($include);
    }

    public function auditCategory(): AuditCategory
    {
        $category = property_exists($this, 'auditCategory') ? $this->auditCategory : null;

        return $category instanceof AuditCategory ? $category : AuditCategory::System;
    }

    /**
     * Snapshot shown in the audit log, kept even after the record is deleted.
     */
    public function auditLabel(): string
    {
        foreach (['title', 'name'] as $attribute) {
            $value = $this->getAttributeFromArray($attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return $this->auditSubjectName().' #'.$this->getKey();
    }

    public function auditSubjectName(): string
    {
        return Str::ucfirst(Str::lower(Str::headline(class_basename($this))));
    }

    /**
     * @return list<string>
     */
    private function auditSetting(string $property): array
    {
        if (! property_exists($this, $property)) {
            return [];
        }

        return array_values((array) $this->{$property});
    }
}
