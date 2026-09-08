<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MediaStatusHistory extends Model { protected $fillable=['media_file_id','from_status','to_status','note','changed_by']; }
