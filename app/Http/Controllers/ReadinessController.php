<?php

namespace App\Http\Controllers;

use App\Models\GoLiveReview;
use App\Models\ReadinessSnapshot;
use App\Models\UatCase;
use App\Services\AuditService;
use App\Services\ReadinessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReadinessController extends Controller
{
    public function run(Request $request, ReadinessService $readiness, AuditService $audit)
    {
        $result=$readiness->runSnapshot($request->user()->id);
        $audit->log($request,'readiness.snapshot.created',$result['snapshot'],'Go-live readiness checks executed.',$result['summary']);
        return response()->json([
            'message'=>'Readiness checks completed.','snapshot'=>$this->snapshotRow($result['snapshot']),'checks'=>$result['checks'],'summary'=>$result['summary'],
        ]);
    }

    public function updateUat(Request $request, UatCase $uatCase, AuditService $audit)
    {
        $data=$request->validate([
            'status'=>['required',Rule::in(['pending','wip','passed','failed','blocked'])],
            'execution_notes'=>['nullable','string','max:10000'],
            'evidence_reference'=>['nullable','string','max:1000'],
        ]);
        if($data['status']==='passed' && blank($data['execution_notes']??null) && blank($data['evidence_reference']??null)){
            return response()->json(['message'=>'Passed UAT requires execution notes or an evidence reference.'],422);
        }
        $before=$uatCase->only(['status','execution_notes','evidence_reference','executed_app_version','approved_at','approved_app_version']);
        $uatCase->forceFill([
            'status'=>$data['status'],'execution_notes'=>trim((string)($data['execution_notes']??''))?:null,
            'evidence_reference'=>trim((string)($data['evidence_reference']??''))?:null,
            'executed_by'=>$request->user()->id,'executed_at'=>now(),'executed_app_version'=>config('version.current'),
            'approved_by'=>null,'approved_at'=>null,'approved_app_version'=>null,
        ])->save();
        $audit->log($request,'uat.case.updated',$uatCase,'UAT case execution result updated.',['before'=>$before,'after'=>$uatCase->only(['status','execution_notes','evidence_reference','executed_app_version'])]);
        return response()->json(['message'=>"{$uatCase->code} updated for v".config('version.current').'.','case'=>$this->uatRow($uatCase->fresh(['executedBy','approvedBy']))]);
    }

    public function approveUat(Request $request, UatCase $uatCase, AuditService $audit)
    {
        abort_unless($uatCase->status==='passed',422,'Only a Passed UAT case can be approved.');
        abort_if(blank($uatCase->execution_notes)&&blank($uatCase->evidence_reference),422,'Evidence/notes are required before approval.');
        abort_unless($uatCase->executed_app_version===config('version.current'),422,'This UAT result was executed against a different application release. Re-run and save the case for the current release before sign-off.');
        $uatCase->forceFill(['approved_by'=>$request->user()->id,'approved_at'=>now(),'approved_app_version'=>config('version.current')])->save();
        $audit->log($request,'uat.case.approved',$uatCase,'UAT case approved/sign-off recorded.',['code'=>$uatCase->code,'app_version'=>config('version.current')]);
        return response()->json(['message'=>"{$uatCase->code} approved for v".config('version.current').'.','case'=>$this->uatRow($uatCase->fresh(['executedBy','approvedBy']))]);
    }

    public function updateSettings(Request $request, ReadinessService $readiness, AuditService $audit)
    {
        $data=$request->validate([
            'planned_go_live_at'=>['nullable','date'],'deployment_owner'=>['nullable','string','max:255'],
            'rollback_owner'=>['nullable','string','max:255'],'business_signoff_owner'=>['nullable','string','max:255'],
            'smoke_test_owner'=>['nullable','string','max:255'],'support_contact'=>['nullable','string','max:500'],
            'rollback_window_minutes'=>['required','integer','min:0','max:10080'],'change_freeze_confirmed'=>['required','boolean'],
        ]);
        $before=$readiness->settings();$after=$readiness->saveSettings($data);
        $audit->log($request,'settings.go-live.updated',null,'Go-live operational settings updated.',['before'=>$before,'after'=>$after]);
        return response()->json(['message'=>'Go-live operational settings saved.','settings'=>$after]);
    }

    public function updateDependencies(Request $request, ReadinessService $readiness, AuditService $audit)
    {
        $data=$request->validate([
            'dependencies'=>['required','array'],'dependencies.*.key'=>['required','string','max:120'],
            'dependencies.*.status'=>['required',Rule::in(['pending','resolved','accepted-risk'])],
            'dependencies.*.note'=>['nullable','string','max:2000'],
        ]);
        $before=$readiness->dependencies();$after=$readiness->saveDependencies($data['dependencies']);
        $audit->log($request,'settings.go-live-dependencies.updated',null,'Go-live Management dependency status updated.',['before'=>$before,'after'=>$after]);
        return response()->json(['message'=>'Management dependency statuses saved.','dependencies'=>$after]);
    }

    public function review(Request $request, ReadinessService $readiness, AuditService $audit)
    {
        $data=$request->validate(['decision'=>['required',Rule::in(['approved','rejected'])],'notes'=>['nullable','string','max:10000']]);
        $result=$readiness->runSnapshot($request->user()->id);
        if($data['decision']==='approved' && !($result['summary']['go_live_allowed']??false)){
            return response()->json(['message'=>'Go-live approval refused because blocking readiness checks remain. Run/resolve the checklist and try again.','summary'=>$result['summary'],'checks'=>$result['checks']],422);
        }
        $review=GoLiveReview::create([
            'readiness_snapshot_id'=>$result['snapshot']->id,'decision'=>$data['decision'],'app_version'=>config('version.current'),'notes'=>trim((string)($data['notes']??''))?:null,
            'dependency_snapshot'=>$readiness->dependencies(),'reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),
        ]);
        $audit->log($request,'go-live.review.'.$data['decision'],$review,'Go-live review decision recorded.',['summary'=>$result['summary'],'notes'=>$review->notes,'app_version'=>config('version.current')]);
        return response()->json(['message'=>'Go-live review recorded as '.$data['decision'].' for v'.config('version.current').'.','review'=>$this->reviewRow($review->fresh('reviewedBy')),'summary'=>$result['summary']]);
    }

    public function export(Request $request, ReadinessService $readiness)
    {
        $checks=$readiness->automaticChecks();$cases=UatCase::with(['executedBy:id,name','approvedBy:id,name'])->orderBy('sort_order')->get();
        $rows=[];$rows[]=['Section','Code/Check','Title','Status','Blocking/Priority','Executed/Detail','Executed Release','Evidence/Notes','Approved','Approved Release'];
        foreach($cases as $case)$rows[]=['UAT',$case->code,$case->title,$case->status,$case->priority,optional($case->executed_at)->toDateTimeString().' '.($case->executedBy?->name??''),$case->executed_app_version,$case->evidence_reference?:$case->execution_notes,optional($case->approved_at)->toDateTimeString().' '.($case->approvedBy?->name??''),$case->approved_app_version];
        foreach($checks as $check)$rows[]=['Readiness',$check['key'],$check['label'],$check['status'],$check['blocking']?'Blocking':'Advisory',$check['detail'],config('version.current'),'','',''];
        $tmp=fopen('php://temp','w+');fwrite($tmp,"\xEF\xBB\xBF");foreach($rows as $row)fputcsv($tmp,$row);rewind($tmp);$csv=stream_get_contents($tmp);fclose($tmp);
        $filename='Karyalay_Portal_'.config('version.current').'_UAT_GoLive_Readiness.csv';
        return response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="'.$filename.'"','Cache-Control'=>'no-store']);
    }

    public function dashboardData(ReadinessService $readiness): array
    {
        return [
            'app_version'=>config('version.current'),
            'uat_cases'=>UatCase::with(['executedBy:id,name','approvedBy:id,name'])->orderBy('sort_order')->get()->map(fn($c)=>$this->uatRow($c))->values(),
            'go_live_settings'=>$readiness->settings(),'management_dependencies'=>$readiness->dependencies(),
            'latest_snapshot'=>($s=ReadinessSnapshot::with('runBy:id,name')->latest('id')->first())?$this->snapshotRow($s):null,
            'latest_review'=>($r=GoLiveReview::with('reviewedBy:id,name')->latest('id')->first())?$this->reviewRow($r):null,
        ];
    }

    private function uatRow(UatCase $c): array{return ['id'=>$c->id,'code'=>$c->code,'title'=>$c->title,'priority'=>$c->priority,'category'=>$c->category,'status'=>$c->status,'execution_notes'=>$c->execution_notes,'evidence_reference'=>$c->evidence_reference,'executed_by'=>$c->executedBy?->name,'executed_at'=>$c->executed_at?->toIso8601String(),'executed_app_version'=>$c->executed_app_version,'approved_by'=>$c->approvedBy?->name,'approved_at'=>$c->approved_at?->toIso8601String(),'approved_app_version'=>$c->approved_app_version];}
    private function snapshotRow(ReadinessSnapshot $s): array{return ['id'=>$s->id,'app_version'=>$s->app_version,'overall_status'=>$s->overall_status,'checks'=>$s->checks,'summary'=>$s->summary,'run_by'=>$s->runBy?->name,'run_at'=>$s->run_at?->toIso8601String()];}
    private function reviewRow(GoLiveReview $r): array{return ['id'=>$r->id,'decision'=>$r->decision,'app_version'=>$r->app_version,'notes'=>$r->notes,'dependency_snapshot'=>$r->dependency_snapshot,'reviewed_by'=>$r->reviewedBy?->name,'reviewed_at'=>$r->reviewed_at?->toIso8601String(),'readiness_snapshot_id'=>$r->readiness_snapshot_id];}
}
