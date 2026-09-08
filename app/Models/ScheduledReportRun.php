<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ScheduledReportRun extends Model { protected $fillable=['scheduled_report_id','status','file_path','checksum_sha256','row_count','error','started_at','finished_at']; protected $casts=['started_at'=>'datetime','finished_at'=>'datetime']; public function scheduledReport(){return $this->belongsTo(ScheduledReport::class);} }
