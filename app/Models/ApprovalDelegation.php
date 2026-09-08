<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApprovalDelegation extends Model { protected $fillable=['department_id','delegator_user_id','delegate_user_id','starts_at','ends_at','is_active','reason']; protected $casts=['starts_at'=>'datetime','ends_at'=>'datetime','is_active'=>'boolean']; public function department(){return $this->belongsTo(Department::class);} public function delegator(){return $this->belongsTo(User::class,'delegator_user_id');} public function delegate(){return $this->belongsTo(User::class,'delegate_user_id');} public function scopeActiveNow($q){return $q->where('is_active',true)->where('starts_at','<=',now())->where('ends_at','>=',now());} }
