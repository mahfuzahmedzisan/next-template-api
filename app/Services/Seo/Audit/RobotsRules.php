<?php

namespace App\Services\Seo\Audit;

/**
 * The `User-agent: *` group of a robots.txt, matched the way Google does:
 * the longest matching rule wins and Allow wins a tie. Supports * and $.
 */
final class RobotsRules
{
    /**
     * @param  list<array{allow: bool, pattern: string}>  $rules
     */
    private function __construct(private readonly array $rules) {}

    public static function parse(?string $contents): self
    {
        if ($contents === null) {
            return new self([]);
        }

        $rules = [];
        $inGroup = false;
        $groupHasRules = false;

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($groupHasRules) {
                    $inGroup = false;
                    $groupHasRules = false;
                }
                $inGroup = $inGroup || $value === '*';

                continue;
            }

            if (! in_array($field, ['allow', 'disallow'], true)) {
                continue;
            }

            $groupHasRules = true;

            if ($inGroup && $value !== '') {
                $rules[] = ['allow' => $field === 'allow', 'pattern' => $value];
            }
        }

        return new self($rules);
    }

    public function allows(string $path): bool
    {
        $best = null;

        foreach ($this->rules as $rule) {
            if (! $this->matches($rule['pattern'], $path)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null || $best['allow'];
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).($anchored ? '$' : '').'#';

        return preg_match($regex, $path) === 1;
    }
}
