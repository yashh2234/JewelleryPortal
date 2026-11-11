<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email to the retailer's users when their order moves (SMS/WhatsApp later). */
class OrderStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $workspace,
        private string $orderNo,
        private string $headline,
        private string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->orderNo}: {$this->headline}")
            ->greeting("Order {$this->orderNo}")
            ->line($this->headline)
            ->action('Track your order', $this->url)
            ->line("Thank you for ordering with {$this->workspace}.");
    }
}
