<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    /**
     * Các cấp bậc quy tắc áp dụng. Rỗng = không ràng buộc cấp bậc.
     */
    public function jobGrades(): BelongsToMany
    {
        return $this->belongsToMany(JobGrade::class, 'document_access_rule_job_grade')->orderBy('level');
    }

    /**
     * Id cấp bậc quy tắc ràng buộc: bảng nối, cộng cột job_grade_id cũ phòng khi
     * dữ liệu còn sót trước lúc chuyển sang bảng nối.
     *
     * @return array<int, int>
     */
    public function gradeIds(): array
    {
        $ids = $this->relationLoaded('jobGrades')
            ? $this->jobGrades->pluck('id')->all()
            : $this->jobGrades()->pluck('job_grades.id')->all();

        if ($this->job_grade_id) {
            $ids[] = $this->job_grade_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
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
    /**
     * @param  array<int, int>|null  $allowedDepartmentIds  Phòng ban hợp lệ đã tính sẵn (gồm phòng con nếu có
     *                                                     include_sub_departments) — truyền vào khi kiểm hàng loạt
     *                                                     để khỏi truy vấn cây phòng ban mỗi lần.
     */
    public function matches(Employee $employee, ?array $allowedDepartmentIds = null): bool
    {
        if ($this->department_id) {
            $allowedDepartmentIds ??= $this->include_sub_departments && $this->department
                ? $this->department->descendantIds()
                : [$this->department_id];

            if (! in_array($employee->department_id, $allowedDepartmentIds, true)) {
                return false;
            }
        }

        if ($this->job_title_id && $employee->job_title_id !== $this->job_title_id) {
            return false;
        }

        // Nhiều cấp bậc: nhân viên chỉ cần thuộc một trong số đó
        $gradeIds = $this->gradeIds();

        if ($gradeIds !== [] && ! collect($gradeIds)->contains(fn ($id) => $employee->hasJobGrade($id))) {
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
