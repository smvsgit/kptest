<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name','email','password','phone','department_id','organization_unit_id','role','portal_role_id','permission_set_id',
        'status','status_reason','status_changed_at','status_changed_by','failed_login_count','locked_until','last_login_at','last_seen_at',
        'must_change_password','preferred_language','ui_theme','two_factor_secret','two_factor_recovery_codes','two_factor_confirmed_at',
        'external_access_allowed','external_access_starts_at','external_access_expires_at','external_access_reason','external_access_approved_by',
    ];

    protected $hidden = ['password','remember_token','two_factor_secret','two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at'=>'datetime',
            'password'=>'hashed',
            'status_changed_at'=>'datetime',
            'locked_until'=>'datetime',
            'last_login_at'=>'datetime',
            'last_seen_at'=>'datetime',
            'must_change_password'=>'boolean',
            'two_factor_confirmed_at'=>'datetime',
            'external_access_allowed'=>'boolean',
            'external_access_starts_at'=>'datetime',
            'external_access_expires_at'=>'datetime',
        ];
    }

    public function department(){return $this->belongsTo(Department::class);}
    public function organizationUnit(){return $this->belongsTo(OrganizationUnit::class);}
    public function permissionSet(){return $this->belongsTo(PermissionSet::class);}
    public function portalRole(){return $this->belongsTo(PortalRole::class);}
    public function groups(){return $this->belongsToMany(UserGroup::class,'user_group_members')->withPivot('added_by')->withTimestamps();}
    public function externalAccessApprover(){return $this->belongsTo(User::class,'external_access_approved_by');}
    public function statusHistory(){return $this->hasMany(UserStatusHistory::class);}
    public function ownedFiles(){return $this->hasMany(MediaFile::class,'owner_user_id');}
    public function uploadedFiles(){return $this->hasMany(MediaFile::class, 'uploaded_by');}
    public function savedSearches(){return $this->hasMany(SavedSearch::class);}
    public function mediaFavorites(){return $this->hasMany(MediaFavorite::class);}
    public function mediaRecentViews(){return $this->hasMany(MediaRecentView::class);}
    public function notificationPreference(){return $this->hasOne(NotificationPreference::class);}
    public function notificationDeliveries(){return $this->hasMany(NotificationDelivery::class);}

    public function isActive(): bool
    {
        return ($this->status ?? 'active')==='active' && (!$this->locked_until || $this->locked_until->isPast());
    }

    public function externalAccessIsActive(): bool
    {
        if (!$this->external_access_allowed) return false;
        if ($this->external_access_starts_at && $this->external_access_starts_at->isFuture()) return false;
        if ($this->external_access_expires_at && $this->external_access_expires_at->isPast()) return false;
        return true;
    }

    public function hasPermission(string $key): bool
    {
        $base = match ($key) {
            'upload' => in_array($this->role, ['super-admin','department-admin','department-operator'], true),
            'delete' => in_array($this->role, ['super-admin','department-admin'], true),
            'manage_policy','manage_lifecycle' => in_array($this->role, ['super-admin','department-admin','department-operator'], true),
            'review_access' => in_array($this->role, ['super-admin','department-admin'], true),
            'manage_categories' => in_array($this->role, ['super-admin','department-admin'], true),
            default => false,
        };
        if (!$base) return false;

        // A custom/built-in portal role can reduce the underlying base role, never elevate it.
        $portalRole = $this->relationLoaded('portalRole') ? $this->portalRole : $this->portalRole()->first();
        if ($portalRole && $portalRole->is_active) {
            $permissions = $portalRole->permissions ?? [];
            if (array_key_exists($key, $permissions) && !$permissions[$key]) return false;
        }

        // Legacy permission sets remain a second least-privilege reducer.
        $set = $this->relationLoaded('permissionSet') ? $this->permissionSet : $this->permissionSet()->first();
        if (!$set || !$set->is_active) return $base;
        $permissions = $set->permissions ?? [];
        return array_key_exists($key,$permissions) ? (bool) $permissions[$key] : $base;
    }

    public function canAccessPage(string $page): bool
    {
        if ($page === 'browse') return true;
        if ($this->role === 'super-admin' && $page === 'settings') return true; // prevent admin-console self-lockout

        $portalRole = $this->relationLoaded('portalRole') ? $this->portalRole : $this->portalRole()->first();
        if ($portalRole && $portalRole->is_active) {
            $pages = $portalRole->page_access ?? [];
            if (array_key_exists($page, $pages)) return (bool) $pages[$page];
        }

        return match ($page) {
            'upload' => $this->canUpload(),
            'access' => true,
            'reports' => true,
            'integrations' => in_array($this->role,['super-admin','department-admin'],true),
            'guide' => true,
            'settings' => in_array($this->role,['super-admin','department-admin'],true),
            default => false,
        };
    }

    public function roleDisplayName(): string
    {
        $portalRole = $this->relationLoaded('portalRole') ? $this->portalRole : $this->portalRole()->first();
        return $portalRole?->name ?: ucwords(str_replace('-',' ', $this->role));
    }

    public function canUpload(): bool{return $this->hasPermission('upload');}
    public function canDelete(): bool{return $this->hasPermission('delete');}
    public function canManageRoles(): bool{return $this->role === 'super-admin';}
    public function canConfigCategories(): bool{return $this->hasPermission('manage_categories');}
    public function canManageDepartments(): bool{return $this->role === 'super-admin';}
}
