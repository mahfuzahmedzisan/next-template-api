# Database backups

Database only. Media files are on the storage volume and are not part of the
archive.

| Piece | Where |
| --- | --- |
| Create | `spatie/laravel-backup` (`backup:run --only-db`) |
| Restore | `wnx/laravel-backup-restore` (`backup:restore`) |
| Disk | `backups` → `storage/app/private/backups/<BACKUP_NAME>/` |
| Queue | `backups`, one Supervisor process (`laravel-backup-worker`) |
| Status + lock | The cache (Redis), keys `backups:operation` and `backups:lock` |

## Schedule and retention

`routes/console.php`, all in `BACKUP_SCHEDULE_TIMEZONE` (default Asia/Dhaka):

- 00:00 `app:backup --kind=scheduled`
- 00:30 `backup:clean`: deletes anything older than 14 days. The newest
  backup is never deleted, even when it is older. `BACKUP_MAX_MB` (default
  5000) also removes the oldest archives once the folder grows past it.
- 01:00 `backup:monitor`: unhealthy when the newest backup is older than a
  day. Mail only goes out when `BACKUP_NOTIFICATION_EMAIL` is set.

Archive names show where they came from: `scheduled-…`, `manual-…`
("Backup now" or `php artisan app:backup --kind=manual`) and `pre-restore-…`.

## Restore from the admin screen

Admin → Application → Database backup → Restore. It needs the account
password and the word RESTORE. The job then:

1. Writes a `pre-restore-<time>.zip` safety backup of the current database.
2. Copies audit log entries newer than the backup to
   `storage/app/private/audit-restore/` (see `docs/audit.md`).
3. Runs `backup:restore --reset`, which drops every table and imports the dump.
4. Runs `migrate --force`, in case the dump predates newer migrations, then
   puts the copied audit entries back.
5. Reimports search (`scout:import-all`). A failure here is only logged.

Everything is replaced, including users and `personal_access_tokens`, so
whoever started the restore is usually signed out. Sign in with the password
that was valid when the backup was taken.

Restore refuses to start when the cache driver is `database`/`array`/`null`
or the queue driver is `database`/`sync`/`null`: those would be wiped or would
run inside the HTTP request.

## Restore from the command line

Inside the container (`www-data`):

```bash
php artisan backup:list
php artisan backup:restore --disk=backups --backup=mahfuz-ahmed-api/<file>.zip --reset
php artisan migrate --force
php artisan scout:import-all
```

`--backup=latest` picks the newest archive. Encrypted archives use
`BACKUP_ARCHIVE_PASSWORD`, or pass `--password=`.

## When a restore fails halfway

`--reset` drops the tables before the import. If the import then fails, the
database can be empty or partial. The status message on the admin screen
names the safety backup; restore it from the command line as above. If you
were signed out and cannot sign in, that is the only way back.

## Downloading

The Download button asks for the password, then the API hands the BFF a
one-time token that expires after five minutes. The archive streams through
the BFF; the browser never talks to the API.

## Local development

spatie does not support Windows servers, and the dump needs `sqlite3` (SQLite)
or `mysqldump` + `mysql` (MySQL), plus `gzip`, on PATH. Without them the admin
screen shows which binary is missing and keeps the buttons disabled. The
feature tests replace `App\Services\Backups\BackupRunner`, so they run anywhere.

## Postgres later

The configuration follows `DB_CONNECTION`. Moving to Postgres 18 needs
`pdo_pgsql` in the build stage and `postgresql-client-18` (from the PGDG
apt repository) in the production stage, so `pg_dump` and `psql` exist.
