<?php

namespace App\Audit\Support;

/**
 * Builds the attribute_changes payload ({old, attributes}) for state that is
 * not an Eloquent model, such as key/value settings.
 */
final class ChangeSet
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{old?: array<string, mixed>, attributes?: array<string, mixed>}
     */
    public static function between(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $previous = $before[$key] ?? null;
            $current = $after[$key] ?? null;

            if ($previous === $current) {
                continue;
            }

            $old[$key] = $previous;
            $new[$key] = $current;
        }

        return $new === [] ? [] : ['old' => $old, 'attributes' => $new];
    }
}
