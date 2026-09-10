<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalRole extends Model
{
    protected $fillable = ['name','slug','base_role','is_builtin','is_active','permissions','page_access','created_by'];

    protected function casts(): array
    {
        return [
            'is_builtin'=>'boolean',
            'is_active'=>'boolean',
            'permissions'=>'array',
            'page_access'=>'array',
        ];
    }

    public function users(){return $this->hasMany(User::class);}
    public function groups(){return $this->hasMany(UserGroup::class);}
}
