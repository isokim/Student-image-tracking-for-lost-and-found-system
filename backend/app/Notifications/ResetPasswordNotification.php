<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset mail pointing at the React app rather than a Laravel
 * view, because this backend serves JSON only.
 *
 * Locally MAIL_MAILER=log, so the link lands in storage/logs/laravel.log
 * instead of being sent - copy it from there to test the flow.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $resetUrl)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your Lost & Found password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset password', $this->resetUrl)
            ->line("This link will expire in {$minutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }
}
