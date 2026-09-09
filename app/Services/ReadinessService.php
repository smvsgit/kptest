<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Models\IntegrationConnection;
use App\Models\MediaSource;
use App\Models\RestoreVerification;
use App\Models\ReadinessSnapshot;
use App\Models\GoLiveReview;
use App\Models\SystemSetting;
use App\Models\UatCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReadinessService
{
    public function __construct(
        private BackupService $backups,
        private AuditIntegrityService $auditIntegrity,
    ) {}

    public function settings(): array
    {
        return array_merge([
            'planned_go_live_at'=>'','deployment_owner'=>'','rollback_owner'=>'','business_signoff_owner'=>'',
            'smoke_test_owner'=>'','support_contact'=>'','rollback_window_minutes'=>0,'change_freeze_confirmed'=>false,
        ], SystemSetting::valueFor('go_live.settings', []));
    }

    public function dependencies(): array
    {
        return array_values(SystemSetting::valueFor('go_live.dependencies', []));
    }

    public function saveSettings(array $input): array
    {
        $value=[
            'planned_go_live_at'=>trim((string)($input['planned_go_live_at']??'')),
            'deployment_owner'=>trim((string)($input['deployment_owner']??'')),
            'rollback_owner'=>trim((string)($input['rollback_owner']??'')),
            'business_signoff_owner'=>trim((string)($input['business_signoff_owner']??'')),
            'smoke_test_owner'=>trim((string)($input['smoke_test_owner']??'')),
            'support_contact'=>trim((string)($input['support_contact']??'')),
            'rollback_window_minutes'=>max(0,(int)($input['rollback_window_minutes']??0)),
            'change_freeze_confirmed'=>(bool)($input['change_freeze_confirmed']??false),
        ];
        SystemSetting::put('go_live.settings',$value);
        return $value;
    }

    public function saveDependencies(array $items): array
    {
        $current=collect($this->dependencies())->keyBy('key');
        $allowed=['pending','resolved','accepted-risk'];
        $normalized=[];
        foreach($items as $item){
            $key=(string)($item['key']??'');
            if(!$current->has($key))continue;
            $base=$current[$key];
            $status=in_array($item['status']??'pending',$allowed,true)?$item['status']:'pending';
            $note=mb_substr(trim((string)($item['note']??'')),0,2000);
            if($status!=='pending'&&blank($note)){
                throw ValidationException::withMessages(['dependencies'=>"{$base['label']}: Resolved / Risk Accepted requires a decision, owner or approval reference note."]);
            }
            $normalized[]=['key'=>$key,'label'=>$base['label'],'status'=>$status,'note'=>$note];
        }
        foreach($current as $key=>$base)if(!collect($normalized)->contains(fn($x)=>$x['key']===$key))$normalized[]=$base;
        SystemSetting::put('go_live.dependencies',array_values($normalized));
        return array_values($normalized);
    }


    public function dashboardData(): array
    {
        $uat=UatCase::with(['executedBy:id,name','approvedBy:id,name'])->orderBy('sort_order')->get()->map(fn($c)=>[
            'id'=>$c->id,'code'=>$c->code,'title'=>$c->title,'priority'=>$c->priority,'category'=>$c->category,'status'=>$c->status,
            'execution_notes'=>$c->execution_notes,'evidence_reference'=>$c->evidence_reference,'executed_by'=>$c->executedBy?->name,
            'executed_at'=>$c->executed_at?->toIso8601String(),'executed_app_version'=>$c->executed_app_version,'approved_by'=>$c->approvedBy?->name,'approved_at'=>$c->approved_at?->toIso8601String(),'approved_app_version'=>$c->approved_app_version,
        ])->values();
        $snapshot=ReadinessSnapshot::with('runBy:id,name')->latest('id')->first();
        $review=GoLiveReview::with('reviewedBy:id,name')->latest('id')->first();
        return [
            'app_version'=>config('version.current'),
            'uat_cases'=>$uat,
            'go_live_settings'=>$this->settings(),
            'management_dependencies'=>$this->dependencies(),
            'latest_snapshot'=>$snapshot?[
                'id'=>$snapshot->id,'app_version'=>$snapshot->app_version,'overall_status'=>$snapshot->overall_status,'checks'=>$snapshot->checks,'summary'=>$snapshot->summary,
                'run_by'=>$snapshot->runBy?->name,'run_at'=>$snapshot->run_at?->toIso8601String(),
            ]:null,
            'latest_review'=>$review?[
                'id'=>$review->id,'decision'=>$review->decision,'app_version'=>$review->app_version,'notes'=>$review->notes,'dependency_snapshot'=>$review->dependency_snapshot,
                'reviewed_by'=>$review->reviewedBy?->name,'reviewed_at'=>$review->reviewed_at?->toIso8601String(),'readiness_snapshot_id'=>$review->readiness_snapshot_id,
            ]:null,
        ];
    }

    public function schedulerHeartbeat(): void
    {
        SystemSetting::put('readiness.scheduler_heartbeat',['at'=>now()->toIso8601String(),'host'=>gethostname()?:null]);
    }

    public function automaticChecks(): array
    {
        $checks=[];
        $push=function(string $key,string $label,string $status,bool $blocking,string $detail,array $meta=[])use(&$checks){
            $checks[]=['key'=>$key,'label'=>$label,'status'=>$status,'blocking'=>$blocking,'detail'=>$detail,'meta'=>$meta];
        };

        $configVersion=(string)config('version.current');
        $versionFile=is_file(base_path('VERSION'))?trim((string)file_get_contents(base_path('VERSION'))):'';
        $versionConsistent=$configVersion!==''&&$versionFile!==''&&hash_equals($configVersion,$versionFile);
        $push('app_version','Application release consistency',$versionConsistent?'pass':'fail',true,$versionConsistent?"VERSION/config agree on v{$configVersion}.":"VERSION/config mismatch: file='{$versionFile}', config='{$configVersion}'.");
        $appKey=(string)config('app.key');
        $push('app_key','APP_KEY configured',blank($appKey)?'fail':'pass',true,blank($appKey)?'APP_KEY is missing.':'APP_KEY is configured; key value is intentionally not displayed.');
        if(app()->environment('production'))$push('debug_mode','Production debug mode',config('app.debug')?'fail':'pass',true,config('app.debug')?'APP_DEBUG must be false in production.':'APP_DEBUG is disabled.');
        else $push('environment','Environment', 'warn', false, 'Current APP_ENV is '.app()->environment().'; production settings must be rechecked after deployment.');

        try{DB::select('SELECT 1');$push('database','Database connectivity','pass',true,'Database query succeeded ('.DB::getDriverName().').');}
        catch(\Throwable $e){$push('database','Database connectivity','fail',true,'Database connection failed: '.mb_substr($e->getMessage(),0,400));}

        try{
            $files=collect(glob(database_path('migrations/*.php')))->map(fn($f)=>basename($f,'.php'))->values();
            $ran=Schema::hasTable('migrations')?collect(DB::table('migrations')->pluck('migration')):collect();
            $pending=$files->diff($ran)->values();
            $push('migrations','Database migrations',$pending->isEmpty()?'pass':'fail',true,$pending->isEmpty()?'All migration files are recorded as run.':$pending->count().' migration(s) pending.',['pending'=>$pending->all()]);
        }catch(\Throwable $e){$push('migrations','Database migrations','fail',true,'Migration state could not be verified: '.mb_substr($e->getMessage(),0,400));}

        try{
            $probe='uploads/.readiness/probe-'.Str::uuid().'.txt';$disk=Storage::disk('media');$ok=$disk->put($probe,'karyalay-readiness')&&$disk->exists($probe);$disk->delete($probe);
            $host=(string)config('filesystems.media_upload_host_path','/srv/media/projects/karyalayportal/uploads');$container=(string)config('filesystems.media_upload_container_path',storage_path('app/media/uploads'));
            $push('media_storage','Persistent upload storage writable',$ok?'pass':'fail',true,$ok?"Write/read/delete probe passed on uploads bind: {$host} -> {$container}.":'Persistent uploads bind write probe failed.',['host_path'=>$host,'container_path'=>$container,'probe_relative_path'=>$probe]);
        }catch(\Throwable $e){$push('media_storage','Persistent upload storage writable','fail',true,'Media storage probe failed: '.mb_substr($e->getMessage(),0,400));}

        try{
            $failed=Schema::hasTable('failed_jobs')?(int)DB::table('failed_jobs')->count():0;
            $pending=Schema::hasTable('jobs')?(int)DB::table('jobs')->count():0;
            $push('queue','Queue health',$failed===0?'pass':'fail',true,$failed===0?"No failed jobs; {$pending} pending job(s).":"{$failed} failed job(s) require review; {$pending} pending.",['failed'=>$failed,'pending'=>$pending]);
        }catch(\Throwable $e){$push('queue','Queue health','fail',true,'Queue tables could not be inspected: '.mb_substr($e->getMessage(),0,400));}

        $heartbeat=SystemSetting::valueFor('readiness.scheduler_heartbeat',[]);$at=!empty($heartbeat['at'])?Carbon::parse($heartbeat['at']):null;
        $fresh=$at&&$at->gte(now()->subMinutes(5));
        $push('scheduler','Scheduler heartbeat',$fresh?'pass':'fail',true,$fresh?'Scheduler heartbeat received '.$at->diffForHumans().'.':'No scheduler heartbeat in the last 5 minutes. Ensure `php artisan schedule:run` is invoked every minute.');

        try{$audit=$this->auditIntegrity->verify();$push('audit_integrity','Audit hash-chain integrity',$audit['valid']?'pass':'fail',true,$audit['valid']?'Audit chain verified across '.($audit['checked']??0).' record(s).':'Audit chain invalid at record #'.($audit['invalid_id']??'?').': '.($audit['reason']??'unknown'),$audit);}
        catch(\Throwable $e){$push('audit_integrity','Audit hash-chain integrity','fail',true,'Audit verification failed: '.mb_substr($e->getMessage(),0,400));}

        $searchSettings=SystemSetting::valueFor('search.settings',[]);
        if(($searchSettings['enabled']??true)&&($searchSettings['engine_pipeline_enabled']??true)){
            try{$host=rtrim((string)config('search.host'),'/');$r=Http::timeout(3)->acceptJson()->get($host.'/health');$ok=$r->successful()&&($r->json('status')==='available'||$r->successful());$push('search_engine','Meilisearch availability',$ok?'pass':'warn',false,$ok?'Meilisearch health endpoint is reachable.':'Meilisearch is not reachable; DB fallback may work but relevance/performance UAT is required.');}
            catch(\Throwable $e){$push('search_engine','Meilisearch availability','warn',false,'Meilisearch health check failed; DB fallback remains available.');}
        } else $push('search_engine','Search engine pipeline','warn',false,'Search engine pipeline is disabled by configuration.');

        try{
            $unavailable=IntegrationConnection::query()->where('is_active',true)->where('status','unavailable')->count();
            $broken=MediaSource::query()->where('is_enabled',true)->whereIn('status',['missing','broken'])->count();
            $ok=$unavailable===0&&$broken===0;
            $push('integrations','Integration/source health',$ok?'pass':'fail',true,$ok?'No active unavailable integrations or broken/missing enabled sources.':"{$unavailable} unavailable connection(s), {$broken} broken/missing enabled source(s).",['unavailable_connections'=>$unavailable,'broken_sources'=>$broken]);
        }catch(\Throwable $e){$push('integrations','Integration/source health','fail',true,'Integration health could not be inspected: '.mb_substr($e->getMessage(),0,400));}

        try{
            $backup=$this->backups->statusSummary();
            $depByKey=collect($this->dependencies())->keyBy('key');
            $hasBackup=!empty($backup['latest_backup_id']);
            $verified=(bool)($backup['latest_backup_verified']??false);
            $rpo=(int)($backup['rpo_target_minutes']??0);$rto=(int)($backup['rto_target_minutes']??0);
            $targetRiskAccepted=($rpo<=0&&($depByKey['rpo_target']['status']??'pending')==='accepted-risk')||($rto<=0&&($depByKey['rto_target']['status']??'pending')==='accepted-risk');
            $targetInconsistent=($rpo<=0&&($depByKey['rpo_target']['status']??'pending')!=='accepted-risk')||($rto<=0&&($depByKey['rto_target']['status']??'pending')!=='accepted-risk');
            $retentionAccepted=($depByKey['backup_retention']['status']??'pending')==='accepted-risk';
            $retentionInconsistent=(bool)($backup['retention_policy_pending']??true)&&!$retentionAccepted;
            $ownerAccepted=($depByKey['backup_destination_owner']['status']??'pending')==='accepted-risk';
            $ownerMissing=blank($backup['responsible_owner']??null)&&!$ownerAccepted;
            $targetsMissed=($rpo>0&&($backup['rpo_met']??null)!==true)||($rto>0&&($backup['rto_met']??null)!==true);
            if(!$hasBackup)$push('backup_readiness','Backup/RPO readiness','fail',true,'No successful backup is recorded. Create and verify a current backup before go-live.',$backup);
            elseif(!$verified)$push('backup_readiness','Backup/RPO readiness','fail',true,'Latest successful backup does not have a current Passed checksum verification. Verify/re-create the backup before go-live.',$backup);
            elseif($ownerMissing)$push('backup_readiness','Backup/RPO readiness','fail',true,'Backup responsible owner is blank without formal Risk Accepted status. Record the owner in Backup & DR settings or formally accept the risk.',$backup);
            elseif($retentionInconsistent)$push('backup_readiness','Backup/RPO readiness','fail',true,'Backup retention is still 0 without formal Risk Accepted status. Configure approved retention or explicitly record Management risk acceptance.',$backup);
            elseif($targetInconsistent)$push('backup_readiness','Backup/RPO readiness','fail',true,'RPO/RTO target is still 0 without formal Risk Accepted status. Configure approved targets or explicitly record Management risk acceptance.',$backup);
            elseif($targetsMissed)$push('backup_readiness','Backup/RPO readiness','fail',true,'Configured RPO/RTO target is not met by the latest verified backup/restore evidence.',$backup);
            elseif($targetRiskAccepted||$retentionAccepted||$ownerAccepted)$push('backup_readiness','Backup/RPO readiness','warn',true,'Latest backup is verified, but one or more Backup/Retention/RPO/RTO controls are formally Risk Accepted. Go-live may proceed only under the recorded Management acceptance.',$backup);
            else $push('backup_readiness','Backup/RPO readiness','pass',true,'Latest backup is verified; responsible owner, retention and configured RPO/RTO evidence are within approved policy.',$backup);

            $restore=null;$restoreBackup=null;$restoreChecksum=null;$restoreRelease=null;
            $restoreCandidates=RestoreVerification::query()->with('backupRun')->where('verification_type','restore-test')->where('status','passed')->latest('verified_at')->limit(50)->get();
            foreach($restoreCandidates as $candidate){
                $candidateBackup=$candidate->backupRun;if(!$candidateBackup||$candidateBackup->status!=='success'||!$candidateBackup->verified_at)continue;
                $candidateRelease=$candidateBackup->manifest['app_version']??null;if($candidateRelease!==config('version.current'))continue;
                $candidateChecksum=$candidateBackup->verifications()->where('verification_type','checksum')->latest('verified_at')->latest('id')->first();
                if($candidateChecksum?->status!=='passed')continue;
                $restore=$candidate;$restoreBackup=$candidateBackup;$restoreChecksum=$candidateChecksum;$restoreRelease=$candidateRelease;break;
            }
            $restoreOkay=(bool)($restore&&$restore->verified_at&&$restoreBackup&&$restoreChecksum?->status==='passed');
            $detail=$restoreOkay
                ? 'Passed restore test recorded for current release v'.config('version.current').' using verified backup #'.$restoreBackup->id.' in '.($restore->target_environment?:'unspecified environment').' on '.$restore->verified_at->toDateTimeString().'.'
                : 'No Passed restore-test is linked to a currently verified backup created by release v'.config('version.current').'. Perform an actual staging restore for this release and record evidence.';
            $push('restore_test','Actual restore-test evidence',$restoreOkay?'pass':'fail',true,$detail,['restore_verification_id'=>$restore?->id,'backup_run_id'=>$restoreBackup?->id,'backup_app_version'=>$restoreRelease]);
        }catch(\Throwable $e){$push('backup_readiness','Backup/RPO readiness','fail',true,'Backup readiness could not be evaluated: '.mb_substr($e->getMessage(),0,400));}

        $uploadSettings=SystemSetting::valueFor('upload.settings',[]);$maxUploadMb=(int)($uploadSettings['max_file_size_mb']??0);$depByKey=collect($this->dependencies())->keyBy('key');$uploadDepStatus=$depByKey['max_upload_size']['status']??'pending';
        if($maxUploadMb>0)$push('upload_policy','Production maximum upload policy','pass',true,"Application maximum upload size is configured at {$maxUploadMb} MB.",['max_file_size_mb'=>$maxUploadMb]);
        elseif($uploadDepStatus==='accepted-risk')$push('upload_policy','Production maximum upload policy','warn',true,'Application max file size remains 0 (no app-level limit) under recorded Management Risk Acceptance. Infrastructure limits must still be validated.',['max_file_size_mb'=>0]);
        else $push('upload_policy','Production maximum upload policy','fail',true,'Application max file size is still 0 without formal Risk Accepted status. Configure the Management-approved limit in Upload & Integrity settings or record formal risk acceptance.',['max_file_size_mb'=>0,'dependency_status'=>$uploadDepStatus]);

        $notification=SystemSetting::valueFor('notification.channels',[]);$missing=[];
        if($notification['email']['enabled']??false){if(blank($notification['email']['host']??null)||blank($notification['email']['from_address']??null))$missing[]='Email';}
        if($notification['whatsapp']['enabled']??false){if(blank($notification['whatsapp']['endpoint']??null)||empty($notification['whatsapp']['token_encrypted']))$missing[]='WhatsApp';}
        if($notification['sms']['enabled']??false){if(blank($notification['sms']['endpoint']??null)||empty($notification['sms']['api_key_encrypted']))$missing[]='SMS';}
        $push('notification_config','Enabled notification provider configuration',$missing?'fail':'pass',true,$missing?'Enabled provider(s) missing required configuration: '.implode(', ',$missing).'.':'Enabled external notification providers have required configuration fields.');

        $uat=UatCase::query()->get();$currentVersion=(string)config('version.current');$passed=$uat->where('status','passed')->count();
        $approvedCurrent=$uat->filter(fn($case)=>$case->status==='passed'&&$case->approved_at&&$case->executed_app_version===$currentVersion&&$case->approved_app_version===$currentVersion)->count();
        $staleApprovals=$uat->filter(fn($case)=>$case->approved_at&&($case->executed_app_version!==$currentVersion||$case->approved_app_version!==$currentVersion))->count();
        $total=$uat->count();$failed=$uat->whereIn('status',['failed','blocked'])->count();
        $push('uat_matrix','P0 UAT matrix',$approvedCurrent===$total&&$total>0?'pass':($failed>0?'fail':'warn'),true,"{$passed}/{$total} executed as Passed; {$approvedCurrent}/{$total} signed off for v{$currentVersion}; {$staleApprovals} stale prior-release approval(s); {$failed} failed/blocked.",['passed'=>$passed,'approved_current_release'=>$approvedCurrent,'stale_approvals'=>$staleApprovals,'total'=>$total,'failed_or_blocked'=>$failed,'app_version'=>$currentVersion]);

        $deps=$this->dependencies();$depCollection=collect($deps);$pendingDeps=$depCollection->where('status','pending')->count();
        $invalidDecisions=$depCollection->filter(fn($d)=>in_array($d['status']??'pending',['resolved','accepted-risk'],true)&&blank($d['note']??null))->count();
        $depOkay=$pendingDeps===0&&$invalidDecisions===0;
        $push('management_dependencies','Management dependencies',$depOkay?'pass':'fail',true,$depOkay?'All tracked Management dependencies are resolved or formally risk-accepted with a recorded decision/approval note.':"{$pendingDeps} Pending; {$invalidDecisions} resolved/risk-accepted item(s) missing a decision note.",['pending'=>$pendingDeps,'missing_decision_note'=>$invalidDecisions]);

        $go=$this->settings();$missingOwners=[];foreach(['planned_go_live_at'=>'Planned go-live window','deployment_owner'=>'Deployment owner','rollback_owner'=>'Rollback owner','business_signoff_owner'=>'Business sign-off owner','smoke_test_owner'=>'Smoke-test owner','support_contact'=>'Support contact'] as $k=>$label)if(blank($go[$k]??null))$missingOwners[]=$label;
        if(!blank($go['planned_go_live_at']??null)){try{Carbon::parse((string)$go['planned_go_live_at']);}catch(\Throwable){$missingOwners[]='Valid planned go-live date/time';}}
        if((int)($go['rollback_window_minutes']??0)<=0)$missingOwners[]='Rollback decision window';
        if(!($go['change_freeze_confirmed']??false))$missingOwners[]='Change freeze confirmation';
        $push('operational_owners','Go-live owners / change freeze',$missingOwners?'fail':'pass',true,$missingOwners?'Missing/invalid: '.implode(', ',$missingOwners).'.':'Deployment, rollback, business sign-off, smoke-test and support ownership are recorded; go-live window is parseable and change freeze is confirmed.');

        return $checks;
    }

    public function summary(array $checks): array
    {
        $blockingFails=collect($checks)->where('blocking',true)->where('status','fail')->count();
        $warnings=collect($checks)->where('status','warn')->count();
        $passes=collect($checks)->where('status','pass')->count();
        return [
            'overall_status'=>$blockingFails>0?'blocked':($warnings>0?'ready-with-warnings':'ready'),
            'blocking_failures'=>$blockingFails,'warnings'=>$warnings,'passes'=>$passes,'total'=>count($checks),
            'go_live_allowed'=>$blockingFails===0,
        ];
    }

    public function runSnapshot(?int $userId): array
    {
        $checks=$this->automaticChecks();$summary=$this->summary($checks);
        $snapshot=\App\Models\ReadinessSnapshot::create(['overall_status'=>$summary['overall_status'],'app_version'=>config('version.current'),'checks'=>$checks,'summary'=>$summary,'run_by'=>$userId,'run_at'=>now()]);
        return ['snapshot'=>$snapshot,'checks'=>$checks,'summary'=>$summary];
    }
}
