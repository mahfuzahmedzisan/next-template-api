<?php

namespace Database\Seeders;

use App\Events\SeoInvalidated;
use App\Models\SeoPage;
use Illuminate\Database\Seeder;

/**
 * Built-in public pages with starter SEO copy. Safe on every deploy: a new
 * row gets everything, an existing row only gets content fields that are
 * still empty, so whatever an admin has written is never replaced.
 */
class SeoPageSeeder extends Seeder
{
    private bool $changed = false;

    public function run(): void
    {
        SeoPage::withoutEvents(function (): void {
            $this->page('/', 'Home', 'home', [
                'schema_type' => 'WebPage',
                'sitemap_priority' => 1.0,
                'sitemap_changefreq' => 'weekly',
            ], [
                'meta_title' => '{{site_name}} — Private workspace and portfolio',
                // The title already leads with the site name; an affix would repeat it.
                'title_affix_mode' => 'none',
                'meta_description' => 'Sign in to manage your profile, password and two-factor authentication in one private workspace, kept apart from the public portfolio.',
                'keywords' => ['portfolio', 'private workspace', 'account security', 'two-factor authentication'],
            ]);

            $auth = [
                '/login' => ['Sign in', 'Sign in to your workspace', 'Sign in to your {{site_name}} workspace to manage your profile, password and two-factor authentication.'],
                '/register' => ['Create account', 'Create your account', 'Create a {{site_name}} account to get a private workspace with a profile, password settings and two-factor sign-in.'],
                '/forgot-password' => ['Forgot password', 'Forgot your password?', 'Enter your email address and we will send a secure link to reset the password for your {{site_name}} account.'],
                '/reset-password' => ['Reset password', 'Choose a new password', 'Set a new password for your {{site_name}} account using the secure link we sent to your email address.'],
                '/two-factor-challenge' => ['Two-factor challenge', 'Two-factor verification', 'Enter the code from your authenticator app, or one of your recovery codes, to finish signing in to {{site_name}}.'],
            ];

            foreach ($auth as $path => [$label, $title, $description]) {
                $this->page($path, $label, 'auth', [
                    'robots_index' => false,
                    'robots_follow' => false,
                    'in_sitemap' => false,
                ], [
                    'meta_title' => $title,
                    'meta_description' => $description,
                ]);
            }
        });

        if ($this->changed) {
            SeoInvalidated::dispatch();
        }
    }

    /**
     * @param  array<string, mixed>  $settings  applied only when the row is created
     * @param  array<string, mixed>  $content  fills fields that are still empty; title_affix_mode follows meta_title
     */
    private function page(string $path, string $label, string $type, array $settings, array $content): void
    {
        $page = SeoPage::query()->firstOrNew(['path' => $path]);

        if (! $page->exists) {
            $page->fill(['label' => $label, 'page_type' => $type, 'is_system' => true, ...$settings]);
        }

        $affix = $content['title_affix_mode'] ?? null;
        unset($content['title_affix_mode']);

        foreach ($content as $field => $value) {
            if (blank($page->getAttribute($field))) {
                $page->setAttribute($field, $value);
            }
        }

        // The affix goes with the seeded title only; an admin's own title keeps their affix choice.
        if ($affix !== null && $page->isDirty('meta_title')) {
            $page->setAttribute('title_affix_mode', $affix);
        }

        if ($page->isDirty()) {
            $page->save();
            $this->changed = true;
        }
    }
}
