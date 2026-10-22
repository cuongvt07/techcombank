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

    public const TYPE_LABELS = [
        self::TYPE_MULTI_DEVICE => 'Đăng nhập nhiều thiết bị',
        self::TYPE_MASS_DOWNLOAD => 'Truy cập tài liệu hàng loạt',
        self::TYPE_UNUSUAL_IP => 'Đăng nhập từ IP lạ',
        self::TYPE_BRUTE_FORCE => 'Dò mật khẩu',
    ];

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? (string) $this->type;
    }

    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_FALSE_POSITIVE = 'false_positive';

    protected $fillable = [
        'user_id', 'type', 'severity', 'title', 'detail', 'context',
        'status', 'handled_by', 'handled_at', 'acknowledged_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'handled_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
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
