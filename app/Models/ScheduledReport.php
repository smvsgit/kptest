<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ScheduledReport extends Model { protected $fillable=['name','report_type','frequency','format','is_active','recipient_user_id','department_id','filters','last_run_at','next_run_at','created_by']; protected $casts=['filters'=>'array','is_active'=>'boolean','last_run_at'=>'datetime','next_run_at'=>'datetime']; public function recipient(){return $this->belongsTo(User::class,'recipient_user_id');} public function runs(){return $this->hasMany(ScheduledReportRun::class);} }
