<?php

namespace App\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordRecoveryNotification extends Notification
{
    public function __construct(public readonly string $token, public readonly string $accountType) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->mailer(config('auth.mailer'))
            ->subject('Reset your store password')
            ->line('Enter this password reset token in the '.$this->accountType.' password reset form:')
            ->line($this->token)
            ->line('It expires in '.config('auth.passwords.users.expire').' minutes and can be used once.')
            ->line('If you did not request this, ignore this email.');
    }
}
