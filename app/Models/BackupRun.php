<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BackupRun extends Model {
    protected $fillable=['trigger','retention_class','status','destination_type','storage_path','filename','size_bytes','sha256','format_version','encrypted','scope','manifest','triggered_by','started_at','completed_at','verified_at','error'];
    protected $casts=['encrypted'=>'boolean','scope'=>'array','manifest'=>'array','started_at'=>'datetime','completed_at'=>'datetime','verified_at'=>'datetime'];
    public function triggeredBy(){return $this->belongsTo(User::class,'triggered_by');}
    public function verifications(){return $this->hasMany(RestoreVerification::class);}
}
