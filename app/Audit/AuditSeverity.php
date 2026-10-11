<?php

namespace App\Audit;

enum AuditSeverity: string
{
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return list<string>
     */
    public static function elevated(): array
    {
        return [self::Warning->value, self::Critical->value];
    }
}
