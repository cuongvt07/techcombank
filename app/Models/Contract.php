<?php

namespace App\Models;

use App\Models\Concerns\HasAutoCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hợp đồng lao động (spec 3.1.3).
 *
 * File scan nằm trong kho tài nguyên chung (stored_files), hợp đồng chỉ giữ
 * tham chiếu. Quyền xem giới hạn ở tầng Policy (HR + quản lý trực tiếp).
 */
class Contract extends Model
{
    use HasAutoCode, SoftDeletes;

    /** Số hợp đồng tự sinh: HD-{năm tạo}-{số thứ tự}, số thứ tự chạy liên tục qua các năm. */
    protected const CODE_COLUMN = 'contract_no';
    protected const CODE_PREFIX = 'HD-';
    protected const CODE_PAD = 4;

    protected static function codePrefix(): string
    {
        return 'HD-' . now()->year . '-';
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRING = 'expiring';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_TERMINATED = 'terminated';

    /** Số ngày mặc định trước hạn thì bắt đầu cảnh báo, ghi đè được ở từng hợp đồng. */
    public const DEFAULT_ALERT_BEFORE_DAYS = 30;

    protected $fillable = [
        'contract_no', 'employee_id', 'contract_type_id', 'stored_file_id', 'signed_at',
        'effective_from', 'effective_to', 'status', 'alert_before_days',
        'alert_sent_at', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'date',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'alert_sent_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    /** Bản scan hợp đồng, lưu trong kho tài nguyên chung. */
    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function alertBeforeDays(): int
    {
        return $this->alert_before_days ?? self::DEFAULT_ALERT_BEFORE_DAYS;
    }

    /** Số ngày còn lại đến hạn; null với hợp đồng không xác định thời hạn. */
    public function daysUntilExpiry(): ?int
    {
        if (! $this->effective_to) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->effective_to->startOfDay(), false);
    }

    public function isExpired(): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null && $days < 0;
    }

    /** Đã bước vào ngưỡng cảnh báo nhưng chưa hết hạn (spec 3.1.3). */
    public function isExpiring(): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null && $days >= 0 && $days <= $this->alertBeforeDays();
    }

    /**
     * Hợp đồng sắp hết hạn cần gửi cảnh báo.
     * So sánh trực tiếp trên SQL để job quét hằng ngày không phải nạp toàn bộ bản ghi.
     */
    public function scopeNeedsExpiryAlert(Builder $query): Builder
    {
        $default = self::DEFAULT_ALERT_BEFORE_DAYS;

        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_EXPIRING])
            ->whereNotNull('effective_to')
            ->whereRaw(
                'DATEDIFF(effective_to, CURDATE()) BETWEEN 0 AND COALESCE(alert_before_days, ?)',
                [$default]
            );
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('effective_to')
            ->whereDate('effective_to', '<', now())
            ->whereNotIn('status', [self::STATUS_EXPIRED, self::STATUS_TERMINATED]);
    }

    /**
     * Cập nhật trạng thái theo ngày: quá hạn -> hết hạn, sắp tới hạn -> sắp hết hạn.
     *
     * Chạy tự động khi mở màn Hợp đồng / chi tiết nhân viên và theo lịch hằng
     * ngày, thay cho nút "Đồng bộ trạng thái" bấm tay trước đây. Chỉ đụng các
     * bản ghi cần đổi nên gọi nhiều lần không tốn kém.
     *
     * @return array{expired: int, expiring: int}
     */
    public static function syncStatuses(): array
    {
        $expired = static::expired()->update(['status' => self::STATUS_EXPIRED]);

        $expiring = static::needsExpiryAlert()
            ->where('status', self::STATUS_ACTIVE)
            ->update(['status' => self::STATUS_EXPIRING]);

        return ['expired' => $expired, 'expiring' => $expiring];
    }
}
