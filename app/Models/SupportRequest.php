<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Yêu cầu hỗ trợ có theo dõi trạng thái (spec 4.5). */
class SupportRequest extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_LOW = 'low';

    /** SLA theo độ ưu tiên, tính bằng giờ lịch (không trừ ngày nghỉ). */
    public const SLA_HOURS = [
        self::PRIORITY_HIGH => 8,
        self::PRIORITY_NORMAL => 24,
        self::PRIORITY_LOW => 72,
    ];

    public const PRIORITY_LABELS = [
        self::PRIORITY_HIGH => 'Cao',
        self::PRIORITY_NORMAL => 'Thường',
        self::PRIORITY_LOW => 'Thấp',
    ];

    public const STATUS_LABELS = [
        self::STATUS_NEW => 'Mới',
        self::STATUS_IN_PROGRESS => 'Đang xử lý',
        self::STATUS_RESOLVED => 'Đã giải quyết',
        self::STATUS_CLOSED => 'Đã đóng',
    ];

    protected $fillable = [
        'ticket_no', 'employee_id', 'topic', 'subject', 'content',
        'priority', 'status', 'assigned_to', 'resolution', 'resolved_at',
        'sla_due_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Hạn SLA chốt lúc tạo phiếu, theo độ ưu tiên lúc đó
        static::creating(function (self $request) {
            if (! $request->sla_due_at) {
                $hours = self::SLA_HOURS[$request->priority ?? self::PRIORITY_NORMAL] ?? 24;
                $request->sla_due_at = ($request->created_at ?? now())->copy()->addHours($hours);
            }
        });
    }

    /** Đã quá hạn SLA: còn mở mà quá hạn, hoặc giải quyết sau hạn. */
    public function isOverSla(): bool
    {
        if (! $this->sla_due_at) {
            return false;
        }

        $doneAt = $this->resolved_at ?? $this->closed_at;

        return ($doneAt ?? now())->greaterThan($this->sla_due_at);
    }

    /** Phiếu còn mở và đã quá hạn SLA tại thời điểm $at (mặc định bây giờ). */
    public function scopeOverSla($query, $at = null)
    {
        return $query->open()->where('sla_due_at', '<', $at ?? now());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_NEW, self::STATUS_IN_PROGRESS]);
    }
}
