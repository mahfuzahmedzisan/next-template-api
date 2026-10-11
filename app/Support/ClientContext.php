<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The BFF forwards the browser's IP and user agent. EnsureTrustedBff has
 * already proven the request came from the BFF, so the headers are trusted.
 */
final class ClientContext
{
    public static function ip(Request $request): ?string
    {
        $forwarded = trim((string) $request->header('X-Client-IP', ''));

        if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }

        return $request->ip();
    }

    public static function userAgent(Request $request): ?string
    {
        $agent = trim((string) ($request->header('X-Client-User-Agent') ?: $request->userAgent()));

        return $agent === '' ? null : mb_substr($agent, 0, 512);
    }
}
