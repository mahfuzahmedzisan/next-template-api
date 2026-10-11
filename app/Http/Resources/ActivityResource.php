<?php

namespace App\Http\Resources;

use App\Audit\AuditEvent;
use App\Models\Activity;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->category();
        $severity = $this->severityLevel();
        $changes = $this->attribute_changes?->toArray() ?? [];

        return [
            'id' => $this->id,
            'category' => $this->log_name,
            'category_label' => $category?->label() ?? Str::headline((string) $this->log_name),
            'event' => $this->event,
            'title' => AuditEvent::tryFrom((string) $this->event)?->label() ?? $this->description,
            'description' => $this->description,
            'severity' => $severity->value,
            'causer' => $this->causer_type === null && $this->causer_label === null ? null : [
                'type' => Activity::aliasFor($this->causer_type),
                'id' => $this->causer_id,
                'label' => $this->causer_label,
            ],
            'subject' => $this->subject_type === null && $this->subject_label === null ? null : [
                'type' => Activity::aliasFor($this->subject_type),
                'id' => $this->subject_id,
                'label' => $this->subject_label,
            ],
            'ip_address' => $this->ip_address,
            'device' => $this->user_agent === null ? null : UserAgent::parse($this->user_agent),
            'origin' => $this->origin,
            'request_id' => $this->request_id,
            'changed_fields' => array_keys((array) ($changes['attributes'] ?? [])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
