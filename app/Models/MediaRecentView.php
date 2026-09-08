<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaRecentView extends Model
{
    protected $fillable = ['user_id', 'media_file_id', 'viewed_at'];
    protected $casts = ['viewed_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
    public function mediaFile() { return $this->belongsTo(MediaFile::class); }
}
