<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FontFile extends Model
{
    protected $fillable = ['font_family_id', 'weight', 'style', 'path', 'original_name', 'mime'];
    protected $appends = ['asset_url'];

    public function family() { return $this->belongsTo(FontFamily::class, 'font_family_id'); }
    public function getAssetUrlAttribute(): string { return route('fonts.asset', $this); }
}
