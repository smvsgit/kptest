<?php
namespace App\Services;

use App\Models\IntegrationConnection;
use App\Models\IntegrationHealthCheck;
use App\Models\MediaSource;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IntegrationHealthService
{
    public function __construct(private NotificationDeliveryService $notifications) {}

    public function publicConnection(IntegrationConnection $c, bool $includeConfig = true): array
    {
        $metadata = $c->metadata ?? [];
        if ($c->type === 'local') {
            $metadata = array_merge($metadata, $this->localMountMetadata());
        }

        return [
            'id'=>$c->id,'name'=>$c->name,'type'=>$c->type,'is_active'=>$c->is_active,'root_path'=>$includeConfig?$c->root_path:null,'base_url'=>$includeConfig?$c->base_url:null,
            'credential_set'=>!blank($c->credentials_encrypted),'sync_frequency_minutes'=>$c->sync_frequency_minutes,'storage_warning_percent'=>$c->storage_warning_percent,
            'status'=>$c->status,'last_checked_at'=>$c->last_checked_at?->toIso8601String(),'last_success_at'=>$c->last_success_at?->toIso8601String(),
            'last_error'=>$c->last_error,'capacity_bytes'=>$c->capacity_bytes,'used_bytes'=>$c->used_bytes,'free_bytes'=>$c->free_bytes,'metadata'=>$metadata,
            'source_count'=>$c->sources_count ?? $c->sources()->count(),
        ];
    }

    public function publicSource(MediaSource $s, bool $includeLocator = true): array
    {
        return [
            'id'=>$s->id,'media_file_id'=>$s->media_file_id,'integration_connection_id'=>$s->integration_connection_id,'type'=>$s->type,'label'=>$s->label,
            'locator'=>$includeLocator?$s->locator:null,'external_id'=>$includeLocator?$s->external_id:null,'is_primary'=>$s->is_primary,'is_enabled'=>$s->is_enabled,'status'=>$s->status,
            'last_checked_at'=>$s->last_checked_at?->toIso8601String(),'last_success_at'=>$s->last_success_at?->toIso8601String(),
            'broken_detected_at'=>$s->broken_detected_at?->toIso8601String(),'last_error'=>$s->last_error,'repair_note'=>$s->repair_note,
            'open_url'=>in_array($s->type,['local','nas'],true)?route('files.sources.open',[$s->media_file_id,$s->id]):$s->open_url,'embed_url'=>$s->embed_url,'connection'=>$s->connection?->only(['id','name','type','status','is_active']),
        ];
    }

    public function checkConnection(IntegrationConnection $c): array
    {
        $started=microtime(true); $before=$c->status; $status='healthy'; $message='Connection available.'; $meta=[]; $capacity=$used=$free=null;
        if(!$c->is_active){$status='inactive';$message='Connection is disabled by Central Admin.';}
        else try {
            if($c->type==='local') {
                $path=Storage::disk('media')->path('uploads'); if(!is_dir($path)||!is_readable($path)||!is_writable($path))throw new \RuntimeException('Persistent local upload bind path is unavailable or not writable.');
                [$capacity,$used,$free]=$this->diskUsage($path);
                $meta=$this->localMountMetadata()+['checked_path'=>$path];
            } elseif($c->type==='nas') {
                $path=trim((string)$c->root_path); if($path===''||!is_dir($path)||!is_readable($path))throw new \RuntimeException('NAS root path is missing or not readable.');
                [$capacity,$used,$free]=$this->diskUsage($path);
            } elseif($c->type==='google-drive') {
                $cred=$this->credentials($c); $req=Http::acceptJson()->timeout(12);
                if(!empty($cred['access_token'])) {
                    $req=$req->withToken($cred['access_token']);
                    $r=$req->get('https://www.googleapis.com/drive/v3/about?fields=user,storageQuota'); $r->throw(); $q=$r->json('storageQuota')??[];
                    $capacity=isset($q['limit'])?(int)$q['limit']:null; $used=isset($q['usage'])?(int)$q['usage']:null; $free=$capacity!==null&&$used!==null?max(0,$capacity-$used):null; $meta=['user'=>$r->json('user.displayName'),'credential_mode'=>'oauth-token'];
                } elseif(!empty($cred['api_key'])) {
                    $status='degraded'; $message='Google Drive API key is configured for public per-file checks; account-level health/quota requires an access token.'; $meta=['credential_mode'=>'api-key'];
                } else throw new \RuntimeException('Google Drive credential is not configured.');
            } elseif($c->type==='youtube') {
                $r=Http::timeout(12)->get('https://www.youtube.com/oembed',['url'=>'https://www.youtube.com/watch?v=dQw4w9WgXcQ','format'=>'json']); $r->throw();
            } else throw new \RuntimeException('Unsupported integration type.');
            if($capacity && $used!==null){$pct=round(($used/$capacity)*100,1);$meta['used_percent']=$pct;if($pct>=(int)$c->storage_warning_percent){$status='degraded';$message="Storage usage is {$pct}% (warning at {$c->storage_warning_percent}%).";}}
        } catch(\Throwable $e){$status='unavailable';$message=mb_substr($e->getMessage(),0,1000);}
        $latency=(int)round((microtime(true)-$started)*1000); $now=now();
        $c->forceFill(['status'=>$status,'last_checked_at'=>$now,'last_success_at'=>in_array($status,['healthy','degraded'],true)?$now:$c->last_success_at,'last_error'=>in_array($status,['healthy','degraded'],true)?null:$message,'capacity_bytes'=>$capacity,'used_bytes'=>$used,'free_bytes'=>$free,'metadata'=>array_merge($c->metadata??[],$meta)])->save();
        IntegrationHealthCheck::create(['integration_connection_id'=>$c->id,'status'=>$status,'latency_ms'=>$latency,'message'=>$message,'metadata'=>$meta,'checked_at'=>$now]);
        if($status==='degraded'&&$before!=='degraded')$this->alertAdmins('storage_warning','Storage warning',"{$c->name}: {$message}",['connection_id'=>$c->id]);
        if($status==='unavailable'&&$before!=='unavailable')$this->alertAdmins('broken_link_detected','Integration unavailable',"{$c->name}: {$message}",['connection_id'=>$c->id]);
        return ['status'=>$status,'message'=>$message,'latency_ms'=>$latency];
    }

    public function checkSource(MediaSource $s): array
    {
        $s->loadMissing(['connection','mediaFile.ownerUser']); $before=$s->status; $started=microtime(true); $status='active'; $message='Source available.'; $meta=[];
        if(!$s->is_enabled){$status='inactive';$message='Source is disabled.';} elseif($s->connection && !$s->connection->is_active){$status='inactive';$message='Integration connection is disabled.';}
        else try {
            if($s->type==='local') {
                if(!Storage::disk('media')->exists($s->locator)){$status='missing';$message='Local source file is missing.';}
            } elseif($s->type==='nas') {
                $root=$s->connection?->root_path; if(!$root)throw new \RuntimeException('NAS connection/root path is not configured.'); $path=$this->safePath($root,$s->locator); if(!is_file($path)){$status='missing';$message='NAS source file is missing.';} elseif(!is_readable($path))throw new \RuntimeException('NAS source file is not readable.');
            } elseif($s->type==='google-drive') {
                $id=$s->driveId(); if(!$id)throw new \RuntimeException('Google Drive file ID cannot be resolved.'); $cred=$this->credentials($s->connection);
                if(!empty($cred['access_token'])||!empty($cred['api_key'])) {
                    $req=Http::acceptJson()->timeout(12); if(!empty($cred['access_token']))$req=$req->withToken($cred['access_token']); $url="https://www.googleapis.com/drive/v3/files/{$id}?fields=id,name,mimeType,size,modifiedTime,trashed"; if(empty($cred['access_token'])&&!empty($cred['api_key']))$url.='&key='.urlencode($cred['api_key']); $r=$req->get($url); if($r->status()===404){$status='missing';$message='Google Drive file was not found.';} else {$r->throw(); if($r->json('trashed')){$status='missing';$message='Google Drive file is in Trash.';} $meta=['name'=>$r->json('name'),'mimeType'=>$r->json('mimeType'),'size'=>$r->json('size'),'modifiedTime'=>$r->json('modifiedTime')];}
                } else {
                    $r=Http::timeout(12)->get("https://drive.google.com/file/d/{$id}/view"); if(!$r->successful())throw new \RuntimeException('Public Google Drive reference is not reachable; configure credentials for private files.'); $meta=['credential_mode'=>'public-reference'];
                }
            } elseif($s->type==='youtube') {
                $url=$s->open_url; if(!$url)throw new \RuntimeException('YouTube video ID/URL is invalid.'); $r=Http::timeout(12)->get('https://www.youtube.com/oembed',['url'=>$url,'format'=>'json']); if(in_array($r->status(),[401,403,404],true)){$status='broken';$message='YouTube video is unavailable, private, removed or embedding metadata cannot be read.';} else {$r->throw();$meta=['title'=>$r->json('title'),'author_name'=>$r->json('author_name'),'thumbnail_url'=>$r->json('thumbnail_url')];}
            } else throw new \RuntimeException('Unsupported source type.');
        } catch(\Throwable $e){if($status==='active')$status='broken';$message=mb_substr($e->getMessage(),0,1000);}
        $now=now(); $newBroken=in_array($status,['missing','broken'],true)&&!in_array($before,['missing','broken'],true); $recovered=$status==='active'&&in_array($before,['missing','broken'],true);
        $s->forceFill(['status'=>$status,'last_checked_at'=>$now,'last_success_at'=>$status==='active'?$now:$s->last_success_at,'broken_detected_at'=>in_array($status,['missing','broken'],true)?($s->broken_detected_at?:$now):null,'last_error'=>$status==='active'?null:$message,'metadata'=>array_merge($s->metadata??[],$meta)])->save();
        IntegrationHealthCheck::create(['integration_connection_id'=>$s->integration_connection_id,'media_source_id'=>$s->id,'status'=>$status,'latency_ms'=>(int)round((microtime(true)-$started)*1000),'message'=>$message,'metadata'=>$meta,'checked_at'=>$now]);
        if($newBroken)$this->alertSourceOwner($s,'broken_link_detected','Source problem detected',"{$s->mediaFile?->name}: {$message}");
        if($recovered)$this->alertSourceOwner($s,'file_updated','Source recovered',"{$s->mediaFile?->name}: source is available again.");
        return ['status'=>$status,'message'=>$message];
    }

    public function runDueChecks(): array
    {
        $connections=0;$sources=0;
        IntegrationConnection::query()->where('is_active',true)->get()->each(function($c)use(&$connections){$due=!$c->last_checked_at||$c->last_checked_at->lte(now()->subMinutes(max(15,(int)$c->sync_frequency_minutes)));if($due){$this->checkConnection($c);$connections++;}});
        MediaSource::query()->with('connection')->where('is_enabled',true)->get()->each(function($s)use(&$sources){$freq=max(15,(int)($s->connection?->sync_frequency_minutes??60));$due=!$s->last_checked_at||$s->last_checked_at->lte(now()->subMinutes($freq));if($due){$this->checkSource($s);$sources++;}});
        return compact('connections','sources');
    }

    private function credentials(?IntegrationConnection $c): array { if(!$c||blank($c->credentials_encrypted))return []; try{return json_decode(Crypt::decryptString($c->credentials_encrypted),true)?:[];}catch(\Throwable){return [];} }
    private function localMountMetadata(): array
    {
        $containerPath = (string) config('filesystems.media_upload_container_path', storage_path('app/media/uploads'));
        return [
            'persistent_upload_bind' => true,
            'host_upload_path' => (string) config('filesystems.media_upload_host_path', '/srv/media/projects/karyalayportal/uploads'),
            'container_upload_path' => $containerPath,
            'health_probe_path' => Storage::disk('media')->path('uploads'),
            'mount_note' => 'Uploaded source bytes use the nested /uploads bind mount; parent /storage remains the app-storage named volume.',
        ];
    }

    private function diskUsage(string $path): array { $cap=@disk_total_space($path);$free=@disk_free_space($path);return [$cap!==false?(int)$cap:null,$cap!==false&&$free!==false?(int)($cap-$free):null,$free!==false?(int)$free:null]; }
    private function safePath(string $root,string $locator): string { $rootReal=realpath($root); if($rootReal===false)throw new \RuntimeException('NAS root path is unavailable.'); $rootReal=rtrim($rootReal,DIRECTORY_SEPARATOR); $rel=ltrim(str_replace(chr(0),'',$locator),'/\\'); if(str_contains($rel,'../')||str_contains($rel,'..\\'))throw new \RuntimeException('Unsafe NAS source path.'); $candidate=$rootReal.DIRECTORY_SEPARATOR.$rel; $resolved=realpath($candidate); if($resolved!==false && $resolved!==$rootReal && !str_starts_with($resolved,$rootReal.DIRECTORY_SEPARATOR))throw new \RuntimeException('NAS source resolves outside configured root.'); return $resolved!==false?$resolved:$candidate; }
    private function alertAdmins(string $event,string $title,string $message,array $data=[]): void { User::query()->where('role','super-admin')->where('status','active')->get()->each(fn($u)=>$this->notifications->sendEvent($u,$event,$title,$message,$data)); }
    private function alertSourceOwner(MediaSource $s,string $event,string $title,string $message): void { $users=collect(); if($s->mediaFile?->ownerUser)$users->push($s->mediaFile->ownerUser); User::query()->where('role','super-admin')->where('status','active')->get()->each(fn($u)=>$users->push($u)); $users->unique('id')->each(fn($u)=>$this->notifications->sendEvent($u,$event,$title,$message,['media_file_id'=>$s->media_file_id,'source_id'=>$s->id])); }
}
