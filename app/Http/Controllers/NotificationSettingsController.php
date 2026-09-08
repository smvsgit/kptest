<?php
namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\NotificationDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class NotificationSettingsController extends Controller
{
    public function update(Request $request, AuditService $audit)
    {
        $data=$request->validate([
            'portal.enabled'=>'required|boolean',
            'email.enabled'=>'required|boolean','email.provider'=>'required|string|max:40','email.host'=>'nullable|string|max:255','email.port'=>'nullable|integer|min:1|max:65535','email.encryption'=>'nullable|in:tls,ssl,none','email.username'=>'nullable|string|max:255','email.password'=>'nullable|string|max:1000','email.from_address'=>'nullable|email|max:255','email.from_name'=>'nullable|string|max:180',
            'whatsapp.enabled'=>'required|boolean','whatsapp.provider'=>'required|string|max:60','whatsapp.endpoint'=>'nullable|url|max:1000','whatsapp.phone_number_id'=>'nullable|string|max:180','whatsapp.business_account_id'=>'nullable|string|max:180','whatsapp.token'=>'nullable|string|max:2000','whatsapp.sender'=>'nullable|string|max:180',
            'sms.enabled'=>'required|boolean','sms.provider'=>'required|string|max:60','sms.endpoint'=>'nullable|url|max:1000','sms.api_key'=>'nullable|string|max:2000','sms.sender_id'=>'nullable|string|max:80',
            'events'=>'required|array','events.*'=>'array','events.*.portal'=>'required|boolean','events.*.email'=>'required|boolean','events.*.whatsapp'=>'required|boolean','events.*.sms'=>'required|boolean',
        ]);
        $current=SystemSetting::valueFor('notification.channels',[]);
        $before=$this->withoutSecrets($current);
        foreach ([['email','password'],['whatsapp','token'],['sms','api_key']] as [$channel,$secret]) {
            if (!empty($data[$channel][$secret])) {
                $data[$channel][$secret.'_encrypted']=Crypt::encryptString($data[$channel][$secret]);
                $data[$channel][$secret.'_set']=true;
            } else {
                if (!empty($current[$channel][$secret.'_encrypted'])) $data[$channel][$secret.'_encrypted']=$current[$channel][$secret.'_encrypted'];
                $data[$channel][$secret.'_set']=!empty($data[$channel][$secret.'_encrypted']);
            }
            unset($data[$channel][$secret]);
        }
        if (($data['email']['enabled'] ?? false) && (blank($data['email']['host'] ?? null) || blank($data['email']['from_address'] ?? null))) {
            return back()->withErrors(['email'=>'SMTP Host and From Address are required before enabling Email.']);
        }
        if (($data['whatsapp']['enabled'] ?? false) && (blank($data['whatsapp']['endpoint'] ?? null) || empty($data['whatsapp']['token_encrypted']))) {
            return back()->withErrors(['whatsapp'=>'WhatsApp endpoint and access token are required before enabling WhatsApp.']);
        }
        if (($data['sms']['enabled'] ?? false) && (blank($data['sms']['endpoint'] ?? null) || empty($data['sms']['api_key_encrypted']))) {
            return back()->withErrors(['sms'=>'SMS endpoint and API key are required before enabling Text SMS.']);
        }
        SystemSetting::put('notification.channels',$data);
        $audit->log($request,'settings.notifications.updated',null,'Notification channel settings updated.',['before'=>$before,'after'=>$this->withoutSecrets($data)]);
        return back()->with('success','Notification channel settings saved.');
    }

    public function updateEscalation(Request $request, AuditService $audit)
    {
        $data=$request->validate([
            'enabled'=>'required|boolean','first_after_hours'=>'required|integer|min:1|max:8760',
            'repeat_every_hours'=>'required|integer|min:1|max:8760','max_escalations'=>'required|integer|min:1|max:20',
        ]);
        $before=SystemSetting::valueFor('notification.escalation',[]);
        SystemSetting::put('notification.escalation',$data);
        $audit->log($request,'settings.notification-escalation.updated',null,'Notification escalation settings updated.',['before'=>$before,'after'=>$data]);
        return back()->with('success','Escalation settings saved.');
    }

    public function test(Request $request, NotificationDeliveryService $delivery, AuditService $audit)
    {
        $data=$request->validate(['channel'=>'required|in:portal,email,whatsapp,sms','recipient'=>'nullable|string|max:320']);
        if ($data['channel']!=='portal' && blank($data['recipient'] ?? null)) return response()->json(['message'=>'Recipient is required for external channel test.'],422);
        try {
            $row=$delivery->queueTest($data['channel'],(string)($data['recipient'] ?? ''),$request->user());
            $audit->log($request,'notification.test.queued',$row,'Notification provider test queued.',['channel'=>$data['channel'],'recipient'=>$data['recipient'] ?? 'self']);
            return response()->json(['message'=>$data['channel']==='portal'?'Portal test delivered.':ucfirst($data['channel']).' provider test queued. Check delivery report for final status.','delivery_id'=>$row->id]);
        } catch (\Throwable $e) {
            return response()->json(['message'=>$e->getMessage()],422);
        }
    }

    private function withoutSecrets(array $settings): array
    {
        foreach ([['email','password'],['whatsapp','token'],['sms','api_key']] as [$channel,$secret]) {
            unset($settings[$channel][$secret.'_encrypted'],$settings[$channel][$secret]);
        }
        return $settings;
    }
}
