<?php

namespace App\Audit;

/**
 * Every hand-written audit event. Model events (created, updated, deleted,
 * restored) come from the Auditable trait and are not listed here.
 */
enum AuditEvent: string
{
    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case LoginThrottled = 'login.throttled';
    case TwoFactorChallengeFailed = 'two_factor.challenge_failed';
    case Logout = 'logout';
    case Registered = 'registered';
    case PasswordResetLinkRequested = 'password.reset_link_requested';
    case PasswordReset = 'password.reset';

    case PasswordChanged = 'password.changed';
    case TwoFactorEnabled = 'two_factor.enabled';
    case TwoFactorConfirmed = 'two_factor.confirmed';
    case TwoFactorDisabled = 'two_factor.disabled';
    case RecoveryCodesRegenerated = 'two_factor.recovery_codes_regenerated';
    case RecoveryCodeUsed = 'two_factor.recovery_code_used';
    case SignedOutEverywhere = 'sessions.signed_out_everywhere';
    case DeviceRevoked = 'device.revoked';
    case DeviceForgotten = 'device.forgotten';
    case DeviceHistoryCleared = 'device_history.cleared';

    case AvatarUpdated = 'avatar.updated';
    case AvatarRemoved = 'avatar.removed';
    case AccountDeleted = 'account.deleted';

    case MediaUploadCompleted = 'media.upload_completed';
    case MediaProcessingFailed = 'media.processing_failed';
    case MediaThumbnailUpdated = 'media.thumbnail_updated';
    case MediaRetried = 'media.retried';

    case GeneralSettingsUpdated = 'settings.general_updated';
    case SmtpSettingsUpdated = 'settings.smtp_updated';
    case LogoUpdated = 'settings.logo_updated';
    case FaviconUpdated = 'settings.favicon_updated';

    case BackupRequested = 'backup.requested';
    case BackupCompleted = 'backup.completed';
    case BackupFailed = 'backup.failed';
    case BackupDeleted = 'backup.deleted';
    case BackupDownloadRequested = 'backup.download_requested';
    case BackupDownloaded = 'backup.downloaded';
    case RestoreRequested = 'restore.requested';
    case RestoreCompleted = 'restore.completed';
    case RestoreFailed = 'restore.failed';

    case AuditPruned = 'audit.pruned';
    case AuditExported = 'audit.exported';

    public function category(): AuditCategory
    {
        return match ($this) {
            self::LoginSucceeded,
            self::LoginFailed,
            self::LoginThrottled,
            self::TwoFactorChallengeFailed,
            self::Logout,
            self::Registered,
            self::PasswordResetLinkRequested,
            self::PasswordReset => AuditCategory::Auth,

            self::PasswordChanged,
            self::TwoFactorEnabled,
            self::TwoFactorConfirmed,
            self::TwoFactorDisabled,
            self::RecoveryCodesRegenerated,
            self::RecoveryCodeUsed,
            self::SignedOutEverywhere,
            self::DeviceRevoked,
            self::DeviceForgotten,
            self::DeviceHistoryCleared => AuditCategory::Security,

            self::AvatarUpdated,
            self::AvatarRemoved,
            self::AccountDeleted => AuditCategory::Account,

            self::MediaUploadCompleted,
            self::MediaProcessingFailed,
            self::MediaThumbnailUpdated,
            self::MediaRetried => AuditCategory::Media,

            self::GeneralSettingsUpdated,
            self::SmtpSettingsUpdated,
            self::LogoUpdated,
            self::FaviconUpdated => AuditCategory::Settings,

            self::BackupRequested,
            self::BackupCompleted,
            self::BackupFailed,
            self::BackupDeleted,
            self::BackupDownloadRequested,
            self::BackupDownloaded,
            self::RestoreRequested,
            self::RestoreCompleted,
            self::RestoreFailed => AuditCategory::Backups,

            self::AuditPruned,
            self::AuditExported => AuditCategory::System,
        };
    }

    public function severity(): AuditSeverity
    {
        return match ($this) {
            self::AccountDeleted,
            self::BackupFailed,
            self::RestoreRequested,
            self::RestoreCompleted,
            self::RestoreFailed => AuditSeverity::Critical,

            self::LoginFailed,
            self::LoginThrottled,
            self::TwoFactorChallengeFailed,
            self::TwoFactorDisabled,
            self::RecoveryCodeUsed,
            self::MediaProcessingFailed,
            self::BackupDeleted,
            self::BackupDownloaded => AuditSeverity::Warning,

            self::PasswordReset,
            self::PasswordChanged,
            self::TwoFactorEnabled,
            self::TwoFactorConfirmed,
            self::RecoveryCodesRegenerated,
            self::SignedOutEverywhere,
            self::DeviceRevoked,
            self::GeneralSettingsUpdated,
            self::SmtpSettingsUpdated,
            self::BackupDownloadRequested,
            self::AuditExported => AuditSeverity::Notice,

            default => AuditSeverity::Info,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Signed in',
            self::LoginFailed => 'Failed sign-in attempt',
            self::LoginThrottled => 'Sign-in attempts throttled',
            self::TwoFactorChallengeFailed => 'Two-factor code rejected',
            self::Logout => 'Signed out',
            self::Registered => 'Account registered',
            self::PasswordResetLinkRequested => 'Password reset requested',
            self::PasswordReset => 'Password reset',
            self::PasswordChanged => 'Password changed',
            self::TwoFactorEnabled => 'Two-factor setup started',
            self::TwoFactorConfirmed => 'Two-factor turned on',
            self::TwoFactorDisabled => 'Two-factor turned off',
            self::RecoveryCodesRegenerated => 'Recovery codes regenerated',
            self::RecoveryCodeUsed => 'Signed in with a recovery code',
            self::SignedOutEverywhere => 'Signed out of other devices',
            self::DeviceRevoked => 'Device signed out',
            self::DeviceForgotten => 'Device removed from history',
            self::DeviceHistoryCleared => 'Device history cleared',
            self::AvatarUpdated => 'Avatar updated',
            self::AvatarRemoved => 'Avatar removed',
            self::AccountDeleted => 'Account deleted',
            self::MediaUploadCompleted => 'Upload completed',
            self::MediaProcessingFailed => 'Processing failed',
            self::MediaThumbnailUpdated => 'Thumbnail updated',
            self::MediaRetried => 'Processing retried',
            self::GeneralSettingsUpdated => 'General settings updated',
            self::SmtpSettingsUpdated => 'Mail settings updated',
            self::LogoUpdated => 'Logo updated',
            self::FaviconUpdated => 'Favicon updated',
            self::BackupRequested => 'Backup requested',
            self::BackupCompleted => 'Backup completed',
            self::BackupFailed => 'Backup failed',
            self::BackupDeleted => 'Backup deleted',
            self::BackupDownloadRequested => 'Backup download requested',
            self::BackupDownloaded => 'Backup downloaded',
            self::RestoreRequested => 'Restore requested',
            self::RestoreCompleted => 'Database restored',
            self::RestoreFailed => 'Restore failed',
            self::AuditPruned => 'Old audit entries removed',
            self::AuditExported => 'Audit log exported',
        };
    }

    public function description(): string
    {
        return $this->label();
    }

    /**
     * Label for any stored event value, including model events.
     */
    public static function labelFor(?string $event): string
    {
        if ($event === null || $event === '') {
            return 'Activity';
        }

        return self::tryFrom($event)?->label() ?? ucfirst(str_replace(['.', '_'], ' ', $event));
    }
}
