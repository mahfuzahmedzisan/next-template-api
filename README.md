# Next Template API

A Laravel 13 API that powers the companion Next.js starter template. It owns
the database, auth (Sanctum + Fortify), SEO health scanning, media/HLS
processing, scheduled backups, and the audit log. The browser never talks to
this API directly — only the Next.js BFF does, using a shared secret.

This repo is independent and self-contained: nothing here assumes a fixed
path relative to the Next.js app or to Reverb. Clone all three wherever you
like.

## Prerequisites

- PHP 8.4+, Composer
- Node.js (only for `npm run build`, used by the Vite dev script)
- A database: SQLite works out of the box for local dev; MySQL/Postgres for
  production (see `.env.example`)
- Redis — required for cache, queues, and broadcasting
- Optional, depending on which modules you use: a search engine (Meilisearch
  / Typesense / Algolia — `collection` driver needs none), `ffmpeg`/`ffprobe`
  (media transcoding), `mysqldump`/`sqlite3` + `gzip` (backups)

## One-time setup

```bash
composer setup
```

This installs dependencies, copies `.env.example` to `.env` if missing, runs
`php artisan app:install` (generates the app key, runs migrations, and
interactively creates the first admin user), then installs and builds the
frontend assets Vite/Blade needs.

Prefer to do it by hand, or run it non-interactively (e.g. in a container)?

```bash
composer install
cp .env.example .env
php artisan app:install --force   # uses APP_INSTALL_ADMIN_* env vars, or safe defaults
```

### Required environment variables

See `.env.example` — every variable is grouped by concern and commented with
whether it's required or optional. At minimum for local dev you need the
`App`, `Database`, `Session`, `Redis`, `Broadcasting`, and `Frontend / BFF
trust` sections filled in (most already have working local defaults).

## Running the app locally

```bash
composer dev
```

This runs, concurrently: `php artisan serve`, queue listeners for the
default, `seo`, `media`, and `backups` queues, and `npm run dev` (Vite).

Run the companion Next.js app separately (see its own README) and point its
`LARAVEL_API_URL` at this API's `APP_URL`.

## Testing

```bash
composer test
# or directly:
php artisan test
```

Tests run against an in-memory SQLite database (see `phpunit.xml`) and don't
touch your `.env` database.

## Code style

```bash
vendor/bin/pint        # fix
vendor/bin/pint --test # check only
```

## Optional modules

These are independently toggleable by simply not configuring their env vars
/ not running their workers — nothing breaks if you skip them:

- **SEO health scanning** — `SEO_QUEUE_*` vars, needs its own queue worker
- **Media / HLS video** — `ffmpeg`/`ffprobe`, tusd (bundled in the Docker
  image), `MEDIA_DISK`
- **Backups** — `spatie/laravel-backup`, `BACKUP_*` vars, see `docs/backups.md`
- **Audit log** — `spatie/laravel-activitylog`, `ACTIVITYLOG_ENABLED`, see
  `docs/audit.md`
- **Search** — `SCOUT_DRIVER=collection` needs nothing extra (Eloquent LIKE
  fallback); set it to `meilisearch`/`typesense`/`algolia` for production
  and run `php artisan scout:import-all --fresh`

## Deployment

The `Dockerfile` builds a production image intended for Coolify or similar
platforms. It bundles `ffmpeg`, `tusd`, and the backup CLI tools. See inline
comments in the `Dockerfile` and `docs/backups.md` for operational details.

## Using this as a template for a new project

1. Rename `APP_NAME` in `.env.example` / `.env`.
2. Update `BACKUP_NAME` in `.env` if you change it from the default — keep it
   fixed afterwards, since renaming hides existing backup archives.
3. Replace `SEO_SCANNER_UA_TOKEN` / `SEO_SCANNER_USER_AGENT` on both this API
   and the Next.js app (they must match) if you want a non-default token.
4. Review `database/seeders/SeoPageSeeder.php` for any placeholder copy you
   want to replace before first deploy.
