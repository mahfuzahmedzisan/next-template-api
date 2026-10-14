<?php

namespace App\Notifications;

use App\Support\FrontendOrigins;
use Illuminate\Notifications\Messages\MailMessage;

class SeoHealthScoreDroppedNotification extends AdminAlertNotification
{
    public function __construct(
        public int $previousScore,
        public int $currentScore,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'SEO health score dropped',
            'body' => "Score fell from {$this->previousScore} to {$this->currentScore}.",
            'url' => '/admin/application/seo-health',
            'severity' => 'warning',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SEO health score dropped')
            ->line("The latest SEO health audit scored {$this->currentScore}, down from {$this->previousScore}.")
            ->action('Review SEO health', rtrim(FrontendOrigins::default(), '/').'/admin/application/seo-health');
    }
}
