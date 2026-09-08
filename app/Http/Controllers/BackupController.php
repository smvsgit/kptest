<?php

namespace App\Http\Controllers;

use App\Models\BackupRun;
use App\Services\AuditService;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BackupController extends Controller
{
    public function updateSettings(Request $request, BackupService $backups, AuditService $audit)
    {
        $data=$request->validate([
            'destination_type'=>['required',Rule::in(['local','filesystem'])],
            'filesystem_path'=>['nullable','string','max:1000'],
            'responsible_owner'=>['nullable','string','max:255'],
            'daily_enabled'=>['required','boolean'],'daily_hour'=>['required','integer','min:0','max:23'],
            'weekly_enabled'=>['required','boolean'],'weekly_day'=>['required','integer','min:0','max:6'],'weekly_hour'=>['required','integer','min:0','max:23'],
            'monthly_enabled'=>['required','boolean'],'monthly_day'=>['required','integer','min:1','max:28'],'monthly_hour'=>['required','integer','min:0','max:23'],
            'daily_retention_days'=>['required','integer','min:0','max:3650'],'weekly_retention_days'=>['required','integer','min:0','max:3650'],'monthly_retention_days'=>['required','integer','min:0','max:3650'],
            'rpo_minutes'=>['required','integer','min:0','max:525600'],'rto_minutes'=>['required','integer','min:0','max:525600'],
            'backup_database'=>['required','boolean'],'backup_configuration'=>['required','boolean'],'backup_audit_security'=>['required','boolean'],
            'external_source_responsibility'=>['required','string','max:3000'],
        ]);
        $before=$backups->settings();$after=$backups->saveSettings($data);
        $audit->log($request,'settings.backup.updated',null,'Backup/DR settings updated.',['before'=>$before,'after'=>$after]);
        return back()->with('success','Backup & DR settings saved.');
    }

    public function create(Request $request, BackupService $backups)
    {
        $run=$backups->createBackup($request->user(),'manual','manual');
        return back()->with('success',"Backup #{$run->id} created successfully.");
    }

    public function verify(Request $request, BackupRun $backupRun, BackupService $backups)
    {
        abort_unless($backupRun->status==='success',422,'Only successful backup files can be verified.');
        $v=$backups->verifyBackup($backupRun,$request->user(),trim((string)$request->input('notes')));
        return back()->with('success',$v->status==='passed'?'Backup integrity verification passed.':'Backup verification failed.');
    }

    public function restoreVerification(Request $request, BackupRun $backupRun, BackupService $backups)
    {
        $data=$request->validate([
            'status'=>['required',Rule::in(['passed','failed'])],
            'duration_minutes'=>['required','integer','min:0','max:525600'],
            'target_environment'=>['required','string','max:120'],
            'notes'=>['nullable','string','max:5000'],
        ]);
        $v=$backups->recordRestoreTest($backupRun,$request->user(),$data['status'],(int)$data['duration_minutes'],$data['target_environment'],$data['notes']??null);
        return back()->with('success',"Restore test verification recorded as {$v->status}.");
    }

    public function download(Request $request, BackupRun $backupRun, BackupService $backups)
    {
        abort_unless($backupRun->status==='success'&&$backupRun->storage_path,404);
        $raw=$backups->readRunFile($backupRun);
        return response($raw,200,[
            'Content-Type'=>'application/octet-stream',
            'Content-Disposition'=>'attachment; filename="'.($backupRun->filename?:('backup-'.$backupRun->id.'.kpbackup')).'"',
            'Content-Length'=>(string)strlen($raw),
            'Cache-Control'=>'no-store, private',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function prune(Request $request, BackupService $backups, AuditService $audit)
    {
        $result=$backups->pruneRetention();
        $audit->log($request,'backup.retention-prune.manual',null,'Manual backup retention scan completed.',$result);
        return back()->with('success',"Retention scan complete; {$result['count']} backup file(s) pruned.");
    }
}
