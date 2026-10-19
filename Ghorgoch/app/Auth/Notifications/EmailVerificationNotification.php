<?php

namespace App\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->mailer(config('auth.mailer'))
            ->subject('Verify your store email')
            ->line('Enter this verification token in the store:')
            ->line($this->token)
            ->line('It expires in '.config('auth.verification_expire').' minutes and can be used once.')
            ->line('If you did not request this, ignore this email.');
    }
}
