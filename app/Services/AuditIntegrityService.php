<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditIntegrityService
{
    public function payload(array $values): array
    {
        return [
            'user_id' => $values['user_id'] ?? null,
            'event' => $values['event'] ?? null,
            'auditable_type' => $values['auditable_type'] ?? null,
            'auditable_id' => $values['auditable_id'] ?? null,
            'description' => $values['description'] ?? null,
            'context' => $values['context'] ?? null,
            'ip_address' => $values['ip_address'] ?? null,
            'user_agent' => $values['user_agent'] ?? null,
            'session_id' => $values['session_id'] ?? null,
            'source' => $values['source'] ?? 'web',
            'created_at' => $values['created_at'] instanceof \DateTimeInterface
                ? $values['created_at']->format('Y-m-d H:i:s')
                : (string)($values['created_at'] ?? ''),
            'previous_hash' => $values['previous_hash'] ?? null,
        ];
    }

    public function hash(array $values): string
    {
        return hash('sha256', json_encode($this->payload($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function verify(): array
    {
        $previous = null;
        $count = 0;
        foreach (AuditLog::query()->orderBy('id')->cursor() as $log) {
            $count++;
            if ($log->previous_hash !== $previous) {
                return ['valid'=>false,'checked'=>$count,'invalid_id'=>$log->id,'reason'=>'Previous hash does not match chain.'];
            }
            $computed = $this->hash([
                'user_id'=>$log->user_id,'event'=>$log->event,'auditable_type'=>$log->auditable_type,'auditable_id'=>$log->auditable_id,
                'description'=>$log->description,'context'=>$log->context,'ip_address'=>$log->ip_address,'user_agent'=>$log->user_agent,
                'session_id'=>$log->session_id,'source'=>$log->source,'created_at'=>$log->created_at,'previous_hash'=>$log->previous_hash,
            ]);
            if (!hash_equals((string)$log->record_hash, $computed)) {
                return ['valid'=>false,'checked'=>$count,'invalid_id'=>$log->id,'reason'=>'Record hash does not match content.'];
            }
            $previous = $log->record_hash;
        }
        return ['valid'=>true,'checked'=>$count,'invalid_id'=>null,'reason'=>null,'last_hash'=>$previous];
    }
}
