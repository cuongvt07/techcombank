<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tiến độ từng bài học — nguồn tính % tiến độ của enrollment (spec 3.2.4). */
class LessonProgress extends Model
{
    protected $table = 'lesson_progresses';

    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'enrollment_id', 'lesson_id', 'employee_id', 'status',
        'last_position_second', 'watch_percent', 'time_spent_seconds',
        'first_accessed_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_position_second' => 'integer',
            'watch_percent' => 'decimal:2',
            'time_spent_seconds' => 'integer',
            'first_accessed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
