<?php

namespace App\Services\Seo\Audit;

/**
 * A robots.txt parsed per RFC 9309, matched the way Google does: a crawler
 * obeys the groups naming its product token (merged), else the `*` group;
 * the longest matching rule wins and Allow wins a tie. Supports * and $.
 */
final class RobotsRules
{
    private const KNOWN_FIELDS = ['user-agent', 'allow', 'disallow', 'sitemap', 'crawl-delay', 'host', 'clean-param'];

    /**
     * @param  list<array{agents: list<string>, rules: list<array{allow: bool, pattern: string}>}>  $groups
     * @param  list<string>  $sitemaps
     * @param  list<string>  $problems
     */
    private function __construct(
        private readonly array $groups,
        private readonly array $sitemaps,
        private readonly array $problems,
    ) {}

    public static function parse(?string $contents): self
    {
        if ($contents === null) {
            return new self([], [], []);
        }

        $groups = [];
        $sitemaps = [];
        $problems = [];
        $current = null;

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $problems[] = 'The file starts with a byte order mark (BOM).';
            $contents = substr($contents, 3);
        }

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $index => $raw) {
            $number = $index + 1;
            $line = trim((string) preg_replace('/#.*$/', '', $raw));

            if ($line === '') {
                continue;
            }

            if (! str_contains($line, ':')) {
                $problems[] = "Line {$number}: \"".mb_substr($line, 0, 60).'" has no colon.';

                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if (! in_array($field, self::KNOWN_FIELDS, true)) {
                $problems[] = "Line {$number}: unknown directive \"{$field}\".";

                continue;
            }

            if ($field === 'sitemap') {
                $sitemaps[] = $value;

                continue;
            }

            if ($field === 'user-agent') {
                if ($value === '' || preg_match('/^(\*|[A-Za-z_\-][A-Za-z0-9_\-.\/ ]*)$/', $value) !== 1) {
                    $problems[] = "Line {$number}: \"{$value}\" is not a valid user-agent token.";
                }

                if ($current === null || $groups[$current]['rules'] !== []) {
                    $groups[] = ['agents' => [], 'rules' => []];
                    $current = array_key_last($groups);
                }

                $groups[$current]['agents'][] = strtolower($value);

                continue;
            }

            if (! in_array($field, ['allow', 'disallow'], true)) {
                continue;
            }

            if ($current === null) {
                $problems[] = "Line {$number}: {$field} before any User-agent line.";

                continue;
            }

            if ($value !== '' && ! str_starts_with($value, '/') && ! str_starts_with($value, '*')) {
                $problems[] = "Line {$number}: the path \"{$value}\" must start with / or *.";
            }

            // An empty rule still closes the user-agent list of its group.
            $groups[$current]['rules'][] = ['allow' => $field === 'allow', 'pattern' => $value];
        }

        return new self($groups, $sitemaps, $problems);
    }

    /**
     * Whether a crawler may fetch the path. The agent is a product token such
     * as "googlebot"; it falls back to the `*` group.
     */
    public function allows(string $path, string $agent = '*'): bool
    {
        if ($path === '/robots.txt') {
            return true;
        }

        $best = null;

        foreach ($this->rulesFor($agent) as $rule) {
            if ($rule['pattern'] === '' || ! $this->matches($rule['pattern'], $path)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null || $best['allow'];
    }

    public function hasGroupFor(string $agent): bool
    {
        $agent = strtolower($agent);

        foreach ($this->groups as $group) {
            if (in_array($agent, $group['agents'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{agents: list<string>, rules: list<array{allow: bool, pattern: string}>}>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * @return list<string>
     */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * @return list<array{allow: bool, pattern: string}>
     */
    private function rulesFor(string $agent): array
    {
        $agent = strtolower($agent);
        $matching = array_filter($this->groups, static fn (array $group) => in_array($agent, $group['agents'], true));

        if ($matching === []) {
            $matching = array_filter($this->groups, static fn (array $group) => in_array('*', $group['agents'], true));
        }

        return array_merge(...array_values(array_map(static fn (array $group) => $group['rules'], $matching)));
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).($anchored ? '$' : '').'#';

        return preg_match($regex, $path) === 1;
    }
}
