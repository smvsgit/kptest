<?php
namespace App\Services;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
class NetworkPolicyService {
 public function allows(Request $r, ?User $user=null): bool {
  $s=SystemSetting::valueFor('security.network',['enabled'=>false]); if(!($s['enabled']??false)) return true;
  if($user && $user->role==='super-admin' && !empty($s['emergency_super_admin_email']) && strcasecmp($user->email,$s['emergency_super_admin_email'])===0) return true;
  $ip=$r->ip(); foreach((array)($s['allowed_cidrs']??[]) as $cidr) if($this->inCidr($ip,(string)$cidr)) return true; return false;
 }
 private function inCidr(string $ip,string $cidr): bool { if(!str_contains($cidr,'/')) return $ip===$cidr; [$sub,$bits]=explode('/',$cidr,2); $a=inet_pton($ip); $b=inet_pton($sub); if($a===false||$b===false||strlen($a)!==strlen($b)) return false; $bits=(int)$bits; for($i=0;$i<strlen($a);$i++){ $take=min(8,max(0,$bits-$i*8)); if($take===0) break; $mask=(0xFF << (8-$take)) & 0xFF; if((ord($a[$i])&$mask)!==(ord($b[$i])&$mask)) return false; } return true; }
}
