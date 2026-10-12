<?php

namespace App\Services\Seo\Audit;

use App\Services\Seo\Audit\Concerns\BuildsCheckResults;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Validates /robots.txt (RFC 9309) and returns the parsed rules for the page
 * and sitemap scans. A 4xx file allows everything; a 5xx or unreachable file
 * blocks everything, as Google treats it.
 */
final class RobotsTxtScanner
{
    use BuildsCheckResults;

    /**
     * @param  list<string>  $pagePaths  paths of the indexable pages
     * @return array{report: array<string, mixed>, rules: RobotsRules}
     */
    public function scan(string $siteUrl, array $pagePaths, bool $development): array
    {
        $url = SeoUrl::join($siteUrl, '/robots.txt');
        $details = ['url' => $url, 'status' => null, 'content_type' => null, 'bytes' => null, 'sitemaps' => [], 'groups' => [], 'problems' => [], 'ai' => [], 'content' => null];

        try {
            $response = $this->client()->get($url);
        } catch (Throwable $exception) {
            return $this->unavailable($details, 'robots.txt did not answer: '.$this->shortError($exception), $development, blocksAll: true);
        }

        $details['status'] = $response->status();
        $details['content_type'] = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

        if ($response->status() !== 200) {
            $message = match (true) {
                $response->redirect() => 'robots.txt redirects to '.($response->header('Location') ?: 'another URL').'. Serve it directly at the root.',
                $response->status() >= 500 => 'robots.txt answers '.$response->status().'. Google stops crawling the whole site until it answers.',
                default => 'There is no robots.txt ('.$response->status().'). Crawlers may crawl everything, but checkers report it missing.',
            };

            return $this->unavailable($details, $message, $development, blocksAll: $response->status() >= 500);
        }

        $body = $response->body();
        $rules = RobotsRules::parse($body);
        $details['bytes'] = strlen($body);
        $details['content'] = mb_substr($body, 0, 20000);
        $details['sitemaps'] = $rules->sitemaps();
        $details['problems'] = $rules->problems();
        $details['groups'] = array_map(static fn (array $group) => ['agents' => $group['agents'], 'rules' => count($group['rules'])], $rules->groups());
        $details['ai'] = $this->aiPolicy($rules);

        $results = [
            'robots_available' => $this->available($details['content_type']),
            'robots_size' => $this->size(strlen($body)),
            'robots_syntax' => $rules->problems() === []
                ? $this->result(true, 'no problems', 'valid RFC 9309 syntax', 'Valid.')
                : $this->result(false, $this->sample($rules->problems(), 3), 'valid RFC 9309 syntax', 'Fix the lines crawlers cannot read.'),
            'robots_wildcard_group' => $rules->hasGroupFor('*')
                ? $this->result(true, 'User-agent: *', 'User-agent: *', 'Present.')
                : $this->result(false, 'missing', 'User-agent: *', 'Add a "User-agent: *" group so every crawler has rules.'),
            'robots_not_blocking_site' => $this->notBlockingSite($rules),
            'robots_assets_allowed' => $this->blockedPaths($rules, (array) config('seo.audit.robots.asset_paths', []), 'assets', 'Crawlers need CSS, JS and images to render pages. Allow them.'),
            'robots_pages_allowed' => $this->blockedPaths($rules, $pagePaths, 'pages', 'robots.txt blocks pages that should be indexed.'),
            'robots_sitemap_declared' => $this->sitemapDeclared($rules, $siteUrl),
            'robots_ai_policy' => $this->result(true, $this->aiSummary($details['ai']), 'a deliberate policy', 'AI crawlers follow these rules.'),
        ];

        return ['report' => [...SeoCheckCatalog::section($results, $development), 'details' => $details], 'rules' => $rules];
    }

    private function client(): PendingRequest
    {
        return Http::timeout(10)
            ->withUserAgent((string) config('seo.audit.user_agent'))
            ->withHeaders(['Accept-Encoding' => 'gzip, deflate'])
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{report: array<string, mixed>, rules: RobotsRules}
     */
    private function unavailable(array $details, string $message, bool $development, bool $blocksAll): array
    {
        $results = ['robots_available' => $this->result(false, $details['status'] === null ? 'no answer' : 'HTTP '.$details['status'], '200 text/plain', $message)];

        return [
            'report' => [...SeoCheckCatalog::section($results, $development), 'details' => $details],
            'rules' => RobotsRules::parse($blocksAll ? "User-agent: *\nDisallow: /\n" : null),
        ];
    }

    private function available(string $contentType): array
    {
        return $contentType === 'text/plain'
            ? $this->result(true, '200 text/plain', '200 text/plain', 'Served correctly.')
            : $this->result(false, '200 '.($contentType ?: 'no content type'), '200 text/plain', 'Serve robots.txt as text/plain.');
    }

    private function size(int $bytes): array
    {
        $limit = (int) config('seo.audit.robots.max_kb', 500);
        $kb = (int) ceil($bytes / 1024);

        return $kb <= $limit
            ? $this->result(true, "{$kb} KB", "≤ {$limit} KB", 'Small enough.')
            : $this->result(false, "{$kb} KB", "≤ {$limit} KB", "Google ignores everything after the first {$limit} KB.");
    }

    private function notBlockingSite(RobotsRules $rules): array
    {
        $blocked = array_values(array_filter(
            (array) config('seo.audit.robots.agents', ['*']),
            static fn (string $agent) => ! $rules->allows('/', $agent),
        ));

        return $blocked === []
            ? $this->result(true, 'home page allowed', 'home page allowed for every search engine', 'Search engines may crawl the site.')
            : $this->result(false, 'blocked for '.implode(', ', $blocked), 'home page allowed for every search engine', 'robots.txt blocks the whole site. Remove "Disallow: /".');
    }

    /**
     * @param  list<string>  $paths
     */
    private function blockedPaths(RobotsRules $rules, array $paths, string $what, string $hint): array
    {
        $blocked = [];

        foreach (array_unique($paths) as $path) {
            foreach (['*', 'googlebot'] as $agent) {
                if (! $rules->allows($path, $agent)) {
                    $blocked[] = $path;

                    break;
                }
            }
        }

        if ($paths === []) {
            return $this->result(true, "no {$what}", 'none blocked', 'Nothing to check.');
        }

        $count = count(array_unique($paths));

        return $blocked === []
            ? $this->result(true, $count.' '.Str::plural(Str::singular($what), $count).' allowed', 'none blocked', 'Crawlable.')
            : $this->result(false, $this->sample($blocked), 'none blocked', $hint);
    }

    private function sitemapDeclared(RobotsRules $rules, string $siteUrl): array
    {
        $sitemaps = $rules->sitemaps();
        $expected = 'an absolute Sitemap URL on this host that answers 200';

        if ($sitemaps === []) {
            return $this->result(false, 'none', $expected, 'Add a "Sitemap:" line so crawlers find the sitemap.');
        }

        $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        $problems = [];

        foreach ($sitemaps as $sitemap) {
            if (preg_match('#^https?://#i', $sitemap) !== 1) {
                $problems[] = "{$sitemap} is not absolute";
            } elseif (strtolower((string) parse_url($sitemap, PHP_URL_HOST)) !== $host) {
                $problems[] = "{$sitemap} is on another host";
            } else {
                $status = $this->status($sitemap);

                if ($status !== 200) {
                    $problems[] = $sitemap.' answers '.($status ?? 'nothing');
                }
            }
        }

        return $problems === []
            ? $this->result(true, implode(', ', $sitemaps), $expected, 'Declared and reachable.')
            : $this->result(false, $this->sample($problems, 3), $expected, 'Fix the Sitemap lines in robots.txt.');
    }

    private function status(string $url): ?int
    {
        try {
            return $this->client()->get($url)->status();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{blocked: list<string>, allowed: list<string>}
     */
    private function aiPolicy(RobotsRules $rules): array
    {
        $policy = ['blocked' => [], 'allowed' => []];

        foreach ((array) config('seo.robots.ai_crawlers', []) as $bot) {
            $policy[$rules->allows('/', strtolower($bot)) ? 'allowed' : 'blocked'][] = $bot;
        }

        return $policy;
    }

    /**
     * @param  array{blocked?: list<string>, allowed?: list<string>}  $policy
     */
    private function aiSummary(array $policy): string
    {
        $blocked = $policy['blocked'] ?? [];
        $allowed = $policy['allowed'] ?? [];

        return match (true) {
            $blocked === [] => 'all AI crawlers allowed',
            $allowed === [] => 'all AI crawlers blocked',
            default => count($blocked).' blocked ('.$this->sample($blocked, 3).'), '.count($allowed).' allowed',
        };
    }
}
