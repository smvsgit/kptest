<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrganizationUnit extends Model { protected $fillable=['department_id','parent_id','type','name','is_active']; protected $casts=['is_active'=>'boolean']; public function department(){return $this->belongsTo(Department::class);} public function parent(){return $this->belongsTo(self::class,'parent_id');} public function children(){return $this->hasMany(self::class,'parent_id');} public function users(){return $this->hasMany(User::class);} }
