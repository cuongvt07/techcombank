<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rule phân quyền tài liệu theo điều kiện (spec 3.3.1).
 * Quyền tính động tại thời điểm truy cập, nên nhân viên đổi phòng ban/cấp bậc
 * là quyền đổi theo ngay, không cần job đồng bộ lại.
 */
class DocumentAccessRule extends Model
{
    public const EFFECT_ALLOW = 'allow';
    public const EFFECT_DENY = 'deny';

    protected $fillable = [
        'document_id', 'document_category_id', 'department_id', 'job_title_id',
        'job_grade_id', 'min_grade_level', 'employment_status',
        'include_sub_departments', 'can_view', 'can_download', 'can_print',
        'effect', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'include_sub_departments' => 'boolean',
            'can_view' => 'boolean',
            'can_download' => 'boolean',
            'can_print' => 'boolean',
            'min_grade_level' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isDeny(): bool
    {
        return $this->effect === self::EFFECT_DENY;
    }

    /**
     * Nhân viên có khớp toàn bộ điều kiện của rule không.
     * Điều kiện NULL = không ràng buộc chiều đó, nên rule trống khớp mọi nhân viên.
     */
    public function matches(Employee $employee): bool
    {
        if ($this->department_id) {
            $allowedDepartmentIds = $this->include_sub_departments && $this->department
                ? $this->department->descendantIds()
                : [$this->department_id];

            if (! in_array($employee->department_id, $allowedDepartmentIds, true)) {
                return false;
            }
        }

        if ($this->job_title_id && $employee->job_title_id !== $this->job_title_id) {
            return false;
        }

        if ($this->job_grade_id && $employee->job_grade_id !== $this->job_grade_id) {
            return false;
        }

        // Điều kiện "từ cấp bậc N trở lên"
        if ($this->min_grade_level !== null) {
            $level = $employee->jobGrade?->level;

            if ($level === null || $level < $this->min_grade_level) {
                return false;
            }
        }

        if ($this->employment_status && $employee->employment_status !== $this->employment_status) {
            return false;
        }

        return true;
    }
}
