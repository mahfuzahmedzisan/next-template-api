<?php

namespace App\Audit\Support;

use Illuminate\Support\Facades\Context;

/**
 * Request details kept in hidden Laravel Context. Hidden context stays out of
 * log files but is still copied into queued jobs, so a job dispatched from a
 * request is audited with that request's IP, device and request id.
 */
final class AuditContext
{
    public const REQUEST_ID = 'audit.request_id';

    public const IP = 'audit.ip';

    public const USER_AGENT = 'audit.user_agent';

    public const ORIGIN = 'audit.origin';

    public const ORIGIN_WEB = 'web';

    public const ORIGIN_CONSOLE = 'console';

    public const ORIGIN_QUEUE = 'queue';

    public const ORIGIN_SYSTEM = 'system';

    public static function capture(string $requestId, ?string $ip, ?string $userAgent): void
    {
        Context::addHidden([
            self::REQUEST_ID => $requestId,
            self::IP => $ip,
            self::USER_AGENT => $userAgent,
            self::ORIGIN => self::ORIGIN_WEB,
        ]);
    }

    /**
     * Marks work started by the scheduler, so it is shown as "System".
     */
    public static function markSystem(): void
    {
        Context::addHidden(self::ORIGIN, self::ORIGIN_SYSTEM);
    }

    public static function requestId(): ?string
    {
        return self::string(self::REQUEST_ID);
    }

    public static function ip(): ?string
    {
        return self::string(self::IP);
    }

    public static function userAgent(): ?string
    {
        return self::string(self::USER_AGENT);
    }

    public static function origin(): string
    {
        if (app()->runningConsoleCommand('queue:work', 'queue:listen')) {
            return self::ORIGIN_QUEUE;
        }

        return self::string(self::ORIGIN)
            ?? (app()->runningInConsole() ? self::ORIGIN_CONSOLE : self::ORIGIN_WEB);
    }

    private static function string(string $key): ?string
    {
        $value = Context::getHidden($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
