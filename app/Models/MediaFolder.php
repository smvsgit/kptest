<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MediaFolder extends Model { protected $fillable=['department_id','parent_id','name','description','is_active','created_by']; protected $casts=['is_active'=>'boolean']; public function parent(){return $this->belongsTo(self::class,'parent_id');} public function children(){return $this->hasMany(self::class,'parent_id');} public function files(){return $this->hasMany(MediaFile::class,'folder_id');} }
