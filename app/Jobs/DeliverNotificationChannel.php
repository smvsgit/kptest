<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverNotificationChannel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 45;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array { return [60, 300, 900]; }

    public function handle(NotificationDeliveryService $service): void
    {
        $delivery = NotificationDelivery::find($this->deliveryId);
        if (!$delivery) return;
        $service->deliver($delivery);
    }
}
