<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class SessionController extends Controller {
 public function index(Request $r){$rows=DB::table('sessions')->where('user_id',$r->user()->id)->orderByDesc('last_activity')->get()->map(fn($s)=>['id'=>$s->id,'ip_address'=>$s->ip_address,'user_agent'=>$s->user_agent,'last_activity'=>date(DATE_ATOM,$s->last_activity),'current'=>$s->id===$r->session()->getId()]);return response()->json(['sessions'=>$rows]);}
 public function destroy(Request $r,string $sessionId,AuditService $audit){$deleted=DB::table('sessions')->where('user_id',$r->user()->id)->where('id',$sessionId)->delete();abort_unless($deleted,404);$audit->log($r,'session.revoked',$r->user(),'User revoked an active session.',['session_id'=>$sessionId]);return response()->json(['message'=>'Session logged out.']);}
 public function destroyOthers(Request $r,AuditService $audit){$count=DB::table('sessions')->where('user_id',$r->user()->id)->where('id','!=',$r->session()->getId())->delete();$audit->log($r,'session.logout-others',$r->user(),'User logged out other sessions.',['count'=>$count]);return response()->json(['message'=>"Logged out {$count} other session(s)."]);}
 public function destroyUser(Request $r,User $user,AuditService $audit){$count=DB::table('sessions')->where('user_id',$user->id)->delete();$audit->log($r,'session.admin-logout-all',$user,'Administrator logged out all user sessions.',['count'=>$count]);return response()->json(['message'=>"Logged out {$count} session(s) for {$user->name}."]);}
}
