<?php

namespace App\Services;

use App\Models\MediaAccessRequest;
use App\Models\SystemSetting;
use App\Models\User;

class AccessEscalationService
{
    public function run(): array
    {
        $settings = SystemSetting::valueFor('notification.escalation', [
            'enabled'=>false,'first_after_hours'=>24,'repeat_every_hours'=>24,'max_escalations'=>3,
        ]);
        if (!($settings['enabled'] ?? false)) return ['enabled'=>false,'escalated'=>0];

        $firstHours = max(1, (int)($settings['first_after_hours'] ?? 24));
        $repeatHours = max(1, (int)($settings['repeat_every_hours'] ?? 24));
        $max = max(1, (int)($settings['max_escalations'] ?? 3));
        $count = 0;

        MediaAccessRequest::query()
            ->with(['mediaFile.department','user'])
            ->where('status','pending')
            ->where('created_at','<=',now()->subHours($firstHours))
            ->where('escalation_count','<',$max)
            ->where(function ($q) use ($repeatHours) {
                $q->whereNull('last_escalated_at')->orWhere('last_escalated_at','<=',now()->subHours($repeatHours));
            })
            ->orderBy('id')
            ->chunkById(100, function ($requests) use (&$count) {
                foreach ($requests as $accessRequest) {
                    $admins = User::query()->where('role','super-admin')
                        ->orWhere(function ($q) use ($accessRequest) {
                            $q->where('role','department-admin')->where('department_id',$accessRequest->mediaFile?->department_id);
                        })->get();
                    foreach ($admins as $admin) {
                        app(NotificationDeliveryService::class)->sendEvent(
                            $admin,
                            'access_request_escalated',
                            'Pending access request reminder',
                            ($accessRequest->user?->name ?? 'A user').' is still waiting for access to '.($accessRequest->mediaFile?->name ?? 'a protected file').'.',
                            ['access_request_id'=>$accessRequest->id,'media_file_id'=>$accessRequest->media_file_id,'escalation_count'=>$accessRequest->escalation_count + 1]
                        );
                    }
                    $accessRequest->forceFill([
                        'last_escalated_at'=>now(),
                        'escalation_count'=>$accessRequest->escalation_count + 1,
                    ])->save();
                    app(AuditService::class)->logSystem(
                        'access.escalated', $accessRequest, 'Pending protected access request escalated.',
                        ['escalation_count'=>$accessRequest->escalation_count]
                    );
                    $count++;
                }
            });

        return ['enabled'=>true,'escalated'=>$count];
    }
}
