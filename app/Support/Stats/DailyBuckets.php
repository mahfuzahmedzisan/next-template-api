<?php

namespace App\Support\Stats;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Counts rows per calendar day in the viewer's timezone.
 *
 * SQL groups by quarter hour in the application timezone (portable across
 * MySQL, PostgreSQL and SQLite), then PHP folds those slots into local days
 * and zero-fills the gaps, so the chart always has one point per day.
 */
final class DailyBuckets
{
    /** @var list<int> */
    public const RANGES = [7, 30, 90];

    public const DEFAULT_DAYS = 30;

    /** @var array<string, string> */
    private array $localDates = [];

    public function __construct(
        public readonly int $days,
        public readonly string $timezone,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', Rule::in(self::RANGES)],
            'tz' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $candidates = [$validated['tz'] ?? null, $request->user()?->timezone];
        $timezone = 'UTC';

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, timezone_identifiers_list(), true)) {
                $timezone = $candidate;
                break;
            }
        }

        return new self((int) ($validated['days'] ?? self::DEFAULT_DAYS), $timezone);
    }

    /** First instant of the window: local midnight `days - 1` days ago. */
    public function start(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->startOfDay()->subDays($this->days - 1);
    }

    /**
     * @return list<string> local `Y-m-d` dates, oldest first
     */
    public function dates(): array
    {
        $start = $this->start();

        return array_map(
            fn (int $offset): string => $start->addDays($offset)->format('Y-m-d'),
            range(0, $this->days - 1),
        );
    }

    public function cacheKey(string $prefix): string
    {
        return $prefix.':'.$this->days.':'.$this->timezone;
    }

    /**
     * @return array{days: int, timezone: string, from: string, to: string}
     */
    public function meta(): array
    {
        $dates = $this->dates();

        return [
            'days' => $this->days,
            'timezone' => $this->timezone,
            'from' => $dates[0],
            'to' => $dates[count($dates) - 1],
        ];
    }

    /**
     * Rows per local day, optionally split into series by a SQL expression.
     * Without a split each point is `{date, count}`; with one it is
     * `{date, <series>...}`. Values outside `$series` are ignored.
     *
     * @param  EloquentBuilder<*>|Builder  $query
     * @param  list<string>  $series
     * @param  list<mixed>  $splitBindings
     * @return list<array<string, int|string>>
     */
    public function count(
        BuilderContract $query,
        ?string $split = null,
        array $series = [],
        array $splitBindings = [],
        string $column = 'created_at',
    ): array {
        $base = clone ($query instanceof EloquentBuilder ? $query->toBase() : $query);
        $keys = $split === null ? ['count'] : $series;

        $base->where($column, '>=', $this->start()->setTimezone((string) config('app.timezone')))
            ->selectRaw($this->quarterHourExpression($base, $column).' as bucket')
            ->groupBy('bucket');

        if ($split !== null) {
            // Grouping by the alias keeps bound split expressions valid under ONLY_FULL_GROUP_BY.
            $base->selectRaw($split.' as series', $splitBindings)->groupBy('series');
        }

        $rows = $base->selectRaw('COUNT(*) as aggregate')->get();

        $dates = $this->dates();
        $index = array_flip($dates);
        $points = array_map(fn (string $date): array => ['date' => $date] + array_fill_keys($keys, 0), $dates);

        foreach ($rows as $row) {
            $date = $this->localDate((string) $row->bucket);
            $key = $split === null ? 'count' : (string) $row->series;

            if (! isset($index[$date]) || ! in_array($key, $keys, true)) {
                continue;
            }

            $points[$index[$date]][$key] += (int) $row->aggregate;
        }

        return $points;
    }

    /**
     * Totals per series across the whole window.
     *
     * @param  list<array<string, int|string>>  $points
     * @param  list<string>  $series
     * @return array<string, int>
     */
    public static function totals(array $points, array $series): array
    {
        $totals = array_fill_keys($series, 0);

        foreach ($points as $point) {
            foreach ($series as $key) {
                $totals[$key] += (int) ($point[$key] ?? 0);
            }
        }

        return $totals;
    }

    /**
     * Every real UTC offset is a multiple of 15 minutes (India +5:30, Nepal
     * +5:45), so a quarter-hour slot never straddles a local midnight.
     */
    private function quarterHourExpression(Builder $query, string $column): string
    {
        $column = $query->getGrammar()->wrap($column);

        return match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "CONCAT(DATE_FORMAT({$column}, '%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE({$column}) / 15) * 15, 2, '0'), ':00')",
            'pgsql' => "to_char(date_trunc('hour', {$column}) + floor(extract(minute from {$column}) / 15) * interval '15 minutes', 'YYYY-MM-DD HH24:MI:00')",
            default => "strftime('%Y-%m-%d %H:', {$column}) || printf('%02d', (CAST(strftime('%M', {$column}) AS INTEGER) / 15) * 15) || ':00'",
        };
    }

    private function localDate(string $bucket): string
    {
        return $this->localDates[$bucket] ??= CarbonImmutable::parse($bucket, (string) config('app.timezone'))
            ->setTimezone($this->timezone)
            ->format('Y-m-d');
    }
}
