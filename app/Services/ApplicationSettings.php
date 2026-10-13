<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use App\Services\Brand\FaviconSet;
use App\Support\MediaUrl;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ApplicationSettings
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /** @var array{default: mixed, smtp: mixed, from: mixed}|null */
    private ?array $originalMail = null;

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $rows = $this->rows($group);

        return array_key_exists($key, $rows) ? $rows[$key] : $default;
    }

    public function put(string $group, string $key, mixed $value): void
    {
        ApplicationSetting::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value],
        );

        unset($this->cache[$group]);
    }

    public function forget(string $group, string $key): void
    {
        ApplicationSetting::query()->where('group', $group)->where('key', $key)->delete();
        unset($this->cache[$group]);
    }

    /**
     * Drops the in-memory copy. Long-running queue workers share this
     * singleton across jobs, so jobs call this before reading settings.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    public function registrationEnabled(): bool
    {
        $value = $this->get('general', 'registration_enabled');

        return $value === null ? true : (bool) $value;
    }

    /**
     * @return array{name: string, short_name: string, logo_url: ?string, favicon_url: ?string, registration_enabled: bool}
     */
    public function publicGeneral(): array
    {
        $name = $this->text('general', 'name', (string) config('app.name'));
        $short = $this->text('general', 'short_name', $name);

        return [
            'name' => $name,
            'short_name' => $short !== '' ? $short : $name,
            'logo_url' => $this->publicUrl($this->text('general', 'logo_path', '')),
            'favicon_url' => $this->publicUrl($this->text('general', 'favicon_path', '')),
            'registration_enabled' => $this->registrationEnabled(),
        ];
    }

    /**
     * @return array{general: array<string, mixed>, smtp: array<string, mixed>}
     */
    public function adminPayload(): array
    {
        return [
            'general' => [
                ...$this->publicGeneral(),
                'audit_retention_days' => $this->auditRetentionDays(),
            ],
            'smtp' => $this->smtpPayload(),
        ];
    }

    /**
     * Days an audit entry is kept, always inside the configured bounds so a
     * bad stored value can neither wipe recent history nor keep it forever.
     */
    public function auditRetentionDays(): int
    {
        $min = (int) config('audit.retention.min_days', 30);
        $max = (int) config('audit.retention.max_days', 730);
        $default = (int) config('audit.retention.default_days', 180);

        try {
            $value = $this->get('general', 'audit_retention_days');
        } catch (Throwable) {
            return $default;
        }

        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * Database SMTP replaces the env mailer only while it is enabled and has a host.
     * Otherwise the process is put back on the mail config it booted with.
     */
    public function applyMailSettings(): void
    {
        $this->rememberOriginalMail();

        // This singleton outlives requests in queue workers; re-read so an SMTP change applies to the next mail.
        $this->flush();

        try {
            $enabled = $this->get('smtp', 'enabled') === true;
            $host = $this->text('smtp', 'host', '');
        } catch (Throwable) {
            $this->restoreOriginalMail();

            return;
        }

        if (! $enabled || $host === '') {
            $this->restoreOriginalMail();

            return;
        }

        $encryption = $this->text('smtp', 'encryption', 'tls');
        $smtp = is_array($this->originalMail['smtp'] ?? null) ? $this->originalMail['smtp'] : [];

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', array_merge($smtp, [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $host,
            'port' => (int) $this->get('smtp', 'port', 587),
            'username' => $this->text('smtp', 'username', '') ?: null,
            'password' => $this->smtpPassword(),
            'auto_tls' => $encryption !== 'none',
        ]));

        $fromAddress = $this->text('smtp', 'from_address', '');
        if ($fromAddress !== '') {
            Config::set('mail.from', [
                'address' => $fromAddress,
                'name' => $this->text('smtp', 'from_name', (string) config('app.name')),
            ]);
        }
    }

    public function storeBrand(string $kind, UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        $name = $kind.'-'.Str::ulid().'.'.$extension;
        $sanitizer = app(SvgSanitizer::class);

        // Brand files are served from the public disk, so an SVG with script would run on our origin.
        if ($extension === 'svg' || $sanitizer->isSvg($file)) {
            try {
                $clean = $sanitizer->sanitize((string) $file->getRealPath());
            } catch (\RuntimeException) {
                throw ValidationException::withMessages(['file' => 'This SVG could not be cleaned. Export it again without scripts.']);
            }

            $name = $kind.'-'.Str::ulid().'.svg';
            $path = Storage::disk('public')->putFileAs('settings', new File($clean), $name);
            @unlink($clean);
        } else {
            $path = $file->storeAs('settings', $name, 'public');
        }

        if (! is_string($path)) {
            throw ValidationException::withMessages(['file' => 'The file could not be saved.']);
        }

        $previous = $this->text('general', $kind.'_path', '');
        $this->put('general', $kind.'_path', $path);

        if ($previous !== '' && $previous !== $path && self::ownsStoredFile($previous)) {
            Storage::disk('public')->delete($previous);
        }

        if ($kind === 'favicon') {
            $this->syncFaviconSet();
        }
    }

    public function removeBrand(string $kind): void
    {
        $this->assignBrand($kind, null);
    }

    public function assignBrand(string $kind, mixed $value): void
    {
        $field = $kind.'_url';

        if ($value !== null && ! is_string($value)) {
            throw ValidationException::withMessages([
                $field => 'Enter a valid image URL.',
            ]);
        }

        $incoming = is_string($value) ? $value : null;
        $stored = MediaUrl::store($incoming);

        if (is_string($incoming) && trim($incoming) !== '' && $stored === null) {
            throw ValidationException::withMessages([
                $field => 'Enter a valid http or https URL.',
            ]);
        }

        if (is_string($stored) && ! MediaUrl::matchesKinds($stored, ['image'])) {
            throw ValidationException::withMessages([
                $field => 'Choose an image URL.',
            ]);
        }

        $previous = $this->text('general', $kind.'_path', '');

        if ($stored === null) {
            $this->forget('general', $kind.'_path');
        } else {
            $this->put('general', $kind.'_path', $stored);
        }

        if ($previous !== '' && $previous !== $stored && self::ownsStoredFile($previous)) {
            Storage::disk('public')->delete($previous);
        }

        if ($kind === 'favicon' && $previous !== (string) $stored) {
            $this->syncFaviconSet();
        }
    }

    /**
     * Rebuilds favicon.ico and the PNG icons from the current favicon source.
     * The set is dropped when there is no source or GD cannot read it (SVG).
     *
     * @return array<string, string>|null
     */
    public function syncFaviconSet(): ?array
    {
        $previous = $this->get('general', 'favicon_set');
        $source = $this->text('general', 'favicon_path', '');
        $sets = app(FaviconSet::class);
        $set = $source !== '' ? $sets->generate($source) : null;

        if ($set === null) {
            $this->forget('general', 'favicon_set');
        } else {
            $this->put('general', 'favicon_set', $set);
        }

        if (is_array($previous) && $previous !== $set) {
            $sets->delete(array_diff($previous, $set ?? []));
        }

        return $set;
    }

    /**
     * Public URLs of the generated favicon files, or null when the frontend
     * should render them itself.
     *
     * @return array<string, string>|null
     */
    public function faviconSetUrls(): ?array
    {
        $set = $this->get('general', 'favicon_set');

        if (! is_array($set) || ! is_string($set['favicon_ico'] ?? null)) {
            return null;
        }

        return collect($set)
            ->filter(fn ($path) => is_string($path))
            ->map(fn (string $path) => $this->publicUrl($path))
            ->filter()
            ->all();
    }

    public function storeSmtpPassword(string $password): void
    {
        $this->put('smtp', 'password', Crypt::encryptString($password));
    }

    /**
     * @return array<string, mixed>
     */
    private function smtpPayload(): array
    {
        $port = $this->get('smtp', 'port');

        return [
            'enabled' => $this->get('smtp', 'enabled') === true,
            'host' => $this->text('smtp', 'host', ''),
            'port' => is_numeric($port) ? (int) $port : 587,
            'encryption' => $this->text('smtp', 'encryption', 'tls'),
            'username' => $this->text('smtp', 'username', ''),
            'password_set' => $this->text('smtp', 'password', '') !== '',
            'from_address' => $this->text('smtp', 'from_address', ''),
            'from_name' => $this->text('smtp', 'from_name', ''),
        ];
    }

    private function smtpPassword(): ?string
    {
        $stored = $this->text('smtp', 'password', '');
        if ($stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    private function rememberOriginalMail(): void
    {
        if ($this->originalMail !== null) {
            return;
        }

        $this->originalMail = [
            'default' => config('mail.default'),
            'smtp' => config('mail.mailers.smtp'),
            'from' => config('mail.from'),
        ];
    }

    private function restoreOriginalMail(): void
    {
        if ($this->originalMail === null) {
            return;
        }

        Config::set('mail.default', $this->originalMail['default']);
        Config::set('mail.mailers.smtp', $this->originalMail['smtp']);
        Config::set('mail.from', $this->originalMail['from']);
    }

    private function text(string $group, string $key, string $default): string
    {
        $value = $this->get($group, $key);

        return is_string($value) ? $value : $default;
    }

    private function publicUrl(string $path): ?string
    {
        $formatted = MediaUrl::format($path);

        return is_string($formatted) ? $formatted : null;
    }

    private static function ownsStoredFile(string $path): bool
    {
        return ! str_contains($path, '://') && str_starts_with($path, 'settings/');
    }

    /**
     * @return array<string, mixed>
     */
    private function rows(string $group): array
    {
        if (! array_key_exists($group, $this->cache)) {
            $this->cache[$group] = ApplicationSetting::query()
                ->where('group', $group)
                ->pluck('value', 'key')
                ->all();
        }

        return $this->cache[$group];
    }
}
