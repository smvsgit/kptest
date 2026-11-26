<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class McpAccessToken extends Model
{
    /** Recognisable at a glance in a log or a config file. */
    public const PREFIX = 'kpmcp_';

    protected $fillable = [
        'user_id', 'name', 'token_hash', 'hint', 'last_used_at', 'last_used_ip',
        'expires_at', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function hashFor(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Mint a token for one person. The plain text is returned once and never
     * stored; only its hash is kept.
     *
     * @return array{token: McpAccessToken, plain: string}
     */
    public static function issue(User $owner, string $name, ?User $actor = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = self::PREFIX.Str::random(48);

        $token = self::create([
            'user_id' => $owner->id,
            'name' => $name,
            'token_hash' => self::hashFor($plain),
            'hint' => substr($plain, 0, 12),
            'expires_at' => $expiresAt,
            'created_by' => ($actor ?: $owner)->id,
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }
}
