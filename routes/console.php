<?php

use App\Services\AccessEscalationService;
use App\Services\AccessExpiryService;
use App\Services\IntegrationHealthService;
use App\Services\BackupService;
use App\Services\AuditService;
use App\Services\ReadinessService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:escalate-access', function () {
    $result = app(AccessEscalationService::class)->run();
    $this->info(($result['enabled'] ? 'Escalation scan complete' : 'Escalation disabled').'; escalated: '.($result['escalated'] ?? 0));
})->purpose('Escalate overdue protected-file access requests using configured notification channels.');

Schedule::command('notifications:escalate-access')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('access:expire-temporary', function () { $r=app(AccessExpiryService::class)->run(); $this->info('Expired: '.($r['expired']??0)); })->purpose('Expire temporary protected-media approvals and revoke entitlement.');
Schedule::command('access:expire-temporary')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('integrations:health-check', function () { $r=app(IntegrationHealthService::class)->runDueChecks(); $this->info('Connections checked: '.($r['connections']??0).'; sources checked: '.($r['sources']??0)); })->purpose('Check due integration connections and linked media sources.');
Schedule::command('integrations:health-check')->everyFifteenMinutes()->withoutOverlapping();


Artisan::command('backup:scheduled', function () {
    $result=app(BackupService::class)->runScheduledIfDue();
    $this->info('Backup schedule scan complete; created: '.($result['count']??0).'; readiness: '.($result['readiness']??'unknown'));
})->purpose('Create due portal database/config/audit backups and monitor RPO readiness.');
Schedule::command('backup:scheduled')->hourly()->withoutOverlapping();

Artisan::command('backup:restore-file {path} {--confirm=} {--force-production}', function (string $path) {
    if ($this->option('confirm') !== 'RESTORE-EMPTY-DATABASE') {
        $this->error('Restore refused. Pass --confirm=RESTORE-EMPTY-DATABASE after verifying the target is a fresh migrated database.');
        return 2;
    }
    if (app()->environment('production')) {
        if (!$this->option('force-production')) {
            $this->error('Production restore requires --force-production.');
            return 2;
        }
        if (!app()->isDownForMaintenance()) {
            $this->error('Production restore requires Laravel maintenance mode (php artisan down).');
            return 2;
        }
    }
    try {
        $result=app(BackupService::class)->restoreFileToFreshDatabase($path);
        app(AuditService::class)->logSystem('backup.restore.completed',null,'Fresh-database restore completed and row counts verified.',['path'=>basename($path),'table_counts'=>$result['table_counts']??[]],'console');
        $this->info('Restore completed and row-count verification passed.');
        foreach (($result['table_counts']??[]) as $table=>$counts) $this->line($table.': '.$counts['actual'].' / '.$counts['expected']);
        return 0;
    } catch (Throwable $e) {
        $this->error('Restore failed: '.$e->getMessage());
        return 1;
    }
})->purpose('Restore an encrypted Karyalay Portal backup into a fresh migrated database.');


Artisan::command('readiness:heartbeat', function () {
    app(ReadinessService::class)->schedulerHeartbeat();
    $this->info('Readiness scheduler heartbeat recorded at '.now()->toDateTimeString().'.');
})->purpose('Record scheduler heartbeat used by the v12.x go-live readiness gate.');
Schedule::command('readiness:heartbeat')->everyMinute()->withoutOverlapping();

Artisan::command('readiness:check {--json}', function () {
    $service=app(ReadinessService::class); $checks=$service->automaticChecks(); $summary=$service->summary($checks);
    if($this->option('json')) { $this->line(json_encode(['summary'=>$summary,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }
    else {
        $this->info('Karyalay Portal v'.config('version.current').' readiness: '.$summary['overall_status']);
        foreach($checks as $check){$this->line(strtoupper($check['status']).' | '.($check['blocking']?'BLOCKING':'ADVISORY').' | '.$check['label'].' | '.$check['detail']);}
        $this->line('Blocking failures: '.$summary['blocking_failures'].'; warnings: '.$summary['warnings'].'; passes: '.$summary['passes'].'/'.$summary['total']);
    }
    return $summary['blocking_failures']>0?1:0;
})->purpose('Run the v12.x automated go-live readiness checks; exits non-zero when blocking failures remain.');

Artisan::command('reports:scheduled', function () {
    if (!(\App\Models\SystemSetting::valueFor('reports.scheduler',['enabled'=>true])['enabled']??true)) {
        $this->info('Scheduled reports are disabled by policy.');
        return;
    }
    $count=0;
    foreach (\App\Models\ScheduledReport::query()->where('is_active',true)->where(function($q){$q->whereNull('next_run_at')->orWhere('next_run_at','<=',now());})->get() as $report) {
        app(\App\Http\Controllers\ScheduledReportController::class)->execute($report); $count++;
    }
    $this->info('Scheduled reports processed: '.$count);
})->purpose('Run due weekly/monthly scheduled reports.');
Schedule::command('reports:scheduled')->hourly()->withoutOverlapping();

Artisan::command('audit:retention', function () { $r=app(\App\Services\AuditRetentionService::class)->run(); $this->info(json_encode($r)); })->purpose('Archive/prune audit records according to approved retention policy.');
Schedule::command('audit:retention')->dailyAt('02:10')->withoutOverlapping();

Artisan::command('recycle:retention', function () { $r=app(\App\Services\RecycleRetentionService::class)->run(); $this->info(json_encode($r)); })->purpose('Purge Recycle Bin items older than approved retention period.');
Schedule::command('recycle:retention')->dailyAt('02:40')->withoutOverlapping();
