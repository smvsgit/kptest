<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FontFamily extends Model
{
    protected $fillable = ['name', 'slug', 'css_family', 'source', 'scripts', 'package', 'is_system', 'is_active'];
    protected $casts = ['scripts' => 'array', 'is_system' => 'boolean', 'is_active' => 'boolean'];

    public function files()
    {
        return $this->hasMany(FontFile::class);
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'custom-font';
        $slug = $base;
        $i = 2;
        while (static::query()->where('slug', $slug)->exists()) $slug = $base.'-'.$i++;
        return $slug;
    }
}
