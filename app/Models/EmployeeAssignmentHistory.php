<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail thay đổi vị trí/phòng ban (spec 3.1.2).
 * Bản ghi có effective_to = NULL là bản đang hiệu lực.
 */
class EmployeeAssignmentHistory extends Model
{
    protected $fillable = [
        'employee_id', 'department_id', 'job_title_id', 'job_grade_id',
        'employment_status', 'effective_from', 'effective_to',
        'change_reason', 'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('effective_to');
    }
}
