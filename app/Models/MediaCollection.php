<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MediaCollection extends Model { protected $fillable=['department_id','name','description','is_active','created_by']; protected $casts=['is_active'=>'boolean']; public function files(){return $this->belongsToMany(MediaFile::class,'media_collection_items','collection_id','media_file_id')->withTimestamps();} }
