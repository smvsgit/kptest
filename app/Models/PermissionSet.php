<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PermissionSet extends Model { protected $fillable=['name','description','permissions','is_active']; protected $casts=['permissions'=>'array','is_active'=>'boolean']; public function users(){return $this->hasMany(User::class);} }
