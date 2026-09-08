<?php
namespace App\Services;

use App\Models\SystemSetting;

class NotificationChannelService
{
    public function configuration(): array
    {
        return SystemSetting::valueFor('notification.channels', []);
    }

    public function enabledChannelsFor(string $event): array
    {
        $settings = $this->configuration();
        $eventMap = $settings['events'][$event] ?? [];
        $enabled = [];
        foreach (['portal','email','whatsapp','sms'] as $channel) {
            if (($settings[$channel]['enabled'] ?? false) && ($eventMap[$channel] ?? false)) $enabled[] = $channel;
        }
        return $enabled;
    }

    public function providerSummary(): array
    {
        $settings = $this->configuration();
        return [
            'portal' => ['enabled'=>$settings['portal']['enabled'] ?? true, 'provider'=>'built-in'],
            'email' => ['enabled'=>$settings['email']['enabled'] ?? false, 'provider'=>$settings['email']['provider'] ?? 'smtp'],
            'whatsapp' => ['enabled'=>$settings['whatsapp']['enabled'] ?? false, 'provider'=>$settings['whatsapp']['provider'] ?? 'meta-cloud-api'],
            'sms' => ['enabled'=>$settings['sms']['enabled'] ?? false, 'provider'=>$settings['sms']['provider'] ?? 'generic-http'],
        ];
    }
}
