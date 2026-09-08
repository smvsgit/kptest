<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class IntegrationConnection extends Model {
 protected $fillable=['name','type','is_active','root_path','base_url','credentials_encrypted','sync_frequency_minutes','storage_warning_percent','status','last_checked_at','last_success_at','last_error','capacity_bytes','used_bytes','free_bytes','metadata'];
 protected $casts=['is_active'=>'boolean','last_checked_at'=>'datetime','last_success_at'=>'datetime','metadata'=>'array'];
 protected $hidden=['credentials_encrypted'];
 public function sources(){return $this->hasMany(MediaSource::class);}
 public function checks(){return $this->hasMany(IntegrationHealthCheck::class);}
}
