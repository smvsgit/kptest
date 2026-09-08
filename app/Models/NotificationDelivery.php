<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'portal_notification_id','user_id','event','category','channel','provider','recipient','status',
        'attempts','last_attempt_at','sent_at','error','response_meta',
    ];
    protected $casts = [
        'last_attempt_at'=>'datetime','sent_at'=>'datetime','response_meta'=>'array',
    ];
    public function user() { return $this->belongsTo(User::class); }
    public function portalNotification() { return $this->belongsTo(PortalNotification::class); }
}
