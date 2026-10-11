<?php

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @mixin Activity
 */
class ActivityDetailResource extends ActivityResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $changes = $this->attribute_changes?->toArray() ?? [];
        $old = (array) ($changes['old'] ?? []);
        $new = (array) ($changes['attributes'] ?? []);

        return [
            ...parent::toArray($request),
            'user_agent' => $this->user_agent,
            'changes' => collect(array_unique([...array_keys($new), ...array_keys($old)]))
                ->map(fn (string $field): array => [
                    'field' => $field,
                    'label' => Str::headline($field),
                    'old' => $old[$field] ?? null,
                    'new' => $new[$field] ?? null,
                ])
                ->values()
                ->all(),
            'properties' => $this->properties?->toArray() ?? [],
            'related_count' => $this->request_id === null ? 0 : Activity::query()
                ->where('request_id', $this->request_id)
                ->whereKeyNot($this->id)
                ->count(),
        ];
    }
}
