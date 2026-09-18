<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Bài kiểm tra trắc nghiệm (spec 3.2.3). */
class Quiz extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'title', 'description', 'course_id', 'questions_per_attempt',
        'duration_minutes', 'pass_score', 'max_attempts', 'shuffle_questions',
        'shuffle_options', 'show_result_immediately', 'show_correct_answers',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'questions_per_attempt' => 'integer',
            'duration_minutes' => 'integer',
            'pass_score' => 'integer',
            'max_attempts' => 'integer',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'show_result_immediately' => 'boolean',
            'show_correct_answers' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('sort_order');
    }

    public function activeQuestions(): HasMany
    {
        return $this->questions()->where('is_active', true);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Rút bộ câu hỏi cho một lượt thi, áp dụng cấu hình trộn và số câu mỗi lượt.
     * Trộn ở tầng PHP thay vì inRandomOrder() để kết quả ổn định khi đã eager load options.
     */
    public function drawQuestions(): Collection
    {
        $questions = $this->activeQuestions()->with('options')->get();

        if ($this->shuffle_questions) {
            $questions = $questions->shuffle();
        }

        if ($this->questions_per_attempt && $this->questions_per_attempt < $questions->count()) {
            $questions = $questions->take($this->questions_per_attempt);
        }

        return $questions->values();
    }

    /** Nhân viên còn lượt thi lại không (spec 3.2.3). */
    public function canAttempt(Employee $employee): bool
    {
        if ($this->max_attempts === null) {
            return true;
        }

        return $this->attempts()
            ->where('employee_id', $employee->id)
            ->count() < $this->max_attempts;
    }

    public function attemptCountFor(Employee $employee): int
    {
        return $this->attempts()->where('employee_id', $employee->id)->count();
    }
}
