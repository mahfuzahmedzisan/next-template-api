# Audit log

Every sign-in, security change, record change, settings change, media and
backup operation is written to the `activity_log` table by
[spatie/laravel-activitylog](https://spatie.be/docs/laravel-activitylog) v5,
extended with a few columns and a small `App\Audit` layer.

| Piece | Where |
| --- | --- |
| Package config | `config/activitylog.php` |
| App config | `config/audit.php` (retention bounds, redaction, subject aliases, broadcast, export cap) |
| Model | `App\Models\Activity` (scopes `search`, `forCategory`, `between`, `forSubjectAlias`, `involving`) |
| Model trait | `App\Audit\Concerns\Auditable` |
| Manual events | `App\Audit\Audit::log()` with an `App\Audit\AuditEvent` case |
| Write pipeline | `App\Audit\Actions\LogAuditActivityAction` (redaction, severity, labels, request context) |
| Request context | `App\Http\Middleware\CaptureAuditContext`, `App\Audit\Support\AuditContext` |
| Admin API | `GET /api/v1/admin/audit`, `/overview`, `/{id}`, `/export` |
| Personal API | `GET /api/v1/profile/activity` |
| Realtime | `App\Events\ActivityLogged` on the private `admin.audit` channel |

## What each row holds

On top of the package columns (`log_name`, `description`, `subject_*`,
`causer_*`, `event`, `attribute_changes`, `properties`):

- `log_name`: the category (`auth`, `security`, `account`, `users`, `media`,
  `settings`, `backups`, `system`).
- `severity`: `info`, `notice`, `warning` or `critical`.
- `causer_label` / `subject_label`: names copied at write time, so a row
  still reads correctly after the user or record is deleted.
- `ip_address`, `user_agent`: the browser's, forwarded by the BFF.
- `request_id`: one UUID per API request (`X-Request-Id`, echoed back in the
  response). Jobs dispatched from a request inherit it via hidden Context, so
  every entry caused by one click can be grouped.
- `origin`: `web`, `queue`, `console` or `system` (the scheduler).

## Auditing a new model

```php
use App\Audit\AuditCategory;
use App\Audit\Concerns\Auditable;

class Invoice extends Model
{
    use Auditable;

    protected AuditCategory $auditCategory = AuditCategory::System;

    // Optional. Defaults to $fillable.
    protected array $auditInclude = ['number', 'status', 'total'];

    // Optional. Never logged.
    protected array $auditExclude = ['internal_notes'];

    // Optional. A save that only touches these creates no entry.
    protected array $auditIgnoreChanges = ['viewed_at'];
}
```

Created, updated and deleted events are then logged with a diff of only the
changed attributes. Add the model to `config('audit.subjects')` to filter by
it in the admin (`filter[subject]=invoice:42`) and show its history panel.
Add a category to `App\Audit\AuditCategory` if none fits.

To skip logging for one operation: `$model->disableLogging()->save()`, or
wrap any code in `activity()->withoutLogging(fn () => ...)`.

## Logging a custom event

1. Add a case to `App\Audit\AuditEvent` and give it a category, severity and
   label in the three `match` blocks.
2. Log it where it happens:

```php
Audit::log(
    AuditEvent::InvoiceSent,
    subject: $invoice,            // optional model
    causer: $user,                // optional; defaults to the signed-in user
    properties: ['to' => $email], // extra context, redacted automatically
    changes: ChangeSet::between($before, $after), // optional diff for non-model state
    subjectLabel: 'Invoice 42',   // when there is no subject model
);
```

`Audit::log()` never throws. A failing write is reported and the action
being audited carries on.

## Secrets

- `config('activitylog.default_except_attributes')` keeps `password`,
  `remember_token` and the two-factor columns out of every model diff.
- Any key containing `password`, `token`, `secret`, `credential`,
  `recovery_code` or `api_key` (`config('audit.redact')`) is stored as
  `[redacted]`, at any depth, in both `attribute_changes` and `properties`.
  Avoid those words in property names that are not secrets.

## Retention

Admins choose 1, 3, 6, 12 or 24 months under Application settings > General
(stored as `general.audit_retention_days`, clamped to 30-730, default 180).
`app:audit-prune` runs nightly at `AUDIT_PRUNE_TIME` in
`AUDIT_PRUNE_TIMEZONE` and logs an `audit.pruned` entry when it deletes
anything. Run it by hand with `php artisan app:audit-prune [--days=90]`.

## Restores

A restore replaces the whole database. `App\Audit\RestorePreserver` copies
the entries written since the backup was taken (5 minutes of overlap) to an
NDJSON file before the import and inserts them again after `migrate`,
skipping ids the dump already has. The restore request itself is therefore
kept, followed by `restore.completed` or `restore.failed`. If replaying fails
the file is left in `storage/app/private/audit-restore/` and the error is in
the log.

## Export

`GET /api/v1/admin/audit/export` takes the same `filter[...]` parameters as
the list and streams a UTF-8 CSV, newest first, capped at
`AUDIT_EXPORT_MAX_ROWS`. Cells that start with `=`, `+`, `-` or `@` are
prefixed with `'` so spreadsheets don't run them. Each export is itself
audited and limited to 5 per minute.

## Events catalogue

See `App\Audit\AuditEvent`. Notable severities:

- critical: account deleted, backup failed, restore requested, completed or failed
- warning: failed or throttled sign-in, rejected two-factor code, two-factor
  turned off, recovery code used, media processing failed, backup deleted or
  downloaded
- notice: password changed or reset, two-factor turned on, devices signed
  out, settings changed, audit export
