<?php

namespace App\Listeners\Audit;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Models\User;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

/**
 * Fortify's two-factor actions announce themselves through events, so the
 * audit trail stays correct whichever controller calls those actions.
 */
class RecordTwoFactorActivity
{
    public function handleEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->record(AuditEvent::TwoFactorEnabled, $event->user);
    }

    public function handleConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record(AuditEvent::TwoFactorConfirmed, $event->user);
    }

    public function handleDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record(AuditEvent::TwoFactorDisabled, $event->user);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->record(AuditEvent::RecoveryCodesRegenerated, $event->user);
    }

    public function handleRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        $user = $event->user;

        $this->record(AuditEvent::RecoveryCodeUsed, $user, [
            'remaining' => $user instanceof User ? count($user->recoveryCodes()) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(AuditEvent $event, mixed $user, array $properties = []): void
    {
        if (! $user instanceof User) {
            return;
        }

        Audit::log($event, subject: $user, causer: $user, properties: array_filter(
            $properties,
            fn ($value) => $value !== null,
        ));
    }
}
