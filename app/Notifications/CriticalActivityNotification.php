<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Support\FrontendOrigins;
use Illuminate\Notifications\Messages\MailMessage;

class CriticalActivityNotification extends AdminAlertNotification
{
    public function __construct(
        public Activity $activity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->activity->eventLabel(),
            'body' => $this->activity->description,
            'url' => '/admin/application/audit',
            'severity' => 'critical',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Critical activity: '.$this->activity->eventLabel())
            ->error()
            ->line($this->activity->description ?? $this->activity->eventLabel())
            ->action('Review audit log', rtrim(FrontendOrigins::default(), '/').'/admin/application/audit');
    }
}
