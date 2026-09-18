<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Chứng nhận hoàn thành khoá học (spec 4.2). */
class Certificate extends Model
{
    protected $fillable = [
        'certificate_no', 'enrollment_id', 'employee_id', 'course_id',
        'score', 'issued_at', 'valid_until', 'verification_code',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'issued_at' => 'datetime',
            'valid_until' => 'date',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isValid(): bool
    {
        return $this->valid_until === null || $this->valid_until->isFuture();
    }
}
