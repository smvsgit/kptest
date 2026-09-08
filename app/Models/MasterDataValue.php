<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterDataValue extends Model
{
    protected $fillable = ['type','name','code','parent_id','aliases','extra','is_active','sort_order'];
    protected $casts = ['aliases'=>'array','extra'=>'array','is_active'=>'boolean'];
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
}
