<?php

namespace App\Notifications;

use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientInvite extends Notification
{
    public function __construct(public string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(object $notifiable): string
    {
        return Filament::getPanel('app')->getResetPasswordUrl($this->token, $notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = (int) ceil(config('auth.passwords.users.expire') / 1440);

        return (new MailMessage)
            ->subject('Your '.config('app.name').' account is ready')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('An account has been created for you. Choose a password to sign in to your dashboard.')
            ->action('Set your password', $this->url($notifiable))
            ->line("This link works for {$days} days. After that, use \"Forgot password\" on the sign-in page.");
    }
}
