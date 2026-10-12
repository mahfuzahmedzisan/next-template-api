<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Audit\Support\ChangeSet;
use App\Events\SeoInvalidated;
use App\Http\Controllers\Controller;
use App\Services\ApplicationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ApplicationSettingsController extends Controller
{
    public function publicShow(ApplicationSettings $settings): JsonResponse
    {
        return $this->apiSuccess('Application settings.', [
            'settings' => [
                ...$settings->publicGeneral(),
                'favicon_set' => $settings->faviconSetUrls(),
            ],
        ]);
    }

    public function show(ApplicationSettings $settings): JsonResponse
    {
        return $this->apiSuccess('Application settings.', [
            'settings' => $settings->adminPayload(),
        ]);
    }

    public function updateGeneral(Request $request, ApplicationSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'short_name' => ['nullable', 'string', 'max:40'],
            'registration_enabled' => ['required', 'boolean'],
            'audit_retention_days' => [
                'sometimes',
                'integer',
                'between:'.config('audit.retention.min_days').','.config('audit.retention.max_days'),
            ],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_favicon' => ['sometimes', 'boolean'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'favicon_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $before = $settings->adminPayload()['general'];

        $this->putOrForget($settings, 'general', 'name', $validated['name'] ?? null);
        $this->putOrForget($settings, 'general', 'short_name', $validated['short_name'] ?? null);
        $settings->put('general', 'registration_enabled', (bool) $validated['registration_enabled']);

        if (array_key_exists('audit_retention_days', $validated)) {
            $settings->put('general', 'audit_retention_days', (int) $validated['audit_retention_days']);
        }

        if ($request->exists('logo_url')) {
            $settings->assignBrand('logo', $validated['logo_url'] ?? null);
        } elseif ($request->boolean('remove_logo')) {
            $settings->assignBrand('logo', null);
        }

        if ($request->exists('favicon_url')) {
            $settings->assignBrand('favicon', $validated['favicon_url'] ?? null);
        } elseif ($request->boolean('remove_favicon')) {
            $settings->assignBrand('favicon', null);
        }

        $payload = $settings->adminPayload();
        $changes = ChangeSet::between($before, $payload['general']);

        if ($changes !== []) {
            Audit::log(AuditEvent::GeneralSettingsUpdated, changes: $changes, subjectLabel: 'General settings');
            SeoInvalidated::dispatch();
        }

        return $this->apiSuccess('General settings saved.', [
            'settings' => $payload,
        ]);
    }

    public function updateSmtp(Request $request, ApplicationSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['nullable', 'string', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validated['enabled']) {
            $request->validate([
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', 'min:1', 'max:65535'],
                'from_address' => ['required', 'email', 'max:255'],
            ]);
        }

        $before = $settings->adminPayload()['smtp'];

        $settings->put('smtp', 'enabled', (bool) $validated['enabled']);
        $this->putOrForget($settings, 'smtp', 'host', $validated['host'] ?? null);
        $settings->put('smtp', 'port', (int) ($validated['port'] ?? 587));
        $settings->put('smtp', 'encryption', $validated['encryption'] ?? 'tls');
        $this->putOrForget($settings, 'smtp', 'username', $validated['username'] ?? null);
        $this->putOrForget($settings, 'smtp', 'from_address', $validated['from_address'] ?? null);
        $this->putOrForget($settings, 'smtp', 'from_name', $validated['from_name'] ?? null);

        $password = $validated['password'] ?? null;
        $passwordChanged = is_string($password) && $password !== '';
        if ($passwordChanged) {
            $settings->storeSmtpPassword($password);
        }

        $payload = $settings->adminPayload();
        $changes = ChangeSet::between(
            Arr::except($before, 'password_set'),
            Arr::except($payload['smtp'], 'password_set'),
        );

        if ($passwordChanged) {
            $changes['old']['password'] = $before['password_set'] ? 'set' : null;
            $changes['attributes']['password'] = 'updated';
        }

        if ($changes !== []) {
            Audit::log(AuditEvent::SmtpSettingsUpdated, changes: $changes, subjectLabel: 'Mail settings');
        }

        return $this->apiSuccess('Mail settings saved.', [
            'settings' => $payload,
        ]);
    }

    public function uploadLogo(Request $request, ApplicationSettings $settings): JsonResponse
    {
        return $this->uploadBrand($request, $settings, 'logo');
    }

    public function uploadFavicon(Request $request, ApplicationSettings $settings): JsonResponse
    {
        return $this->uploadBrand($request, $settings, 'favicon');
    }

    private function uploadBrand(Request $request, ApplicationSettings $settings, string $kind): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'extensions:jpg,jpeg,png,gif,webp,svg,ico'],
        ]);

        $settings->storeBrand($kind, $request->file('file'));

        Audit::log(
            $kind === 'logo' ? AuditEvent::LogoUpdated : AuditEvent::FaviconUpdated,
            properties: [
                'file_name' => $request->file('file')->getClientOriginalName(),
                'size' => $request->file('file')->getSize(),
            ],
            subjectLabel: $kind === 'logo' ? 'Logo' : 'Favicon',
        );

        SeoInvalidated::dispatch();

        return $this->apiSuccess('Brand file saved.', [
            'settings' => $settings->adminPayload(),
        ]);
    }

    private function putOrForget(ApplicationSettings $settings, string $group, string $key, mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            $settings->forget($group, $key);

            return;
        }

        $settings->put($group, $key, trim($value));
    }
}
