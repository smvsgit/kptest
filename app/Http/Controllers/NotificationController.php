<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\PortalNotification;
use App\Services\AuditService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function markRead(Request $request, PortalNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['read_at' => now()]);
        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllRead(Request $request)
    {
        PortalNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['message' => 'Notifications marked as read.']);
    }

    public function preferences(Request $request, AuditService $audit)
    {
        $data=$request->validate([
            'portal_enabled'=>'required|boolean','email_enabled'=>'required|boolean','whatsapp_enabled'=>'required|boolean','sms_enabled'=>'required|boolean',
            'access_enabled'=>'required|boolean','file_enabled'=>'required|boolean','storage_enabled'=>'required|boolean','security_enabled'=>'required|boolean',
        ]);
        $pref=NotificationPreference::firstOrCreate(['user_id'=>$request->user()->id]);
        $before=$pref->only(array_keys($data));
        $pref->fill($data)->save();
        $audit->log($request,'notification.preferences.updated',$pref,'User notification preferences updated.',['before'=>$before,'after'=>$data]);
        return response()->json(['message'=>'Notification preferences saved.','preferences'=>$pref->fresh()]);
    }
}
