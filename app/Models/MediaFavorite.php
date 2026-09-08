<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaFavorite extends Model
{
    protected $fillable = ['user_id', 'media_file_id'];
    public function user() { return $this->belongsTo(User::class); }
    public function mediaFile() { return $this->belongsTo(MediaFile::class); }
}
