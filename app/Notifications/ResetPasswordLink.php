<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private string $url, private string $workspace, private int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Reset your {$this->workspace} password")
            ->line("We received a request to reset your password for {$this->workspace}.")
            ->action('Choose a new password', $this->url)
            ->line("This link works once and expires in {$this->minutes} minutes.")
            ->line('If you did not ask for this, you can ignore this email.');
    }
}
