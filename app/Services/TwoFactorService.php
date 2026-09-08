<?php
namespace App\Services;
class TwoFactorService {
 private const ALPHABET='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
 public function generateSecret(int $bytes=20): string { $raw=random_bytes($bytes); $bits=''; foreach(str_split($raw) as $c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT); $out=''; foreach(str_split($bits,5) as $chunk){if(strlen($chunk)<5)$chunk=str_pad($chunk,5,'0');$out.=self::ALPHABET[bindec($chunk)];} return $out; }
 public function verify(string $secret,string $code,int $window=1): bool { $code=preg_replace('/\D/','',$code); if(strlen($code)!==6)return false; $counter=(int)floor(time()/30); for($i=-$window;$i<=$window;$i++) if(hash_equals($this->code($secret,$counter+$i),$code))return true; return false; }
 public function code(string $secret,int $counter): string { $key=$this->decodeBase32($secret); $bin=pack('N2',($counter>>32)&0xffffffff,$counter&0xffffffff); $hash=hash_hmac('sha1',$bin,$key,true); $o=ord($hash[19])&0xf; $n=((ord($hash[$o])&0x7f)<<24)|(ord($hash[$o+1])<<16)|(ord($hash[$o+2])<<8)|ord($hash[$o+3]); return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT); }
 public function recoveryCodes(int $count=8): array { return array_map(fn()=>strtoupper(bin2hex(random_bytes(5))),range(1,$count)); }
 public function uri(string $issuer,string $email,string $secret): string { return 'otpauth://totp/'.rawurlencode($issuer.':'.$email).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&digits=6&period=30'; }
 private function decodeBase32(string $s): string { $s=strtoupper(preg_replace('/[^A-Z2-7]/','',$s));$bits='';foreach(str_split($s) as $c){$p=strpos(self::ALPHABET,$c);if($p===false)continue;$bits.=str_pad(decbin($p),5,'0',STR_PAD_LEFT);} $out='';foreach(str_split($bits,8) as $b)if(strlen($b)===8)$out.=chr(bindec($b));return $out; }
}
