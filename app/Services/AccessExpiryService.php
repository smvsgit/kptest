<?php
namespace App\Services;
use App\Models\MediaAccessRequest;
class AccessExpiryService {
 public function run(): array { $rows=MediaAccessRequest::with(['user','mediaFile'])->where('status','approved')->whereNotNull('expires_at')->where('expires_at','<=',now())->get();$n=0;foreach($rows as $r){$r->update(['status'=>'expired','revoked_at'=>now(),'revoke_reason'=>'Temporary protected access expired automatically.']);try{app(AuditService::class)->logSystem('access.expired',$r,'Temporary protected access expired automatically.',['media_file_id'=>$r->media_file_id]);}catch(\Throwable $e){report($e);}if($r->user)$this->notify($r);$n++;}return ['expired'=>$n]; }
 private function notify(MediaAccessRequest $r):void { try{app(NotificationDeliveryService::class)->sendEvent($r->user,'access_request_decided','Temporary access expired','Your temporary access to '.$r->mediaFile?->name.' has expired.',['access_request_id'=>$r->id,'status'=>'expired']);}catch(\Throwable $e){report($e);} }
}
