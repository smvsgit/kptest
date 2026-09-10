<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;

class NetworkPolicyService
{
    public const SOURCE_INTERNAL='internal';
    public const SOURCE_VPN='vpn';
    public const SOURCE_EXTERNAL='external';
    public const SOURCE_UNRESTRICTED='unrestricted';
    public const SOURCE_BLOCKED='blocked';

    public function settings(): array
    {
        return array_replace([
            'enabled'=>false,
            'allowed_cidrs'=>[],
            'trusted_vpn_proxies'=>[],
            'emergency_super_admin_email'=>'',
            'department_admin_can_manage_external_access'=>false,
        ], SystemSetting::valueFor('security.network', []));
    }

    public function allows(Request $request, ?User $user=null): bool
    {
        return $this->accessSource($request,$user)!==self::SOURCE_BLOCKED;
    }

    public function accessSource(Request $request, ?User $user=null): string
    {
        $settings=$this->settings();
        if(!($settings['enabled']??false)) return self::SOURCE_UNRESTRICTED;

        if($user && $user->role==='super-admin' && !empty($settings['emergency_super_admin_email']) && strcasecmp($user->email,(string)$settings['emergency_super_admin_email'])===0){
            return self::SOURCE_INTERNAL;
        }

        $ip=$request->ip();
        if($this->matchesAny($ip,(array)($settings['allowed_cidrs']??[]))) return self::SOURCE_INTERNAL;
        if($this->matchesAny($ip,(array)($settings['trusted_vpn_proxies']??[]))) return self::SOURCE_VPN;
        if($user && $user->externalAccessIsActive()) return self::SOURCE_EXTERNAL;
        return self::SOURCE_BLOCKED;
    }

    public function isExternalExceptionActive(User $user): bool
    {
        return $user->externalAccessIsActive();
    }

    private function matchesAny(string $ip,array $cidrs): bool
    {
        foreach($cidrs as $cidr) if($this->inCidr($ip,trim((string)$cidr))) return true;
        return false;
    }

    private function inCidr(string $ip,string $cidr): bool
    {
        if($cidr==='') return false;
        if(!str_contains($cidr,'/')) return $ip===$cidr;
        [$sub,$bits]=explode('/',$cidr,2);
        $a=inet_pton($ip);$b=inet_pton($sub);
        if($a===false||$b===false||strlen($a)!==strlen($b)) return false;
        $bits=(int)$bits;
        if($bits<0||$bits>strlen($a)*8) return false;
        for($i=0;$i<strlen($a);$i++){
            $take=min(8,max(0,$bits-$i*8));
            if($take===0) break;
            $mask=(0xFF << (8-$take)) & 0xFF;
            if((ord($a[$i])&$mask)!==(ord($b[$i])&$mask)) return false;
        }
        return true;
    }
}
