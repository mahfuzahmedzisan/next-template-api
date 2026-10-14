<?php

namespace App\Notifications;

use App\Support\FrontendOrigins;
use Illuminate\Notifications\Messages\MailMessage;

class BackupFailedNotification extends AdminAlertNotification
{
    public function __construct(
        public string $reason,
    ) {}

    /**
     * `url` is a path on the Next.js frontend, not this API — the admin
     * inbox UI routes to it directly, same convention as the stored SEO
     * audit / activity notifications below.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Backup failed',
            'body' => $this->reason,
            'url' => '/admin/application/backup',
            'severity' => 'critical',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Backup failed')
            ->error()
            ->line('A scheduled database backup failed.')
            ->line($this->reason)
            ->action('Review backups', rtrim(FrontendOrigins::default(), '/').'/admin/application/backup');
    }
}
