<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The only place that shells out for backups. Tests replace it, because the
 * dump and import binaries are not available everywhere (spatie does not
 * support Windows servers at all).
 */
class BackupRunner
{
    public function backup(string $filename): void
    {
        $this->call('backup:run', [
            '--only-db' => true,
            '--filename' => $filename,
            '--only-to-disk' => BackupManager::DISK,
            '--timeout' => 3600,
        ], 'The backup failed.');
    }

    public function restore(string $path): void
    {
        $this->call('backup:restore', [
            '--disk' => BackupManager::DISK,
            '--backup' => $path,
            '--connection' => config('database.default'),
            '--reset' => true,
            '--no-interaction' => true,
        ], 'The restore failed.');
    }

    public function migrate(): void
    {
        $this->call('migrate', ['--force' => true], 'Migrations failed after the restore.');
    }

    public function reindexSearch(): void
    {
        $this->call('scout:import-all', [], 'Search reindex failed after the restore.');
    }

    /**
     * Binaries the dump and import need for the default connection.
     *
     * @return list<string>
     */
    public function missingBinaries(): array
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $binaryPath = (string) config("database.connections.{$connection}.dump.dump_binary_path", '');

        $required = match ($driver) {
            'mysql', 'mariadb' => ['mysqldump', 'mysql'],
            'pgsql' => ['pg_dump', 'psql'],
            'sqlite' => ['sqlite3'],
            default => [$driver.'-client'],
        };
        $required[] = 'gzip';

        $finder = new ExecutableFinder;
        $extraDirs = $binaryPath !== '' ? [rtrim($binaryPath, '/\\')] : [];

        return array_values(array_filter(
            $required,
            fn (string $binary): bool => $finder->find($binary, null, $extraDirs) === null,
        ));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function call(string $command, array $parameters, string $fallback): void
    {
        $exitCode = Artisan::call($command, $parameters);

        if ($exitCode !== 0) {
            $output = trim(Artisan::output());

            throw new RuntimeException($output !== '' ? $this->lastLines($output) : $fallback);
        }
    }

    private function lastLines(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: [])));

        return implode(' ', array_slice($lines, -3));
    }
}
