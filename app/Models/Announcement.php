<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sự kiện / thông báo gửi tới nhân viên (spec 3.1.5).
 *
 * Hai phạm vi gán:
 *  - employee_id NULL  : toàn công ty
 *  - employee_id có giá trị : chỉ riêng nhân viên đó
 */
class Announcement extends Model
{
    public const TYPE_GENERAL = 'general';
    public const TYPE_COURSE = 'course';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_CONTRACT = 'contract';
    public const TYPE_SYSTEM = 'system';

    public const TYPES = [
        self::TYPE_GENERAL => 'Chung',
        self::TYPE_COURSE => 'Đào tạo',
        self::TYPE_DOCUMENT => 'Tài liệu',
        self::TYPE_CONTRACT => 'Hợp đồng',
        self::TYPE_SYSTEM => 'Hệ thống',
    ];

    protected $fillable = [
        'title', 'content', 'type', 'department_id', 'employee_id',
        'starts_at', 'ends_at', 'location',
        'published_at', 'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Thông báo đang hiển thị: đã phát hành và chưa hết hạn. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Sự kiện một nhân viên được xem: của riêng họ + của toàn công ty.
     *
     * Không dùng orWhere trần ở tầng gọi — lồng trong closure để điều kiện này
     * không nuốt mất các điều kiện khác trong cùng truy vấn (ví dụ scopeVisible).
     */
    public function scopeForEmployee(Builder $query, ?Employee $employee): Builder
    {
        return $query->where(function ($q) use ($employee) {
            $q->whereNull('employee_id');

            if ($employee) {
                $q->orWhere('employee_id', $employee->id);
            }
        });
    }

    /**
     * Sắp xếp cho site người dùng. Thứ tự ưu tiên:
     *   1. Sự kiện gán riêng cho mình
     *   2. Đang diễn ra  — cần biết ngay lúc này
     *   3. Sắp diễn ra   — gần nhất trước
     *   4. Không có mốc thời gian (thông báo chung)
     *   5. Đã kết thúc
     *
     * Nhóm "đang diễn ra" phải tách khỏi "đã bắt đầu": một sự kiện đang chạy mà
     * xếp chung với sự kiện đã qua thì bị đẩy xuống đáy, đúng lúc nhân viên cần
     * thấy nó nhất.
     */
    public function scopeOrderedForEmployee(Builder $query): Builder
    {
        // Truyền mốc thời gian từ PHP thay vì dùng NOW() của MySQL: ứng dụng chạy
        // UTC còn NOW() trả giờ máy chủ (UTC+7), lệch 7 tiếng thì sự kiện đang
        // diễn ra bị xếp nhầm sang nhóm đã kết thúc.
        $now = now();

        return $query
            ->orderByRaw('employee_id IS NULL')
            ->orderByRaw(
                'CASE
                    WHEN starts_at IS NOT NULL
                         AND starts_at <= ?
                         AND COALESCE(ends_at, starts_at) > ? THEN 0
                    WHEN starts_at > ? THEN 1
                    WHEN starts_at IS NULL THEN 2
                    ELSE 3
                END',
                [$now, $now, $now]
            )
            // Trong nhóm sắp diễn ra: gần nhất lên trước
            ->orderByRaw('CASE WHEN starts_at > ? THEN starts_at END', [$now])
            ->orderByDesc('published_at');
    }

    public function isPersonal(): bool
    {
        return $this->employee_id !== null;
    }

    /** Sự kiện đã kết thúc chưa. Không có mốc thời gian thì coi như còn hiệu lực. */
    public function hasEnded(): bool
    {
        $end = $this->ends_at ?? $this->starts_at;

        return $end !== null && $end->isPast();
    }

    public function isUpcoming(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isFuture();
    }

    public function isHappeningNow(): bool
    {
        if (! $this->starts_at || $this->starts_at->isFuture()) {
            return false;
        }

        return ($this->ends_at ?? $this->starts_at)->isFuture();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
