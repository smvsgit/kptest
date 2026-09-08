<?php
namespace App\Http\Controllers;
use App\Models\IntegrationConnection;
use App\Models\IntegrationHealthCheck;
use App\Models\MediaSource;
use App\Services\IntegrationHealthService;
use Illuminate\Http\Request;
class IntegrationHealthController extends Controller {
 public function index(Request $r,IntegrationHealthService $h){abort_unless(in_array($r->user()->role,['super-admin','department-admin'],true),403);$include=$r->user()->role==='super-admin';$connections=IntegrationConnection::withCount('sources')->orderBy('type')->orderBy('name')->get()->map(fn($c)=>$h->publicConnection($c,$include));$q=MediaSource::with(['mediaFile:id,name,department_id,owner_user_id','mediaFile.ownerUser:id,name','connection:id,name,type,status,is_active'])->whereIn('status',['missing','broken','inactive'])->latest('last_checked_at');if($r->user()->role==='department-admin')$q->whereHas('mediaFile',fn($x)=>$x->where('department_id',$r->user()->department_id));$issues=$q->limit(250)->get()->map(fn($s)=>$h->publicSource($s)+['media_name'=>$s->mediaFile?->name,'owner'=>$s->mediaFile?->ownerUser?->name]);$recent=IntegrationHealthCheck::latest('checked_at')->limit(100)->get();return response()->json(['connections'=>$connections,'issues'=>$issues,'recent_checks'=>$recent]);}
 public function run(Request $r,IntegrationHealthService $h){abort_unless($r->user()->role==='super-admin',403);return response()->json(['message'=>'Health scan complete.']+$h->runDueChecks());}
}
