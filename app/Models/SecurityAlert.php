<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cảnh báo truy cập bất thường (spec 3.3.2). */
class SecurityAlert extends Model
{
    public const TYPE_MULTI_DEVICE = 'multi_device';
    public const TYPE_MASS_DOWNLOAD = 'mass_download';
    public const TYPE_UNUSUAL_IP = 'unusual_ip';
    public const TYPE_BRUTE_FORCE = 'brute_force';

    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_FALSE_POSITIVE = 'false_positive';

    protected $fillable = [
        'user_id', 'type', 'severity', 'title', 'detail', 'context',
        'status', 'handled_by', 'handled_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'handled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
