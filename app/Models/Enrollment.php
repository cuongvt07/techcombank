<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Một nhân viên được gán một khoá học, kèm % tiến độ (spec 3.2.4).
 * Bản ghi này là nơi tổng hợp tiến độ từ lesson_progresses.
 */
class Enrollment extends Model
{
    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'course_id', 'employee_id', 'course_assignment_rule_id', 'is_mandatory',
        'status', 'progress_percent', 'completed_lessons', 'total_lessons',
        'final_score', 'assigned_at', 'started_at', 'completed_at',
        'due_date', 'last_accessed_at', 'assigned_by',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'progress_percent' => 'decimal:2',
            'completed_lessons' => 'integer',
            'total_lessons' => 'integer',
            'final_score' => 'decimal:2',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'due_date' => 'date',
            'last_accessed_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignmentRule(): BelongsTo
    {
        return $this->belongsTo(CourseAssignmentRule::class, 'course_assignment_rule_id');
    }

    public function lessonProgresses(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeMandatory(Builder $query): Builder
    {
        return $query->where('is_mandatory', true);
    }

    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_NOT_STARTED, self::STATUS_IN_PROGRESS, self::STATUS_OVERDUE]);
    }

    /** Quá hạn nhưng chưa hoàn thành — dùng cho nhắc việc (spec 4.2). */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! $this->isCompleted();
    }

    public function daysUntilDue(): ?int
    {
        if (! $this->due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date->startOfDay(), false);
    }
}
