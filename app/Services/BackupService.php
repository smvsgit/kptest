<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Models\RestoreVerification;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BackupService
{
    public const FORMAT = 'kp-backup-v1';

    /** Parent-first order also serves as the restore insert order. */
    public const CORE_TABLES = [
        'departments','organization_units','permission_sets','users','user_status_histories',
        'uat_cases','readiness_snapshots','go_live_reviews',
        'categories','subcategories','master_data_values','font_families','font_files',
        'media_files','media_file_versions','media_access_requests','approval_delegations',
        'saved_searches','media_favorites','media_recent_views',
        'portal_notifications','notification_preferences','notification_deliveries',
        'integration_connections','integration_health_checks','media_sources',
    ];
    public const CONFIG_TABLES = ['system_settings'];
    public const AUDIT_TABLES = ['audit_logs'];

    public function __construct(
        private AuditService $audit,
        private NotificationDeliveryService $notifications,
    ) {}

    public function settings(): array
    {
        return array_merge($this->defaults(), SystemSetting::valueFor('backup.settings', []));
    }

    public function saveSettings(array $input): array
    {
        $settings = [
            'destination_type' => in_array($input['destination_type'] ?? 'local',['local','filesystem'],true) ? $input['destination_type'] : 'local',
            'filesystem_path' => trim((string)($input['filesystem_path'] ?? '')),
            'responsible_owner' => trim((string)($input['responsible_owner'] ?? '')),
            'daily_enabled' => (bool)($input['daily_enabled'] ?? false),
            'daily_hour' => min(23,max(0,(int)($input['daily_hour'] ?? 2))),
            'weekly_enabled' => (bool)($input['weekly_enabled'] ?? false),
            'weekly_day' => min(6,max(0,(int)($input['weekly_day'] ?? 0))),
            'weekly_hour' => min(23,max(0,(int)($input['weekly_hour'] ?? 3))),
            'monthly_enabled' => (bool)($input['monthly_enabled'] ?? false),
            'monthly_day' => min(28,max(1,(int)($input['monthly_day'] ?? 1))),
            'monthly_hour' => min(23,max(0,(int)($input['monthly_hour'] ?? 4))),
            'daily_retention_days' => max(0,(int)($input['daily_retention_days'] ?? 0)),
            'weekly_retention_days' => max(0,(int)($input['weekly_retention_days'] ?? 0)),
            'monthly_retention_days' => max(0,(int)($input['monthly_retention_days'] ?? 0)),
            'rpo_minutes' => max(0,(int)($input['rpo_minutes'] ?? 0)),
            'rto_minutes' => max(0,(int)($input['rto_minutes'] ?? 0)),
            'backup_database' => (bool)($input['backup_database'] ?? true),
            'backup_configuration' => (bool)($input['backup_configuration'] ?? true),
            'backup_audit_security' => (bool)($input['backup_audit_security'] ?? true),
            'external_source_responsibility' => trim((string)($input['external_source_responsibility'] ?? $this->defaults()['external_source_responsibility'])),
        ];
        if (!$settings['backup_database'] && !$settings['backup_configuration'] && !$settings['backup_audit_security']) {
            throw new RuntimeException('At least one backup scope must be enabled.');
        }
        if ($settings['destination_type']==='filesystem') $this->validatedFilesystemDirectory($settings['filesystem_path']);
        SystemSetting::put('backup.settings',$settings);
        return $settings;
    }

    public function createBackup(?User $actor = null, string $trigger = 'manual', string $retentionClass = 'manual'): BackupRun
    {
        $settings=$this->settings();
        $run=BackupRun::create([
            'trigger'=>$trigger,'retention_class'=>$retentionClass,'status'=>'running','destination_type'=>$settings['destination_type'],
            'triggered_by'=>$actor?->id,'started_at'=>now(),'encrypted'=>true,'format_version'=>self::FORMAT,
            'scope'=>$this->scopeFromSettings($settings),
        ]);
        try {
            $payload=$this->buildPayload($settings,$run);
            $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $compressed=gzencode($json,6);
            if ($compressed===false) throw new RuntimeException('Backup compression failed.');
            $envelope=json_encode([
                'format'=>self::FORMAT,
                'payload_sha256'=>hash('sha256',$compressed),
                'payload_base64'=>base64_encode($compressed),
            ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $encrypted=Crypt::encryptString($envelope);
            $filename='karyalay-portal-'.now()->format('Ymd-His').'-'.$run->id.'.kpbackup';
            $storagePath=$this->writeBackupFile($settings,$filename,$encrypted);
            $sha=hash('sha256',$encrypted);
            $size=strlen($encrypted);
            $run->forceFill([
                'status'=>'success','storage_path'=>$storagePath,'filename'=>$filename,'size_bytes'=>$size,'sha256'=>$sha,
                'manifest'=>$payload['manifest'],'completed_at'=>now(),'error'=>null,
            ])->save();
            $this->audit->logSystem('backup.completed',$run,'Portal backup completed.',[
                'trigger'=>$trigger,'retention_class'=>$retentionClass,'size_bytes'=>$size,'sha256'=>$sha,'destination_type'=>$settings['destination_type'],
            ],$trigger==='manual'?'system':'scheduler');
            // Every completed archive is immediately read back, decrypted and checksum/table-count verified.
            $verification=$this->verifyBackup($run,null,'Automatic post-backup integrity verification.');
            if($verification->status!=='passed') throw new RuntimeException('Automatic post-backup integrity verification failed.');
            if($trigger!=='manual')$this->pruneRetention();
            return $run->fresh();
        } catch (\Throwable $e) {
            $run->forceFill(['status'=>'failed','completed_at'=>now(),'error'=>mb_substr($e->getMessage(),0,4000)])->save();
            $this->audit->logSystem('backup.failed',$run,'Portal backup failed.',['error'=>$run->error],$trigger==='manual'?'system':'scheduler');
            $this->alertAdmins('backup_failed','Backup failed','Karyalay Portal backup failed: '.$run->error,['backup_run_id'=>$run->id]);
            throw $e;
        }
    }

    public function verifyBackup(BackupRun $run, ?User $actor = null, string $notes = ''): RestoreVerification
    {
        $result=['exists'=>false,'file_sha256_match'=>false,'payload_sha256_match'=>false,'manifest_counts_match'=>false];
        try {
            $raw=$this->readRunFile($run); $result['exists']=true;
            $fileSha=hash('sha256',$raw); $result['file_sha256_match']=hash_equals((string)$run->sha256,$fileSha);
            $payload=$this->decodeEncryptedPayload($raw,$result);
            $manifestCounts=(array)($payload['manifest']['table_counts']??[]);
            $payloadCounts=[]; foreach((array)($payload['tables']??[]) as $table=>$rows)$payloadCounts[$table]=count($rows);
            $result['manifest_counts_match']=$manifestCounts===$payloadCounts;
            $passed=$result['file_sha256_match']&&$result['payload_sha256_match']&&$result['manifest_counts_match'];
            $verification=RestoreVerification::create([
                'backup_run_id'=>$run->id,'verification_type'=>'checksum','status'=>$passed?'passed':'failed',
                'verified_size_bytes'=>strlen($raw),'verified_sha256'=>$fileSha,'results'=>$result,'notes'=>$notes?:null,
                'verified_by'=>$actor?->id,'verified_at'=>now(),
            ]);
            $run->forceFill(['verified_at'=>$passed?now():null])->save();
            $this->audit->logSystem($passed?'backup.verified':'backup.verification-failed',$run,$passed?'Backup integrity verified.':'Backup integrity verification failed.',$result,'system');
            if(!$passed)$this->alertAdmins('backup_failed','Backup verification failed','A Karyalay Portal backup failed integrity verification.',['backup_run_id'=>$run->id]);
            return $verification;
        } catch(\Throwable $e) {
            $run->forceFill(['verified_at'=>null])->saveQuietly();
            $result['error']=mb_substr($e->getMessage(),0,2000);
            $verification=RestoreVerification::create([
                'backup_run_id'=>$run->id,'verification_type'=>'checksum','status'=>'failed','results'=>$result,'notes'=>$notes?:null,
                'verified_by'=>$actor?->id,'verified_at'=>now(),
            ]);
            $this->audit->logSystem('backup.verification-failed',$run,'Backup integrity verification failed.',$result,'system');
            throw $e;
        }
    }

    public function recordRestoreTest(BackupRun $run, User $actor, string $status, int $durationMinutes, string $environment, ?string $notes): RestoreVerification
    {
        if(!in_array($status,['passed','failed'],true))throw new RuntimeException('Invalid restore verification status.');
        if($status==='passed') {
            $latestChecksum=$run->verifications()->where('verification_type','checksum')->latest('verified_at')->latest('id')->first();
            if($run->status!=='success'||!$run->verified_at||!$latestChecksum||$latestChecksum->status!=='passed') {
                throw ValidationException::withMessages(['status'=>'A Passed restore test requires a successful backup whose latest checksum verification is Passed. Verify the backup first.']);
            }
        }
        $verification=RestoreVerification::create([
            'backup_run_id'=>$run->id,'verification_type'=>'restore-test','status'=>$status,'target_environment'=>trim($environment),
            'duration_minutes'=>$durationMinutes,'notes'=>$notes,'verified_by'=>$actor->id,'verified_at'=>now(),
            'results'=>['backup_verified_at'=>$run->verified_at?->toIso8601String(),'backup_sha256'=>$run->sha256],
        ]);
        $this->audit->logSystem('backup.restore-test-recorded',$run,'Restore test verification recorded.',[
            'status'=>$status,'duration_minutes'=>$durationMinutes,'target_environment'=>$environment,'verified_by'=>$actor->id,
        ],'system');
        return $verification;
    }

    public function runScheduledIfDue(?Carbon $now = null): array
    {
        $now=$now?:now(); $s=$this->settings(); $created=[];
        foreach(['daily','weekly','monthly'] as $class){
            if(!($s[$class.'_enabled']??false) || !$this->scheduleDue($class,$s,$now))continue;
            $created[]=$this->createBackup(null,'scheduled',$class)->id;
        }
        $this->monitorRpo();
        return ['created'=>$created,'count'=>count($created),'readiness'=>$this->statusSummary()['readiness']];
    }

    public function pruneRetention(): array
    {
        $s=$this->settings(); $pruned=[];
        foreach(['daily','weekly','monthly'] as $class){
            $days=(int)($s[$class.'_retention_days']??0); if($days<=0)continue;
            BackupRun::query()->where('status','success')->where('retention_class',$class)->where('completed_at','<',now()->subDays($days))->get()->each(function(BackupRun $run)use(&$pruned){
                $this->deleteRunFile($run); $run->forceFill(['status'=>'pruned','storage_path'=>null,'error'=>null])->save(); $pruned[]=$run->id;
                $this->audit->logSystem('backup.pruned',$run,'Backup file pruned by approved retention rule.',['retention_class'=>$run->retention_class],'scheduler');
            });
        }
        return ['pruned'=>$pruned,'count'=>count($pruned)];
    }

    public function statusSummary(): array
    {
        $s=$this->settings();
        $latest=BackupRun::query()->where('status','success')->latest('completed_at')->first();
        $latestChecksum=$latest?->verifications()->where('verification_type','checksum')->latest('verified_at')->latest('id')->first();
        $latestVerified=(bool)($latest&&$latest->verified_at&&$latestChecksum&&$latestChecksum->status==='passed');
        $lastRestore=RestoreVerification::query()->where('verification_type','restore-test')->where('status','passed')->latest('verified_at')->first();
        $rpo=(int)$s['rpo_minutes'];$rto=(int)$s['rto_minutes'];
        $age=$latest?->completed_at ? (int)$latest->completed_at->diffInMinutes(now()) : null;
        $rpoMet=$rpo>0&&$age!==null ? $age<=$rpo : null;
        $rtoMet=$rto>0&&$lastRestore?->duration_minutes!==null ? $lastRestore->duration_minutes<=$rto : null;
        $policyPending=$rpo<=0||$rto<=0;
        $retentionPending=(int)$s['daily_retention_days']<=0&&(int)$s['weekly_retention_days']<=0&&(int)$s['monthly_retention_days']<=0;
        $readiness=$policyPending?'policy-pending':(($latestVerified&&$rpoMet===true&&$rtoMet===true)?'ready':'warning');
        return [
            'readiness'=>$readiness,'latest_backup_at'=>$latest?->completed_at?->toIso8601String(),'latest_backup_id'=>$latest?->id,
            'latest_backup_verified'=>$latestVerified,'latest_backup_verified_at'=>$latest?->verified_at?->toIso8601String(),
            'latest_backup_verification_status'=>$latestChecksum?->status,
            'latest_backup_age_minutes'=>$age,'last_restore_test_at'=>$lastRestore?->verified_at?->toIso8601String(),
            'last_restore_duration_minutes'=>$lastRestore?->duration_minutes,'rpo_target_minutes'=>$rpo,'rto_target_minutes'=>$rto,
            'rpo_met'=>$rpoMet,'rto_met'=>$rtoMet,'retention_policy_pending'=>$retentionPending,
            'schedule_enabled'=>(bool)$s['daily_enabled']||(bool)$s['weekly_enabled']||(bool)$s['monthly_enabled'],
            'destination_type'=>$s['destination_type'],'responsible_owner'=>$s['responsible_owner'],
            'app_key_custody_required'=>true,
        ];
    }

    public function monitorRpo(): void
    {
        $summary=$this->statusSummary();
        if($summary['rpo_target_minutes']>0&&$summary['rpo_met']===false){
            $recent=DB::table('notification_deliveries')->where('event','backup_rpo_warning')->where('created_at','>=',now()->subHours(6))->exists();
            if(!$recent)$this->alertAdmins('backup_rpo_warning','Backup RPO warning','Latest successful portal backup is older than the configured RPO target.',$summary);
        }
    }

    public function publicRun(BackupRun $run): array
    {
        return [
            'id'=>$run->id,'trigger'=>$run->trigger,'retention_class'=>$run->retention_class,'status'=>$run->status,'destination_type'=>$run->destination_type,
            'storage_path'=>$run->storage_path,'filename'=>$run->filename,'size_bytes'=>$run->size_bytes,'sha256'=>$run->sha256,'format_version'=>$run->format_version,
            'encrypted'=>$run->encrypted,'scope'=>$run->scope,'manifest'=>$run->manifest,'triggered_by'=>$run->triggeredBy?->name,
            'started_at'=>$run->started_at?->toIso8601String(),'completed_at'=>$run->completed_at?->toIso8601String(),'verified_at'=>$run->verified_at?->toIso8601String(),'error'=>$run->error,
        ];
    }

    public function publicVerification(RestoreVerification $v): array
    {
        return [
            'id'=>$v->id,'backup_run_id'=>$v->backup_run_id,'verification_type'=>$v->verification_type,'status'=>$v->status,
            'target_environment'=>$v->target_environment,'duration_minutes'=>$v->duration_minutes,'results'=>$v->results,'notes'=>$v->notes,
            'verified_by'=>$v->verifiedBy?->name,'verified_at'=>$v->verified_at?->toIso8601String(),
        ];
    }

    public function readRunFile(BackupRun $run): string
    {
        if(!$run->storage_path)throw new RuntimeException('Backup file path is not available.');
        if($run->destination_type==='local'){
            if(!Storage::disk('local')->exists($run->storage_path))throw new RuntimeException('Backup file is missing from local protected storage.');
            return (string)Storage::disk('local')->get($run->storage_path);
        }
        if(!is_file($run->storage_path)||!is_readable($run->storage_path))throw new RuntimeException('Backup file is missing or unreadable on the configured filesystem destination.');
        $data=file_get_contents($run->storage_path); if($data===false)throw new RuntimeException('Could not read backup file.'); return $data;
    }

    /** Used by the guarded console restore command. Target must be a fresh migrated database. */
    public function restoreFileToFreshDatabase(string $path): array
    {
        $raw=$this->readArbitraryPath($path);$verify=[];$payload=$this->decodeEncryptedPayload($raw,$verify);
        if(!($verify['payload_sha256_match']??false))throw new RuntimeException('Backup payload checksum failed.');
        $tables=(array)($payload['tables']??[]); if(!$tables)throw new RuntimeException('Backup contains no database tables.');
        if(DB::table('users')->count()>0||DB::table('media_files')->count()>0||DB::table('audit_logs')->count()>0||DB::table('media_access_requests')->count()>0)throw new RuntimeException('Restore target is not a fresh migrated database. Existing portal user/business/audit data was detected.');
        $driver=DB::getDriverName();
        DB::beginTransaction();
        try{
            $this->setForeignKeys(false,$driver);
            foreach(array_reverse(array_keys($tables)) as $table){ if(!in_array($table,$this->allBackupTables(),true))continue; DB::table($table)->delete(); }
            $inserted=[];
            foreach($this->allBackupTables() as $table){ if(!array_key_exists($table,$tables))continue; $rows=(array)$tables[$table]; foreach(array_chunk($rows,200) as $chunk)if($chunk)DB::table($table)->insert($chunk); $inserted[$table]=count($rows); }
            $counts=[];foreach($inserted as $table=>$expected){$actual=DB::table($table)->count();$counts[$table]=['expected'=>$expected,'actual'=>$actual,'match'=>$actual===$expected];}
            if(collect($counts)->contains(fn($v)=>!$v['match']))throw new RuntimeException('Post-restore row-count verification failed.');
            $this->setForeignKeys(true,$driver);DB::commit();
            return ['status'=>'passed','table_counts'=>$counts,'manifest'=>$payload['manifest']??[]];
        }catch(\Throwable $e){try{$this->setForeignKeys(true,$driver);}catch(\Throwable){}if(DB::transactionLevel()>0)DB::rollBack();throw $e;}
    }

    private function buildPayload(array $settings, BackupRun $run): array
    {
        $tables=[];$counts=[];
        foreach($this->tablesForSettings($settings) as $table){$rows=DB::table($table)->orderBy($this->orderColumn($table))->get()->map(fn($r)=>(array)$r)->all();$tables[$table]=$rows;$counts[$table]=count($rows);}
        $configFiles=[];
        if($settings['backup_configuration']){
            foreach(['VERSION','config/version.php','composer.json','package.json','docker-compose.yml','.env.example','.env.coolify.example'] as $file){$path=base_path($file);if(is_file($path)&&is_readable($path))$configFiles[$file]=file_get_contents($path);}
        }
        return [
            'manifest'=>[
                'format'=>self::FORMAT,'created_at'=>now()->toIso8601String(),'backup_run_id'=>$run->id,'app_version'=>config('version.current'),'db_driver'=>DB::getDriverName(),
                'table_counts'=>$counts,'config_files'=>array_keys($configFiles),'scope'=>$this->scopeFromSettings($settings),'encrypted'=>true,
                'app_key_note'=>'APP_KEY is intentionally NOT stored in this backup. Secure DR custody of the matching APP_KEY is required to decrypt and restore.',
                'external_source_responsibility'=>$settings['external_source_responsibility'],
                'local_media_note'=>'This application-native archive protects portal database/metadata/configuration/audit records. Media/source file bytes require the storage/source owner backup plan and are not embedded in this metadata archive.',
            ],
            'tables'=>$tables,'configuration_files'=>$configFiles,
        ];
    }

    private function decodeEncryptedPayload(string $raw, array &$result=[]): array
    {
        $envelope=json_decode(Crypt::decryptString($raw),true,512,JSON_THROW_ON_ERROR);
        if(($envelope['format']??null)!==self::FORMAT)throw new RuntimeException('Unsupported backup format.');
        $compressed=base64_decode((string)($envelope['payload_base64']??''),true);if($compressed===false)throw new RuntimeException('Invalid backup payload encoding.');
        $actual=hash('sha256',$compressed);$result['payload_sha256_match']=hash_equals((string)($envelope['payload_sha256']??''),$actual);
        if(!$result['payload_sha256_match'])throw new RuntimeException('Backup payload checksum mismatch.');
        $json=gzdecode($compressed);if($json===false)throw new RuntimeException('Backup decompression failed.');
        return json_decode($json,true,512,JSON_THROW_ON_ERROR);
    }

    private function writeBackupFile(array $settings,string $filename,string $contents): string
    {
        if($settings['destination_type']==='local'){$path='backups/'.$filename;if(!Storage::disk('local')->put($path,$contents))throw new RuntimeException('Could not write backup to protected local storage.');return $path;}
        $dir=$this->validatedFilesystemDirectory($settings['filesystem_path']);$path=$dir.DIRECTORY_SEPARATOR.$filename;if(file_put_contents($path,$contents,LOCK_EX)===false)throw new RuntimeException('Could not write backup to filesystem destination.');@chmod($path,0600);return $path;
    }

    private function deleteRunFile(BackupRun $run): void
    {
        if(!$run->storage_path)return;if($run->destination_type==='local')Storage::disk('local')->delete($run->storage_path);elseif(is_file($run->storage_path))@unlink($run->storage_path);
    }

    private function validatedFilesystemDirectory(string $path): string
    {
        if($path===''||!str_starts_with($path,DIRECTORY_SEPARATOR))throw new RuntimeException('Filesystem backup destination must be an existing absolute directory.');
        $real=realpath($path);if($real===false||!is_dir($real)||!is_writable($real))throw new RuntimeException('Filesystem backup destination does not exist or is not writable.');
        $public=realpath(public_path());if($public&&($real===$public||str_starts_with($real,rtrim($public,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)))throw new RuntimeException('Backup destination cannot be inside the public web directory.');
        return rtrim($real,DIRECTORY_SEPARATOR);
    }

    private function readArbitraryPath(string $path): string
    {
        if(is_file($path)&&is_readable($path)){ $data=file_get_contents($path);if($data!==false)return $data; }
        if(Storage::disk('local')->exists($path))return (string)Storage::disk('local')->get($path);
        throw new RuntimeException('Backup file cannot be read.');
    }

    private function scheduleDue(string $class,array $s,Carbon $now): bool
    {
        $hour=(int)$s[$class.'_hour'];if($now->hour<$hour)return false;
        if($class==='weekly'&&$now->dayOfWeek!==(int)$s['weekly_day'])return false;
        if($class==='monthly'&&$now->day!==(int)$s['monthly_day'])return false;
        $q=BackupRun::query()->where('trigger','scheduled')->where('retention_class',$class)->where('status','success');
        if($class==='daily')$q->whereDate('completed_at',$now->toDateString());
        elseif($class==='weekly')$q->whereBetween('completed_at',[$now->copy()->startOfWeek(Carbon::SUNDAY),$now->copy()->endOfWeek(Carbon::SATURDAY)]);
        else $q->whereYear('completed_at',$now->year)->whereMonth('completed_at',$now->month);
        return !$q->exists();
    }

    private function tablesForSettings(array $s): array
    {
        $tables=[];if($s['backup_database'])$tables=array_merge($tables,self::CORE_TABLES);if($s['backup_configuration'])$tables=array_merge($tables,self::CONFIG_TABLES);if($s['backup_audit_security'])$tables=array_merge($tables,self::AUDIT_TABLES);return array_values(array_unique($tables));
    }
    private function allBackupTables(): array{return array_values(array_unique(array_merge(self::CORE_TABLES,self::CONFIG_TABLES,self::AUDIT_TABLES)));}
    private function orderColumn(string $table): string{return in_array($table,['system_settings'],true)?'key':'id';}
    private function scopeFromSettings(array $s): array{return ['database'=>(bool)$s['backup_database'],'configuration'=>(bool)$s['backup_configuration'],'audit_security'=>(bool)$s['backup_audit_security'],'media_bytes'=>false];}
    private function setForeignKeys(bool $enabled,string $driver): void{if($driver==='mysql')DB::statement('SET FOREIGN_KEY_CHECKS='.(int)$enabled);elseif($driver==='sqlite')DB::statement('PRAGMA foreign_keys = '.($enabled?'ON':'OFF'));}
    private function alertAdmins(string $event,string $title,string $message,array $data=[]): void{User::query()->where('role','super-admin')->where('status','active')->get()->each(fn($u)=>$this->notifications->sendEvent($u,$event,$title,$message,$data));}
    private function defaults(): array{return [
        'destination_type'=>'local','filesystem_path'=>'','responsible_owner'=>'','daily_enabled'=>false,'daily_hour'=>2,'weekly_enabled'=>false,'weekly_day'=>0,'weekly_hour'=>3,
        'monthly_enabled'=>false,'monthly_day'=>1,'monthly_hour'=>4,'daily_retention_days'=>0,'weekly_retention_days'=>0,'monthly_retention_days'=>0,'rpo_minutes'=>0,'rto_minutes'=>0,
        'backup_database'=>true,'backup_configuration'=>true,'backup_audit_security'=>true,
        'external_source_responsibility'=>'NAS / Google Drive / YouTube source-content backup is owned outside the portal backup and must be covered by the responsible source-system owner.',
    ];}
}
