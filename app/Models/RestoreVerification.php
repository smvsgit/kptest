<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RestoreVerification extends Model {
    protected $fillable=['backup_run_id','verification_type','status','target_environment','duration_minutes','verified_size_bytes','verified_sha256','results','notes','verified_by','verified_at'];
    protected $casts=['results'=>'array','verified_at'=>'datetime'];
    public function backupRun(){return $this->belongsTo(BackupRun::class);}
    public function verifiedBy(){return $this->belongsTo(User::class,'verified_by');}
}
