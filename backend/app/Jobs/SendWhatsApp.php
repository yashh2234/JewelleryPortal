<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** @param list<string> $phones */
    public function __construct(public array $phones, public string $text) {}

    public function handle(WhatsApp $whatsapp): void
    {
        foreach (array_unique($this->phones) as $phone) {
            $whatsapp->send($phone, $this->text);
        }
    }
}
