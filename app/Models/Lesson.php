<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bài học trong khoá (spec 3.2.5). Mỗi bài chọn một dạng nội dung:
 * text (soạn trực tiếp), document/video (lấy từ thư viện), hoặc quiz.
 */
class Lesson extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_TEXT = 'text';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_VIDEO = 'video';
    public const TYPE_QUIZ = 'quiz';

    /** Mặc định phải xem hết 80% video mới tính hoàn thành, nếu bài không cấu hình riêng. */
    public const DEFAULT_MIN_WATCH_PERCENT = 80;

    protected $fillable = [
        'course_id', 'course_section_id', 'title', 'summary', 'content_type',
        'content_html', 'document_id', 'quiz_id', 'sort_order',
        'is_required', 'estimated_minutes', 'min_watch_percent',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'estimated_minutes' => 'integer',
            'min_watch_percent' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function progresses(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function isQuiz(): bool
    {
        return $this->content_type === self::TYPE_QUIZ;
    }

    public function isVideo(): bool
    {
        return $this->content_type === self::TYPE_VIDEO;
    }

    public function minWatchPercent(): int
    {
        return $this->min_watch_percent ?? self::DEFAULT_MIN_WATCH_PERCENT;
    }

    /** Bài học liền trước trong cùng khoá, dùng để xét điều kiện mở khoá tuần tự. */
    public function previousLesson(): ?self
    {
        return static::where('course_id', $this->course_id)
            ->where('sort_order', '<', $this->sort_order)
            ->orderByDesc('sort_order')
            ->first();
    }

    /**
     * Bài có mở cho nhân viên này không (spec 3.2.5: học tuần tự hoặc tự do).
     * Khoá học không bật sequential thì mọi bài đều mở.
     */
    public function isUnlockedFor(Enrollment $enrollment): bool
    {
        if (! $this->course->sequential) {
            return true;
        }

        $previous = $this->previousLesson();

        if (! $previous) {
            return true;
        }

        return $previous->progresses()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', LessonProgress::STATUS_COMPLETED)
            ->exists();
    }
}
