<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The public site origin. The path probe and the SEO audit send requests to
 * it from the server, so in production it must be https and must not point
 * at localhost or a private network (SSRF guard).
 */
class SafeSiteUrl implements ValidationRule
{
    public function __construct(private readonly ?bool $production = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $parts = parse_url(trim($value));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            $fail('Enter an absolute URL such as https://example.com.');

            return;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('The site URL must start with http:// or https://.');

            return;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            $fail('Enter only the origin, without a path, query or fragment (e.g. https://example.com).');

            return;
        }

        if (! ($this->production ?? app()->isProduction())) {
            return;
        }

        if ($scheme !== 'https') {
            $fail('The site URL must use https in production.');

            return;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            $fail('The site URL cannot point to localhost.');

            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $fail('The site URL cannot resolve to a private, loopback or link-local address.');

                return;
            }
        }
    }
}
