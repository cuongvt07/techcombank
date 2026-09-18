<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * "Bộ tài liệu nội bộ ban hành" (spec 3.2.4) — tương đương một khoá học/lộ trình.
 */
class Course extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'code', 'title', 'slug', 'description', 'cover_image',
        'owner_department_id', 'sequential', 'is_onboarding', 'status',
        'published_at', 'published_by', 'pass_score', 'duration_days',
        'issue_certificate', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sequential' => 'boolean',
            'is_onboarding' => 'boolean',
            'issue_certificate' => 'boolean',
            'published_at' => 'datetime',
            'pass_score' => 'integer',
            'duration_days' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'title', 'status', 'published_at', 'sequential', 'is_onboarding'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('course');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('sort_order');
    }

    /**
     * Bài học của khoá, KHÔNG sắp xếp sẵn.
     *
     * Cố ý không gắn orderBy vào quan hệ: orderBy ở đây sẽ luôn là khoá sắp xếp
     * chính, khiến mọi truy vấn phái sinh (vd lấy bài có sort_order lớn nhất)
     * không đảo được thứ tự. Dùng lessonsInOrder() khi cần đúng trình tự học.
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /** Bài học theo đúng trình tự học (spec 3.2.5). */
    public function lessonsInOrder(): HasMany
    {
        return $this->lessons()->orderBy('sort_order')->orderBy('id');
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function assignmentRules(): HasMany
    {
        return $this->hasMany(CourseAssignmentRule::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function ownerDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'owner_department_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeOnboarding(Builder $query): Builder
    {
        return $query->where('is_onboarding', true);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Số bài học bắt buộc — mẫu số khi tính % tiến độ (spec 3.2.4).
     * Bài tự chọn không tính vào tiến độ để nhân viên không bị "kẹt" ở 90%.
     */
    public function requiredLessonCount(): int
    {
        return $this->lessons()->where('is_required', true)->count();
    }
}
