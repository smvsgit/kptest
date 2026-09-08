<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id','portal_enabled','email_enabled','whatsapp_enabled','sms_enabled',
        'access_enabled','file_enabled','storage_enabled','security_enabled',
    ];
    protected $casts = [
        'portal_enabled'=>'boolean','email_enabled'=>'boolean','whatsapp_enabled'=>'boolean','sms_enabled'=>'boolean',
        'access_enabled'=>'boolean','file_enabled'=>'boolean','storage_enabled'=>'boolean','security_enabled'=>'boolean',
    ];
    public function user() { return $this->belongsTo(User::class); }
}
