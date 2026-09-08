<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class GoLiveReview extends Model {
    protected $fillable=['readiness_snapshot_id','decision','app_version','notes','dependency_snapshot','reviewed_by','reviewed_at'];
    protected $casts=['dependency_snapshot'=>'array','reviewed_at'=>'datetime'];
    public function snapshot(){return $this->belongsTo(ReadinessSnapshot::class,'readiness_snapshot_id');}
    public function reviewedBy(){return $this->belongsTo(User::class,'reviewed_by');}
}
