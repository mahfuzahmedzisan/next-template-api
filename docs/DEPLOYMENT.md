# Deploying the API (Coolify + Docker)

The image is rebuilt from scratch on every deploy, so anything written inside
the container is gone the moment it ships. The `storage` directory must
therefore live outside the image.

## 1. Authentication (Sanctum API tokens)

The API issues Sanctum personal access tokens to the Next.js BFF. Tokens are
SHA-256 hashed in the `personal_access_tokens` table, so they survive a
rebuild without any signing keys or OAuth clients.

- Login issues a token that expires after 12 hours, or 30 days with
  "remember me". There is no refresh token; an expired token returns `401`
  and the BFF signs the user out.
- Logout deletes the current token. A password change deletes every other
  token of that user, and a password reset or account deletion deletes all of
  them.
- `sanctum:prune-expired --hours=24` runs daily from the scheduler to clear
  expired rows.
- Every token has a row in `user_devices` (browser, platform, IP, sign-in
  time). The BFF forwards the browser IP and user agent as `X-Client-IP` and
  `X-Client-User-Agent`; they are only read after `EnsureTrustedBff` passes.
  Revoking a device deletes its token but keeps the row as history.
  `model:prune` runs daily and deletes rows revoked or expired more than 30
  days ago, so the scheduler must be running.
- Optional: set `SANCTUM_TOKEN_PREFIX` (for example `mak_`) so leaked tokens
  are recognisable by secret scanners. Changing it only affects new tokens.

Rotating `APP_KEY` does not sign anyone out, but it does invalidate in-flight
two-factor challenge tokens (they are sealed with `Crypt`).

## 2. Persistent storage volume (required)

Uploaded media is written to the `public` disk, which resolves to
`storage/app/public`. That is inside the container. Without a volume, every
deploy deletes the entire media library.

In Coolify, add a persistent volume mounted at `/var/www/storage`.

Because the volume shadows the directory baked into the image, the container
needs its skeleton recreated on boot; `docker/entrypoint.sh` handles that along
with `storage:link`.

## 3. Database

`docker/entrypoint.sh` runs `php artisan migrate --force` on every boot, so a
new table is created before Nginx serves traffic. A missing `videos` or
`media` table shows up on the Vercel admin as “Server Error”.

Never run `migrate:fresh` against production. It truncates
`personal_access_tokens` (signing everyone out) along with everything else.

## 4. Redis (required for cache + queues)

This image expects an **external** Redis (Coolify Redis resource or managed
host). The PHP `redis` extension is already enabled in the Dockerfile. Do not
run Redis inside the app container.

Set in Coolify (or `.env`):

```env
REDIS_CLIENT=phpredis
REDIS_URL=redis://default:PASSWORD@HOST:PORT/0
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

`REDIS_URL` alone is enough; `REDIS_HOST` / `REDIS_PASSWORD` / `REDIS_PORT` are
fallbacks when the URL is empty. The Supervisor queue worker reads
`QUEUE_CONNECTION` (default `redis` in the image).

API sessions stay on `SESSION_DRIVER=file` (or `database`). Sanctum bearer
tokens do not use sessions.

## Configuration caching

`docker/entrypoint.sh` caches config and routes at container start rather than
at image build. This matters: Coolify injects environment variables into the
running container, not necessarily into the build, so caching during the build
can bake `.env.example` placeholders into `bootstrap/cache/config.php` where
they silently override the real values.

## Verifying a deploy did not sign everyone out

Stay logged in to the admin panel, deploy, then reload. If you land back on the
login page, check that the database volume is intact and that no deploy step
ran `migrate:fresh` or truncated `personal_access_tokens`.

## 5. Search (optional at runtime)

Search engines stay **outside** this image (Typesense, Meilisearch, Algolia, or
Turbopuffer). `SCOUT_DRIVER=collection` or an unreachable engine still serves
admin lists through Eloquent `LIKE`.

Do not run imports from the container entrypoint. After setting `SCOUT_DRIVER`
and that engine's credentials in Coolify, run once:

```bash
php artisan scout:import-all --fresh
```

Use `--queue` when Redis workers are up and the catalog is large. Switching
engines is the same command against the new driver. See `docs/scout.md`.

## 6. Database backups

spatie/laravel-backup writes a gzipped dump, zipped, to
`storage/app/private/backups/<BACKUP_NAME>/` every night at 00:00 Asia/Dhaka
and keeps 14 days. The archives sit on the same storage volume as the media
(section 2), so that volume is required for backups too.

- The image installs the official MySQL 8.4 client (`mysqldump`, `mysql`).
  The `laravel-backup-worker` Supervisor program consumes the `backups`
  queue, one job at a time.
- Set `BACKUP_NAME` once and leave it. Optional: `BACKUP_ARCHIVE_PASSWORD`
  (AES-256 archives) and `BACKUP_NOTIFICATION_EMAIL` (failure mail).
- The database user needs `SELECT`, `SHOW VIEW`, `TRIGGER` and `LOCK TABLES`
  for the dump, and `DROP`/`CREATE`/`INSERT`/`ALTER` for a restore.

Restore, the safety backup and recovery steps are in `docs/backups.md`.

## 7. Audit log

spatie/laravel-activitylog writes every audited action to the `activity_log`
table. Run `php artisan migrate --force` once after deploying this release.

- Retention is set by an admin under Application settings > General
  (30 to 730 days, 180 by default). `app:audit-prune` deletes older entries
  every night at `AUDIT_PRUNE_TIME` (`02:00` Asia/Dhaka by default), so the
  scheduler container must be running.
- Live updates on the audit screen are a queued broadcast on the `default`
  queue, which the main worker already consumes. Set `AUDIT_BROADCAST=false`
  to turn them off. `ACTIVITYLOG_ENABLED=false` stops all audit writes.
- A restore keeps audit entries written after the backup was taken. They are
  parked in `storage/app/private/audit-restore/` during the restore and
  removed once replayed.

Details, the event catalogue and how to audit a new model are in
`docs/audit.md`.
