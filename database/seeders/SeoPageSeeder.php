<?php

namespace Database\Seeders;

use App\Models\SeoPage;
use Illuminate\Database\Seeder;

/**
 * Built-in public pages. Insert-only: existing rows are never touched, so
 * running this on every deploy keeps whatever an admin has edited.
 */
class SeoPageSeeder extends Seeder
{
    public function run(): void
    {
        $this->page('/', 'Home', 'home', [
            'schema_type' => 'WebPage',
            'sitemap_priority' => 1.0,
            'sitemap_changefreq' => 'weekly',
        ]);

        $auth = [
            '/login' => 'Sign in',
            '/register' => 'Create account',
            '/forgot-password' => 'Forgot password',
            '/reset-password' => 'Reset password',
            '/two-factor-challenge' => 'Two-factor challenge',
        ];

        foreach ($auth as $path => $label) {
            $this->page($path, $label, 'auth', [
                'robots_index' => false,
                'robots_follow' => false,
                'in_sitemap' => false,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function page(string $path, string $label, string $type, array $attributes): void
    {
        SeoPage::query()->firstOrCreate(['path' => $path], [
            'label' => $label,
            'page_type' => $type,
            'is_system' => true,
            ...$attributes,
        ]);
    }
}
