<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\RendersForFrontend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Lets admins know someone asked for password help.
 *
 * The reset email is still sent by Laravel's password broker. This
 * notification gives admins a work-queue signal and a place to resend
 * the reset link from the student management page when needed.
 */
class PasswordResetRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersForFrontend;

    public function __construct(public readonly User $requestingUser)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload(
            type: 'staff_message',
            title: 'Password reset requested',
            body: "{$this->requestingUser->name} requested a password reset link.",
            relatedResourceUrl: '/admin/students',
        );
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return 'notification.created';
    }
}
