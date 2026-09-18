<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Câu hỏi trắc nghiệm (spec 3.2.3): chọn 1, chọn nhiều, đúng/sai. */
class Question extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_SINGLE = 'single_choice';
    public const TYPE_MULTIPLE = 'multiple_choice';
    public const TYPE_TRUE_FALSE = 'true_false';

    protected $fillable = [
        'quiz_id', 'content', 'type', 'explanation', 'score', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order');
    }

    public function correctOptions(): HasMany
    {
        return $this->options()->where('is_correct', true);
    }

    public function correctOptionIds(): array
    {
        return $this->options->where('is_correct', true)->pluck('id')->sort()->values()->all();
    }

    public function isMultiple(): bool
    {
        return $this->type === self::TYPE_MULTIPLE;
    }

    /**
     * Chấm một câu: chọn nhiều đáp án phải khớp trọn bộ, không tính điểm từng phần.
     * Quy ước này cần thống nhất với nghiệp vụ đào tạo trước khi phát hành.
     */
    public function isAnsweredCorrectly(array $selectedOptionIds): bool
    {
        $selected = collect($selectedOptionIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $selected === $this->correctOptionIds();
    }
}
