<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MediaSource extends Model {
 protected $fillable=['media_file_id','integration_connection_id','type','label','locator','external_id','is_primary','is_enabled','status','last_checked_at','last_success_at','broken_detected_at','last_error','repair_note','metadata'];
 protected $appends=['open_url','embed_url'];
 protected $casts=['is_primary'=>'boolean','is_enabled'=>'boolean','last_checked_at'=>'datetime','last_success_at'=>'datetime','broken_detected_at'=>'datetime','metadata'=>'array'];
 public function mediaFile(){return $this->belongsTo(MediaFile::class);}
 public function connection(){return $this->belongsTo(IntegrationConnection::class,'integration_connection_id');}
 public function checks(){return $this->hasMany(IntegrationHealthCheck::class);}
 public function youtubeId():?string { if($this->type!=='youtube')return null; $u=$this->external_id?:$this->locator; if(preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})~',$u,$m))return $m[1]; return preg_match('/^[A-Za-z0-9_-]{6,}$/',$u)?$u:null; }
 public function driveId():?string { if($this->type!=='google-drive')return null; $u=$this->external_id?:$this->locator; if(preg_match('~/d/([^/]+)~',$u,$m))return $m[1]; if(preg_match('/[?&]id=([^&]+)/',$u,$m))return $m[1]; return preg_match('/^[A-Za-z0-9_-]{10,}$/',$u)?$u:null; }
 public function getOpenUrlAttribute():?string { if($this->type==='youtube'&&($id=$this->youtubeId()))return "https://www.youtube.com/watch?v={$id}"; if($this->type==='google-drive'&&($id=$this->driveId()))return "https://drive.google.com/file/d/{$id}/view"; return null; }
 public function getEmbedUrlAttribute():?string { if($this->type==='youtube'&&($id=$this->youtubeId()))return "https://www.youtube.com/embed/{$id}"; if($this->type==='google-drive'&&($id=$this->driveId()))return "https://drive.google.com/file/d/{$id}/preview"; return null; }
}
