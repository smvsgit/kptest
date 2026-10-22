<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'is_active', 'is_system'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function users(){return $this->hasMany(User::class);}
    public function organizationUnits(){return $this->hasMany(OrganizationUnit::class);}
    public function mediaFiles(){return $this->hasMany(MediaFile::class);}

    /** A department can only be deleted once nothing depends on it. */
    public function isDeletable(): bool
    {
        return !$this->is_system
            && !$this->users()->exists()
            && !MediaFile::withTrashed()->where('department_id', $this->id)->exists()
            && !$this->organizationUnits()->exists();
    }
}
