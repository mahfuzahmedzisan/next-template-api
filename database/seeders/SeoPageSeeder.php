<?php

namespace Database\Seeders;

use App\Events\SeoInvalidated;
use App\Models\SeoPage;
use App\Services\Seo\SeoSettings;
use Illuminate\Database\Seeder;

/**
 * Built-in public pages with starter SEO copy. Safe on every deploy: a new
 * row gets everything, an existing row only gets content fields that are
 * still empty (or still hold copy an older version of this seeder wrote),
 * so whatever an admin has written is never replaced.
 */
class SeoPageSeeder extends Seeder
{
    /**
     * Copy earlier releases seeded. A field still holding one of these values
     * was never edited, so it is upgraded like an empty one.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SUPERSEDED = [
        '/' => [
            'meta_title' => '{{site_name}} — Private workspace and portfolio',
            'meta_description' => 'Sign in to manage your profile, password and two-factor authentication in one private workspace, kept apart from the public portfolio.',
            'keywords' => ['portfolio', 'private workspace', 'account security', 'two-factor authentication'],
        ],
    ];

    private bool $changed = false;

    public function run(): void
    {
        $this->settingsDefaults();

        SeoPage::withoutEvents(function (): void {
            foreach ($this->publicPages() as $path => [$label, $type, $settings, $content]) {
                // Titles are written to the strict 50-60 character range as they stand; an affix would push them over.
                $this->page($path, $label, $type, $settings, [...$content, 'title_affix_mode' => 'none']);
            }

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
     * Public pages rendered by the frontend. Titles are 50-60 characters and
     * descriptions 140-160, the strict range the SEO health check enforces.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}>
     */
    public function publicPages(): array
    {
        return [
            '/' => ['Home', 'home', [
                'schema_type' => 'WebPage',
                'sitemap_priority' => 1.0,
                'sitemap_changefreq' => 'weekly',
            ], [
                'meta_title' => 'Mahfuz Ahmed: Full-Stack Developer for Next.js and Laravel',
                'meta_description' => 'Full-stack developer Mahfuz Ahmed builds fast, accessible web apps with Next.js and Laravel, from first sketch to launch, with weekly releases and support.',
                'keywords' => ['full-stack developer', 'Next.js developer', 'Laravel developer', 'web application development', 'technical SEO'],
            ]],
            '/about' => ['About', 'about', [
                'schema_type' => 'AboutPage',
                'og_type' => 'profile',
                'sitemap_priority' => 0.8,
                'sitemap_changefreq' => 'monthly',
            ], [
                'meta_title' => 'About Mahfuz Ahmed, Full-Stack Web Developer in Dhaka',
                'meta_description' => 'Meet Mahfuz Ahmed, a full-stack developer with ten years of Next.js, React and Laravel experience who turns messy processes into simple, reliable web apps.',
                'keywords' => ['about Mahfuz Ahmed', 'full-stack developer Dhaka', 'Next.js', 'Laravel', 'freelance web developer'],
            ]],
            '/services' => ['Services', 'landing', [
                'schema_type' => 'WebPage',
                'sitemap_priority' => 0.9,
                'sitemap_changefreq' => 'monthly',
            ], [
                'meta_title' => 'Web Development Services: Next.js, Laravel and Technical SEO',
                'meta_description' => 'Web application development, APIs and integrations, performance and technical SEO, and ongoing maintenance for teams that need dependable web software.',
                'keywords' => ['web development services', 'API integrations', 'performance optimization', 'technical SEO', 'website maintenance'],
            ]],
            '/projects' => ['Projects', 'listing', [
                'schema_type' => 'CollectionPage',
                'sitemap_priority' => 0.9,
                'sitemap_changefreq' => 'weekly',
            ], [
                'meta_title' => 'Projects and Case Studies by Full-Stack Developer Mahfuz',
                'meta_description' => 'Case studies of dashboards, booking systems, portals and storefronts built with Next.js and Laravel, each with the problem, approach and measured results.',
                'keywords' => ['portfolio', 'case studies', 'Next.js projects', 'Laravel projects', 'web applications'],
            ]],
            '/blog' => ['Blog', 'listing', [
                'schema_type' => 'CollectionPage',
                'sitemap_priority' => 0.8,
                'sitemap_changefreq' => 'weekly',
            ], [
                'meta_title' => 'Blog: Practical Notes on Next.js, Laravel, Speed and SEO',
                'meta_description' => 'Practical articles on Next.js, Laravel, performance, accessibility and technical SEO, written from real client projects with steps you can apply right away.',
                'keywords' => ['web development blog', 'Next.js tutorials', 'Laravel tips', 'web performance', 'technical SEO'],
            ]],
            '/contact' => ['Contact', 'contact', [
                'schema_type' => 'ContactPage',
                'sitemap_priority' => 0.7,
                'sitemap_changefreq' => 'yearly',
            ], [
                'meta_title' => 'Contact Mahfuz Ahmed to Discuss Your Next Web Project',
                'meta_description' => 'Tell Mahfuz Ahmed about your team and the problem you want to solve. Get a personal reply within two working days, a free call and a short written proposal.',
                'keywords' => ['contact', 'hire a full-stack developer', 'web project quote', 'Next.js consultant'],
            ]],
            '/privacy-policy' => ['Privacy policy', 'legal', [
                'schema_type' => 'WebPage',
                'sitemap_priority' => 0.3,
                'sitemap_changefreq' => 'yearly',
            ], [
                'meta_title' => 'Privacy Policy: How Your Personal Information Is Handled',
                'meta_description' => 'What personal information this website collects, why it is collected, how long it is kept, which cookies are used and how to exercise your privacy rights.',
                'keywords' => ['privacy policy', 'personal data', 'cookies', 'data protection'],
            ]],
            '/terms' => ['Terms of service', 'legal', [
                'schema_type' => 'WebPage',
                'sitemap_priority' => 0.3,
                'sitemap_changefreq' => 'yearly',
            ], [
                'meta_title' => 'Terms of Service for the Mahfuz Ahmed Portfolio Website',
                'meta_description' => 'The rules for using this website and its private workspace: acceptable use, accounts, content ownership, project agreements, disclaimers and liability.',
                'keywords' => ['terms of service', 'terms and conditions', 'acceptable use', 'website terms'],
            ]],
        ];
    }

    /** Global SEO defaults, written only while the admin has left them empty. */
    private function settingsDefaults(): void
    {
        $seo = app(SeoSettings::class);
        $current = $seo->all();

        $values = array_filter([
            'default_author' => $current['default_author'] === '' ? 'Mahfuz Ahmed' : null,
        ]);

        if ($values !== []) {
            $seo->update($values);
            $this->changed = true;
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

        $superseded = self::SUPERSEDED[$path] ?? [];

        foreach ($content as $field => $value) {
            $current = $page->getAttribute($field);

            if (blank($current) || (array_key_exists($field, $superseded) && $current === $superseded[$field])) {
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
