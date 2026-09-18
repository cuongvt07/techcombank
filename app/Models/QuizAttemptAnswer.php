<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Câu trả lời trong một lượt thi. Giữ question_snapshot để lịch sử kết quả
 * không đổi nghĩa khi ngân hàng câu hỏi bị sửa về sau (spec 3.2.3).
 */
class QuizAttemptAnswer extends Model
{
    protected $fillable = [
        'quiz_attempt_id', 'question_id', 'question_snapshot',
        'selected_option_ids', 'is_correct', 'score', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'is_correct' => 'boolean',
            'score' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
