<?php

namespace App\Services;

use App\Models\PortalNotification;
use App\Models\User;

class PortalNotificationService
{
    public function send(User $user, string $type, string $title, string $message, array $data = []): PortalNotification
    {
        return PortalNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data ?: null,
        ]);
    }
}
