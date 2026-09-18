<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Điều kiện gán khoá học tự động (spec 3.2.4 + 3.3.1).
 * Cột NULL nghĩa là không ràng buộc chiều đó.
 */
class CourseAssignmentRule extends Model
{
    protected $fillable = [
        'course_id', 'name', 'department_id', 'job_title_id', 'job_grade_id',
        'employment_status', 'include_sub_departments', 'is_mandatory',
        'due_days', 'is_active',
    ];

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

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Nhân viên có thoả toàn bộ điều kiện của rule không. */
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

        if ($this->employment_status && $employee->employment_status !== $this->employment_status) {
            return false;
        }

        return true;
    }
}
