<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserGroup extends Model
{
    protected $fillable = ['department_id','name','description','portal_role_id','is_active','created_by'];

    protected function casts(): array
    {
        return ['is_active'=>'boolean'];
    }

    public function department(){return $this->belongsTo(Department::class);}
    public function portalRole(){return $this->belongsTo(PortalRole::class);}
    public function members(){return $this->belongsToMany(User::class,'user_group_members')->withPivot('added_by')->withTimestamps();}
}
