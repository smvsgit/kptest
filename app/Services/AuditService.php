<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditService
{
    public function __construct(private AuditIntegrityService $integrity) {}

    public function log(Request $request, string $event, ?Model $subject = null, ?string $description = null, array $context = []): AuditLog
    {
        return $this->write([
            'user_id'=>$request->user()?->id,
            'event'=>$event,
            'auditable_type'=>$subject ? get_class($subject) : null,
            'auditable_id'=>$subject?->getKey(),
            'description'=>$description,
            'context'=>$context ?: null,
            'ip_address'=>$request->ip(),
            'user_agent'=>mb_substr((string)$request->userAgent(),0,500),
            'session_id'=>$request->hasSession() ? $request->session()->getId() : null,
            'source'=>'web',
        ]);
    }

    public function logSystem(string $event, ?Model $subject = null, ?string $description = null, array $context = [], string $source = 'scheduler'): AuditLog
    {
        return $this->write([
            'user_id'=>null,'event'=>$event,'auditable_type'=>$subject ? get_class($subject) : null,'auditable_id'=>$subject?->getKey(),
            'description'=>$description,'context'=>$context ?: null,'ip_address'=>null,'user_agent'=>null,'session_id'=>null,'source'=>$source,
        ]);
    }

    public function logFromRequest(?Request $request, string $event, ?Model $subject = null, ?string $description = null, array $context = []): AuditLog
    {
        if ($request) return $this->log($request,$event,$subject,$description,$context);
        return $this->logSystem($event,$subject,$description,$context,'auth');
    }

    private function write(array $values): AuditLog
    {
        return DB::transaction(function () use ($values) {
            $previous = AuditLog::query()->orderByDesc('id')->lockForUpdate()->value('record_hash');
            $createdAt = now();
            $values['previous_hash'] = $previous;
            $values['created_at'] = $createdAt;
            $values['updated_at'] = $createdAt;
            $values['record_hash'] = $this->integrity->hash($values);
            return AuditLog::create($values);
        });
    }
}
