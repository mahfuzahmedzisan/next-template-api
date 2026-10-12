<?php

namespace App\Services\Seo\Audit\Concerns;

use Throwable;

trait BuildsCheckResults
{
    /**
     * @return array{passed: bool, actual: ?string, expected: ?string, message: string}
     */
    private function result(bool $passed, ?string $actual, ?string $expected, string $message): array
    {
        return [
            'passed' => $passed,
            'actual' => $actual === null ? null : mb_substr($actual, 0, 300),
            'expected' => $expected,
            'message' => $message,
        ];
    }

    /**
     * @return array{passed: bool, actual: ?string, expected: ?string, message: string}
     */
    private function present(?string $value, string $expected, string $hint): array
    {
        return $value !== null && $value !== ''
            ? $this->result(true, $value, $expected, 'Present.')
            : $this->result(false, null, $expected, $hint);
    }

    private function shortError(Throwable $exception): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '', 0, 200);
    }

    /**
     * @param  list<string>  $items
     */
    private function sample(array $items, int $limit = 5): string
    {
        $more = count($items) - $limit;

        return implode(', ', array_slice($items, 0, $limit)).($more > 0 ? " and {$more} more" : '');
    }
}
