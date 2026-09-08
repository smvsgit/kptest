<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReadinessSnapshot extends Model {
    protected $fillable=['overall_status','app_version','checks','summary','run_by','run_at'];
    protected $casts=['checks'=>'array','summary'=>'array','run_at'=>'datetime'];
    public function runBy(){return $this->belongsTo(User::class,'run_by');}
}
