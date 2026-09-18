<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một lượt làm bài của nhân viên (spec 3.2.3: lưu lịch sử từng lần thi). */
class QuizAttempt extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_GRADED = 'graded';

    protected $fillable = [
        'quiz_id', 'employee_id', 'lesson_id', 'attempt_no', 'status',
        'score', 'max_score', 'percentage', 'is_passed',
        'started_at', 'submitted_at', 'expires_at', 'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'percentage' => 'decimal:2',
            'is_passed' => 'boolean',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAttemptAnswer::class)->orderBy('sort_order');
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /** Hết giờ làm bài. Lượt quá hạn phải bị chấm ngay thay vì cho làm tiếp. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at);
    }

    public function remainingSeconds(): ?int
    {
        if (! $this->expires_at) {
            return null;
        }

        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }
}
