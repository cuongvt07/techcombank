<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
        'title', 'content', 'type', 'department_id', 'employee_id', 'course_id', 'quiz_id',
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

    /** Khóa học mà lời nhắc học / nhắc làm lại trỏ tới (chuông bấm vào đi thẳng tới đó). */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
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

    // ---- Chuông thông báo -------------------------------------------------------

    /**
     * Thông báo được tính vào chuông của nhân viên: đang hiển thị + áp cho họ.
     *
     * Quy tắc "người mới": thông báo toàn công ty phát hành TRƯỚC khi nhân viên có
     * tài khoản thì không tính — người mới vào không phải nhận cả trăm thông báo
     * cũ chưa đọc. Thông báo riêng luôn tính (gửi đích danh thì cần thấy).
     * Mốc lấy theo users.created_at; nhân viên chưa có tài khoản dùng
     * employees.created_at.
     */
    public function scopeNotifiableFor(Builder $query, Employee $employee): Builder
    {
        $since = self::notifySince($employee);

        return $query->visible()
            ->forEmployee($employee)
            ->where(function ($q) use ($since) {
                $q->whereNotNull('employee_id');

                if ($since) {
                    $q->orWhere('published_at', '>=', $since);
                } else {
                    $q->orWhereNull('employee_id');
                }
            });
    }

    /** Thông báo của nhân viên mà họ chưa xem. */
    public function scopeUnreadFor(Builder $query, Employee $employee): Builder
    {
        return $query->notifiableFor($employee)
            ->whereDoesntHave('reads', fn ($q) => $q->where('employee_id', $employee->id));
    }

    /** Gắn cờ is_read (withExists) để view hiện chấm đỏ mà không N+1. */
    public function scopeWithReadFlag(Builder $query, Employee $employee): Builder
    {
        return $query->withExists([
            'reads as is_read' => fn ($q) => $q->where('employee_id', $employee->id),
        ]);
    }

    public static function unreadCountFor(Employee $employee): int
    {
        return self::query()->unreadFor($employee)->count();
    }

    /** Đánh dấu đã xem — gọi lại nhiều lần không sao (unique + insertOrIgnore). */
    public function markReadBy(Employee $employee): void
    {
        AnnouncementRead::insertOrIgnore([
            'announcement_id' => $this->id,
            'employee_id' => $employee->id,
            'read_at' => now(),
        ]);
    }

    /** @return int số thông báo vừa được đánh dấu */
    public static function markAllReadFor(Employee $employee): int
    {
        $now = now();
        $rows = self::query()->unreadFor($employee)->pluck('id')
            ->map(fn ($id) => ['announcement_id' => $id, 'employee_id' => $employee->id, 'read_at' => $now])
            ->all();

        return $rows ? AnnouncementRead::insertOrIgnore($rows) : 0;
    }

    /** Lời nhắc học / nhắc làm lại bài kiểm tra (gửi từ báo cáo). */
    public function isLearningReminder(): bool
    {
        return $this->type === self::TYPE_COURSE && $this->employee_id !== null;
    }

    /**
     * Trang mở ra khi bấm thông báo ở chuông: lời nhắc khóa học → khóa đó (hoặc
     * Khóa học của tôi nếu không rõ khóa); còn lại → đúng sự kiện trên trang Sự kiện.
     */
    public function targetUrl(): string
    {
        if ($this->type === self::TYPE_COURSE) {
            return $this->course?->slug
                ? route('learn.course', $this->course)
                : route('learn.courses');
        }

        return route('learn.events', ['su-kien' => $this->id]);
    }

    private static function notifySince(Employee $employee): ?Carbon
    {
        $user = $employee->relationLoaded('user') ? $employee->user : $employee->user()->first(['id', 'created_at']);

        return $user?->created_at ?? $employee->created_at;
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
