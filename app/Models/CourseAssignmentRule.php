<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Điều kiện gán khoá học tự động (spec 3.2.4 + 3.3.1).
 * Cột NULL nghĩa là không ràng buộc chiều đó.
 *
 * Phòng ban nằm ở bảng nối course_assignment_rule_department: một điều kiện
 * áp cho nhiều phòng cùng lúc, không chọn phòng nào = mọi phòng ban.
 * Cột department_id cũ chỉ còn để tương thích dữ liệu chưa chuyển.
 */
class CourseAssignmentRule extends Model
{
    protected $fillable = [
        'course_id', 'name', 'department_id', 'job_title_id', 'job_grade_id',
        'employment_status', 'include_sub_departments', 'is_mandatory',
        'due_days', 'is_active',
    ];

    /** Tập phòng ban khớp đã tính — applyRule gọi matches() cho hàng nghìn nhân viên. */
    private ?array $allowedDepartmentIdsCache = null;

    private bool $allowedDepartmentIdsResolved = false;

    protected function casts(): array
    {
        return [
            'include_sub_departments' => 'boolean',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
            'due_days' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Cột cũ (một phòng ban) — chỉ đọc để tương thích, form mới ghi vào departments(). */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Gồm cả phòng ban đã xoá mềm: nếu bỏ qua, điều kiện chỉ nhắm phòng đã xoá
     * sẽ trông như "không chọn phòng nào" và bị hiểu thành giao cho mọi người.
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'course_assignment_rule_department')
            ->withTrashed();
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Điều kiện không giới hạn phòng ban: bảng nối trống và cột cũ trống.
     */
    public function scopeForAllDepartments($query)
    {
        return $query->whereNull('department_id')->whereDoesntHave('departments');
    }

    /**
     * Điều kiện áp cho phòng ban $departmentId: nhắm thẳng phòng đó hoặc phòng con
     * của nó (để lọc theo khối thấy cả khoá giao cho các phòng bên dưới), hoặc nhắm
     * phòng cha mà có "gồm phòng con". Không gồm điều kiện mọi phòng ban.
     */
    public function scopeTargetingDepartment($query, int $departmentId)
    {
        $department = Department::withTrashed()->find($departmentId);
        $belowIds = $department ? $department->descendantIds() : [$departmentId];

        $ancestorIds = [];
        $node = $department?->parent;
        while ($node && ! in_array($node->id, $ancestorIds, true)) {
            $ancestorIds[] = $node->id;
            $node = $node->parent;
        }

        return $query->where(function ($q) use ($belowIds, $ancestorIds) {
            $q->whereHas('departments', fn ($d) => $d->whereIn('departments.id', $belowIds))
                ->orWhereIn('department_id', $belowIds);

            if ($ancestorIds) {
                $q->orWhere(fn ($sub) => $sub->where('include_sub_departments', true)
                    ->where(fn ($s) => $s
                        ->whereHas('departments', fn ($d) => $d->whereIn('departments.id', $ancestorIds))
                        ->orWhereIn('department_id', $ancestorIds)));
            }
        });
    }

    /**
     * Phòng ban được chọn (bảng nối + cột cũ nếu còn), đã bỏ trùng.
     *
     * @return Collection<int, Department>
     */
    public function targetDepartments(): Collection
    {
        $departments = $this->departments->values();

        if ($this->department_id && ! $departments->contains('id', $this->department_id)) {
            $legacy = $this->department ?? Department::withTrashed()->find($this->department_id);
            if ($legacy) {
                $departments->push($legacy);
            }
        }

        return $departments;
    }

    /**
     * Danh sách department_id mà nhân viên phải thuộc để khớp, đã gồm phòng con
     * khi bật include_sub_departments. NULL = mọi phòng ban.
     */
    public function allowedDepartmentIds(): ?array
    {
        if ($this->allowedDepartmentIdsResolved) {
            return $this->allowedDepartmentIdsCache;
        }

        $selected = $this->targetDepartments();

        if ($selected->isEmpty()) {
            $ids = null;
        } else {
            $ids = [];
            foreach ($selected as $department) {
                $ids = array_merge($ids, $this->include_sub_departments
                    ? $department->descendantIds()
                    : [$department->id]);
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
        }

        $this->allowedDepartmentIdsResolved = true;

        return $this->allowedDepartmentIdsCache = $ids;
    }

    /** Nhân viên có thoả toàn bộ điều kiện của rule không. */
    public function matches(Employee $employee): bool
    {
        $allowedDepartmentIds = $this->allowedDepartmentIds();

        if ($allowedDepartmentIds !== null
            && ! in_array((int) $employee->department_id, $allowedDepartmentIds, true)) {
            return false;
        }

        if ($this->job_title_id && $employee->job_title_id !== $this->job_title_id) {
            return false;
        }

        if ($this->job_grade_id && ! $employee->hasJobGrade((int) $this->job_grade_id)) {
            return false;
        }

        if ($this->employment_status && $employee->employment_status !== $this->employment_status) {
            return false;
        }

        return true;
    }

    /** Bỏ tập phòng ban đã tính khi nạp lại từ DB (vd sau khi đổi phòng ban của điều kiện). */
    public function refresh()
    {
        $this->allowedDepartmentIdsResolved = false;
        $this->allowedDepartmentIdsCache = null;

        return parent::refresh();
    }
}
