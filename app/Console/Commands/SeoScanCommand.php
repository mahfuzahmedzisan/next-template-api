<?php

namespace App\Console\Commands;

use App\Services\Seo\Audit\RobotsTxtScanner;
use App\Services\Seo\Audit\SeoAuditRunner;
use App\Services\Seo\Audit\SeoPageScanner;
use App\Services\Seo\Audit\SitemapScanner;
use App\Services\Seo\Audit\SiteScanner;
use Illuminate\Console\Command;

/**
 * Runs the SEO health engine against any URL, to compare with external checkers.
 */
class SeoScanCommand extends Command
{
    protected $signature = 'seo:scan
        {url : A page URL; its origin is used for the site, robots.txt and sitemap checks}
        {--type=default : Page type or model alias, for the word minimum}
        {--only= : page, site, robots or sitemap}
        {--json : Print the full report as JSON}';

    protected $description = 'Scan a URL with the SEO health checks';

    public function handle(SeoAuditRunner $runner, SeoPageScanner $pages, SiteScanner $site, RobotsTxtScanner $robots, SitemapScanner $sitemap): int
    {
        $url = (string) $this->argument('url');

        if (preg_match('#^https?://[^/]+#i', $url, $match) !== 1) {
            $this->error('Pass an absolute http(s) URL.');

            return self::INVALID;
        }

        $only = (string) $this->option('only');

        if ($only !== '' && ! in_array($only, ['page', 'site', 'robots', 'sitemap'], true)) {
            $this->error('Use --only=page, site, robots or sitemap.');

            return self::INVALID;
        }

        $origin = $match[0];
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $development = $runner->isDevelopment($origin);
        $wants = static fn (string $section) => $only === '' || $only === $section;
        $robotsScan = $robots->scan($origin, [$path], $development);
        $report = ['url' => $url, 'environment' => $development ? 'development' : 'production'];

        if ($wants('page')) {
            $report['page'] = $pages->scan(['kind' => 'page', 'page_type' => (string) $this->option('type'), 'url' => $url, 'indexable' => true], $robotsScan['rules'], $development);
        }

        if ($wants('site')) {
            $report['site'] = $site->scan($origin, $path === '/' ? null : $path, $development);
        }

        if ($wants('robots')) {
            $report['robots'] = $robotsScan['report'];
        }

        if ($wants('sitemap')) {
            $report['sitemap'] = $sitemap->scan($origin, $robotsScan['rules']->sitemaps(), [$url], $robotsScan['rules'], $development);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach (['page', 'site', 'robots', 'sitemap'] as $section) {
            if (! isset($report[$section])) {
                continue;
            }

            $this->newLine();
            $this->info(ucfirst($section).': '.($report[$section]['score'] ?? '—').($report[$section]['error'] ?? null ? ' ('.$report[$section]['error'].')' : ''));
            $this->table(['', 'Check', 'Actual', 'Expected'], array_map(static fn (array $check) => [
                $check['passed'] ? 'pass' : ($check['counted'] ? 'FAIL' : 'skip'),
                $check['label'],
                (string) $check['actual'],
                (string) $check['expected'],
            ], $report[$section]['checks']));
        }

        return self::SUCCESS;
    }
}
