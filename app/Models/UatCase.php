<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class UatCase extends Model {
    protected $fillable=['code','title','priority','category','sort_order','status','execution_notes','evidence_reference','executed_by','executed_at','executed_app_version','approved_by','approved_at','approved_app_version'];
    protected $casts=['executed_at'=>'datetime','approved_at'=>'datetime'];
    public function executedBy(){return $this->belongsTo(User::class,'executed_by');}
    public function approvedBy(){return $this->belongsTo(User::class,'approved_by');}
}
