<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class UserStatusHistory extends Model { protected $fillable=['user_id','from_status','to_status','reason','changed_by','context']; protected $casts=['context'=>'array']; public function user(){return $this->belongsTo(User::class);} public function changedBy(){return $this->belongsTo(User::class,'changed_by');} }
