<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaAccessRequest extends Model
{
    protected $fillable = [
        'media_file_id', 'user_id', 'access_level', 'reason', 'status',
        'decision_note','decided_by','decided_at','expires_at','revoked_at','revoke_reason','reviewed_via_delegation_id','last_escalated_at','escalation_count',
    ];

    protected $casts=['decided_at'=>'datetime','expires_at'=>'datetime','revoked_at'=>'datetime','last_escalated_at'=>'datetime','escalation_count'=>'integer'];

    public function mediaFile() { return $this->belongsTo(MediaFile::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function decider() { return $this->belongsTo(User::class, 'decided_by'); }
}
