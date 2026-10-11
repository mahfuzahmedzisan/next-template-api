<?php

namespace App\Audit;

enum AuditCategory: string
{
    case Auth = 'auth';
    case Security = 'security';
    case Account = 'account';
    case Users = 'users';
    case Media = 'media';
    case Settings = 'settings';
    case Backups = 'backups';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Auth => 'Sign-in',
            self::Security => 'Security',
            self::Account => 'Account',
            self::Users => 'Users',
            self::Media => 'Media',
            self::Settings => 'Settings',
            self::Backups => 'Backups',
            self::System => 'System',
        };
    }

    /**
     * Categories a user sees in their own activity feed.
     *
     * @return list<self>
     */
    public static function personal(): array
    {
        return [self::Auth, self::Security, self::Account];
    }
}
