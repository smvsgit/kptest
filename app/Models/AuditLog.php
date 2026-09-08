<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    protected $guarded = [];
    protected $casts = ['context'=>'array'];
    public function user() { return $this->belongsTo(User::class); }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('Audit logs are append-only and cannot be updated.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Audit logs are append-only and cannot be deleted.');
    }
}
