<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phiên thiết bị, dùng để giới hạn số thiết bị đăng nhập đồng thời (spec 3.3.2). */
class DeviceSession extends Model
{
    protected $fillable = [
        'user_id', 'session_id', 'device_fingerprint', 'device_label',
        'ip_address', 'last_activity_at', 'is_active', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function revoke(): void
    {
        $this->update(['is_active' => false, 'revoked_at' => now()]);
    }
}
