<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for admin-facing alert notifications (backup failures, SEO health
 * drops, critical audit activity). `via()` is driven entirely by the
 * recipient's own preferences (`User::$push_notifications` for in-app —
 * database row + realtime broadcast together — and
 * `User::$email_notifications` for mail), per Phase 6 of the starter
 * template roadmap. If a user has turned both off, nothing is sent or
 * stored for them — that is the intended effect of opting out.
 */
abstract class AdminAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable instanceof User && $notifiable->push_notifications) {
            $channels[] = 'database';
            $channels[] = 'broadcast';
        }

        if ($notifiable instanceof User && $notifiable->email_notifications) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Stored payload for the `notifications` table and the realtime push —
     * same shape for both, so the inbox and the live bell render identically.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(object $notifiable): array;

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * Without this, Echo would have to listen for the fully-qualified
     * `Illuminate\Notifications\Events\BroadcastNotificationCreated` event
     * name — `broadcastAs()` (not `broadcastType()`) is what Laravel's
     * `BroadcastNotificationCreated` event actually uses for the wire event
     * name; `type` only ends up as a field inside the payload.
     */
    public function broadcastAs(): string
    {
        return 'notification';
    }

    public function broadcastType(): string
    {
        return 'notification';
    }
}
