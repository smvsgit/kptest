<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class IntegrationHealthCheck extends Model { public $timestamps=false; protected $fillable=['integration_connection_id','media_source_id','status','latency_ms','message','metadata','checked_at']; protected $casts=['metadata'=>'array','checked_at'=>'datetime']; public function connection(){return $this->belongsTo(IntegrationConnection::class,'integration_connection_id');} public function source(){return $this->belongsTo(MediaSource::class,'media_source_id');} }
