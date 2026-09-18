<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Đầu mối hỗ trợ theo chủ đề/phòng ban (spec 4.5). */
class SupportContact extends Model
{
    protected $fillable = [
        'topic', 'department_id', 'contact_name', 'email', 'phone',
        'note', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Đầu mối áp dụng cho một nhân viên: đầu mối riêng của phòng ban
     * cộng với đầu mối chung toàn công ty (department_id = NULL).
     */
    public function scopeForEmployee($query, Employee $employee)
    {
        return $query->where(function ($q) use ($employee) {
            $q->whereNull('department_id')
                ->orWhere('department_id', $employee->department_id);
        });
    }
}
