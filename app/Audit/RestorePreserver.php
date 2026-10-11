<?php

namespace App\Audit;

use App\Models\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A restore replaces the whole database, audit log included. The entries
 * written after the backup was taken (the restore request among them) are
 * copied to disk first and put back once the restored schema is migrated.
 */
class RestorePreserver
{
    private const DIRECTORY = 'audit-restore';

    /** Rows a few minutes older than the archive are kept too; replay skips ids the dump already has. */
    private const OVERLAP_MINUTES = 5;

    private const CHUNK = 500;

    /**
     * Returns the snapshot path, or null when there is nothing to keep.
     */
    public function capture(Carbon $since): ?string
    {
        $table = $this->table();

        if (! Schema::hasTable($table)) {
            return null;
        }

        $path = self::DIRECTORY.'/'.Str::uuid().'.ndjson';
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);

        $handle = fopen($disk->path($path), 'wb');

        if ($handle === false) {
            throw new RuntimeException('Could not write the audit snapshot before the restore.');
        }

        $count = 0;

        try {
            DB::table($table)
                ->where('created_at', '>=', $since->copy()->setTimezone(config('app.timezone'))->subMinutes(self::OVERLAP_MINUTES))
                ->lazyById(self::CHUNK)
                ->each(function (object $row) use ($handle, &$count): void {
                    fwrite($handle, json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
                    $count++;
                });
        } finally {
            fclose($handle);
        }

        if ($count === 0) {
            $disk->delete($path);

            return null;
        }

        return $path;
    }

    /**
     * Inserts the captured rows the restored database doesn't already have.
     * Returns how many were put back. The snapshot is deleted afterwards.
     */
    public function replay(?string $path): int
    {
        if ($path === null) {
            return 0;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return 0;
        }

        $table = $this->table();
        $columns = array_flip(Schema::getColumnListing($table));
        $handle = fopen($disk->path($path), 'rb');

        if ($handle === false) {
            return 0;
        }

        $inserted = 0;
        $batch = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $batch[] = array_intersect_key(json_decode($line, true, flags: JSON_THROW_ON_ERROR), $columns);

                if (count($batch) >= self::CHUNK) {
                    $inserted += $this->insertMissing($table, $batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $inserted += $this->insertMissing($table, $batch);
            }
        } finally {
            fclose($handle);
        }

        $this->resetSequence($table);
        $disk->delete($path);

        return $inserted;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertMissing(string $table, array $rows): int
    {
        $existing = DB::table($table)
            ->whereIn('id', array_column($rows, 'id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $missing = array_values(array_filter(
            $rows,
            fn (array $row): bool => ! $existing->has((int) ($row['id'] ?? 0)),
        ));

        if ($missing !== []) {
            DB::table($table)->insert($missing);
        }

        return count($missing);
    }

    private function resetSequence(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1))",
            [$table],
        );
    }

    private function table(): string
    {
        return (new Activity)->getTable();
    }
}
