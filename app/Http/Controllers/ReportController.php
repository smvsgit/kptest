<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\MediaAccessRequest;
use App\Models\MediaFile;
use App\Models\MediaFavorite;
use App\Models\MediaRecentView;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\IntegrationConnection;
use App\Models\MediaSource;
use App\Services\AuditIntegrityService;
use App\Services\AuditService;
use App\Services\MediaAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function summary(Request $request, MediaAccessService $access, AuditIntegrityService $integrity)
    {
        $user = $request->user();
        $media = MediaFile::query();
        $access->applyVisibility($media, $user);
        $summary = [
            'role'=>$user->role,
            'files'=>(clone $media)->count(),
            'storage_bytes'=>(int)(clone $media)->sum('size'),
            'favorites'=>MediaFavorite::where('user_id',$user->id)->count(),
            'recent'=>MediaRecentView::where('user_id',$user->id)->count(),
            'my_requests'=>MediaAccessRequest::where('user_id',$user->id)->count(),
            'my_pending_requests'=>MediaAccessRequest::where('user_id',$user->id)->where('status','pending')->count(),
        ];
        if ($user->role === 'super-admin') {
            $summary += [
                'users'=>User::count(),'departments'=>Department::count(),
                'pending_approvals'=>MediaAccessRequest::where('status','pending')->count(),
                'broken_assets'=>MediaFile::whereIn('asset_status',['broken','inactive'])->count(),
                'integration_connections'=>IntegrationConnection::count(),
                'unavailable_integrations'=>IntegrationConnection::whereIn('status',['degraded','unavailable'])->count(),
                'broken_sources'=>MediaSource::whereIn('status',['missing','broken'])->count(),
                'integration_capacity_bytes'=>(int)IntegrationConnection::sum('capacity_bytes'),
                'integration_free_bytes'=>(int)IntegrationConnection::sum('free_bytes'),
                'failed_deliveries'=>NotificationDelivery::where('status','failed')->count(),
                'audit_integrity'=>$integrity->verify(),
            ];
        } elseif ($user->role === 'department-admin') {
            $summary += [
                'department_files'=>MediaFile::where('department_id',$user->department_id)->count(),
                'pending_approvals'=>MediaAccessRequest::where('status','pending')->whereHas('mediaFile',fn($q)=>$q->where('department_id',$user->department_id))->count(),
                'recent_uploads'=>MediaFile::where('department_id',$user->department_id)->where('created_at','>=',now()->subDays(7))->count(),
                'department_activity'=>AuditLog::whereIn('user_id',User::where('department_id',$user->department_id)->select('id'))->where('created_at','>=',now()->subDays(7))->count(),
            ];
        }
        return response()->json($summary);
    }

    public function access(Request $request)
    {
        return response()->json(['rows'=>$this->accessQuery($request)->limit(250)->get()->map(fn(AuditLog $log)=>$this->auditRow($log))->values()]);
    }

    public function activity(Request $request)
    {
        return response()->json(['rows'=>$this->scopeAudit(AuditLog::query()->with('user:id,name,email,department_id')->latest('id'),$request)->limit(250)->get()->map(fn(AuditLog $log)=>$this->auditRow($log))->values()]);
    }

    public function notifications(Request $request)
    {
        abort_unless($request->user()->role === 'super-admin',403);
        $rows=NotificationDelivery::with('user:id,name,email,phone')->latest('id')->limit(250)->get()->map(fn(NotificationDelivery $d)=>[
            'id'=>$d->id,'created_at'=>optional($d->created_at)->toIso8601String(),'event'=>$d->event,'category'=>$d->category,
            'channel'=>$d->channel,'provider'=>$d->provider,'recipient'=>$d->recipient,'status'=>$d->status,'attempts'=>$d->attempts,
            'sent_at'=>optional($d->sent_at)->toIso8601String(),'error'=>$d->error,'user'=>$d->user?->name,
        ]);
        $counts=NotificationDelivery::selectRaw('channel,status,COUNT(*) as total')->groupBy('channel','status')->get();
        return response()->json(['rows'=>$rows,'counts'=>$counts]);
    }

    public function verifyAudit(Request $request, AuditIntegrityService $integrity, AuditService $audit)
    {
        abort_unless($request->user()->role === 'super-admin',403);
        $result=$integrity->verify();
        $audit->log($request,'audit.integrity-verified',null,'Audit hash chain verification executed.',$result);
        return response()->json($result);
    }

    public function export(Request $request, string $report, AuditService $audit)
    {
        abort_unless(in_array($report,['access','activity','notifications'],true),404);
        if ($report==='notifications') abort_unless($request->user()->role==='super-admin',403);
        $audit->log($request,'report.exported',null,'Report exported as CSV.',['report'=>$report]);
        $filename='karyalay-'.$report.'-report-'.now()->format('Ymd-His').'.csv';
        return response()->streamDownload(function () use ($request,$report) {
            $out=fopen('php://output','wb');
            if ($report==='notifications') {
                fputcsv($out,['Date','User','Event','Category','Channel','Provider','Recipient','Status','Attempts','Sent At','Error']);
                NotificationDelivery::with('user:id,name')->latest('id')->chunk(500,function($rows)use($out){foreach($rows as $d)fputcsv($out,[$d->created_at,$d->user?->name,$d->event,$d->category,$d->channel,$d->provider,$d->recipient,$d->status,$d->attempts,$d->sent_at,$d->error]);});
            } else {
                fputcsv($out,['Date','User','Event','Subject','Description','IP','Session','Source']);
                $query=$report==='access'?$this->accessQuery($request):$this->scopeAudit(AuditLog::query()->with('user:id,name')->latest('id'),$request);
                $query->chunk(500,function($rows)use($out){foreach($rows as $log){$r=$this->auditRow($log);fputcsv($out,[$r['created_at'],$r['user'],$r['event'],$r['subject'],$r['description'],$r['ip_address'],$r['session_id'],$r['source']]);}});
            }
            fclose($out);
        },$filename,['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    private function accessQuery(Request $request): Builder
    {
        $query=AuditLog::query()->with('user:id,name,email,department_id')->whereIn('event',['media.previewed','media.downloaded','media.bulk-downloaded'])->latest('id');
        return $this->scopeAudit($query,$request);
    }

    private function scopeAudit(Builder $query, Request $request): Builder
    {
        $user=$request->user();
        if ($user->role==='super-admin') return $query;
        if ($user->role==='department-admin') {
            $departmentId=$user->department_id;
            return $query->where(function($scope) use ($departmentId) {
                $scope->whereIn('user_id',User::where('department_id',$departmentId)->select('id'))
                    ->orWhere(function($subject) use ($departmentId) {
                        $subject->where('auditable_type',MediaFile::class)
                            ->whereIn('auditable_id',MediaFile::where('department_id',$departmentId)->select('id'));
                    });
            });
        }
        return $query->where('user_id',$user->id);
    }

    private function auditRow(AuditLog $log): array
    {
        $subject='';
        if ($log->auditable_type && $log->auditable_id) $subject=class_basename($log->auditable_type).' #'.$log->auditable_id;
        return [
            'id'=>$log->id,'created_at'=>optional($log->created_at)->toIso8601String(),'user'=>$log->user?->name ?? 'System',
            'event'=>$log->event,'subject'=>$subject,'description'=>$log->description,'ip_address'=>$log->ip_address,
            'session_id'=>$log->session_id,'source'=>$log->source,'context'=>$log->context,
        ];
    }
}
