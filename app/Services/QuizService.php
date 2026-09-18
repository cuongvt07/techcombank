<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptAnswer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use RuntimeException;

/**
 * Tổ chức một lượt làm bài trắc nghiệm (spec 3.2.3): rút đề, lưu bài làm, chấm điểm.
 *
 * Đề được "đóng băng" ngay khi bắt đầu: toàn bộ câu hỏi của lượt thi được ghi vào
 * quiz_attempt_answers kèm snapshot nội dung. Nhờ đó sửa ngân hàng câu hỏi giữa chừng
 * không làm hỏng lượt đang thi, và lịch sử kết quả vẫn đọc được đúng đề đã ra.
 */
class QuizService
{
    public function __construct(private readonly ProgressService $progress)
    {
    }

    /**
     * Bắt đầu một lượt thi mới.
     *
     * @throws RuntimeException khi đã hết số lần thi lại cho phép
     */
    public function start(Quiz $quiz, Employee $employee, ?Lesson $lesson = null): QuizAttempt
    {
        if (! $quiz->canAttempt($employee)) {
            throw new RuntimeException('Bạn đã dùng hết số lần làm bài cho phép.');
        }

        return DB::transaction(function () use ($quiz, $employee, $lesson) {
            $questions = $quiz->drawQuestions();

            if ($questions->isEmpty()) {
                throw new RuntimeException('Bài kiểm tra chưa có câu hỏi.');
            }

            $attempt = QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'employee_id' => $employee->id,
                'lesson_id' => $lesson?->id,
                'attempt_no' => $quiz->attemptCountFor($employee) + 1,
                'status' => QuizAttempt::STATUS_IN_PROGRESS,
                'max_score' => $questions->sum(fn (Question $q) => (float) $q->score),
                'started_at' => now(),
                'expires_at' => $quiz->duration_minutes
                    ? now()->addMinutes($quiz->duration_minutes)
                    : null,
                'ip_address' => Request::ip(),
            ]);

            foreach ($questions as $index => $question) {
                QuizAttemptAnswer::create([
                    'quiz_attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'question_snapshot' => $question->content,
                    'sort_order' => $index,
                ]);
            }

            return $attempt;
        });
    }

    /** Lưu tạm câu trả lời trong lúc làm bài, chưa chấm điểm. */
    public function saveAnswer(QuizAttempt $attempt, int $questionId, array $selectedOptionIds): void
    {
        if (! $attempt->isInProgress()) {
            throw new RuntimeException('Lượt thi đã kết thúc.');
        }

        if ($attempt->hasExpired()) {
            $this->submit($attempt);

            throw new RuntimeException('Đã hết thời gian làm bài, bài thi được nộp tự động.');
        }

        $attempt->answers()
            ->where('question_id', $questionId)
            ->update(['selected_option_ids' => array_values(array_map('intval', $selectedOptionIds))]);
    }

    /**
     * Nộp bài và chấm điểm.
     * Chấm lại toàn bộ câu trong lượt, câu không trả lời tính 0 điểm.
     */
    public function submit(QuizAttempt $attempt): QuizAttempt
    {
        if (! $attempt->isInProgress()) {
            return $attempt;
        }

        return DB::transaction(function () use ($attempt) {
            $answers = $attempt->answers()->with('question.options')->get();
            $totalScore = 0.0;
            $maxScore = 0.0;

            foreach ($answers as $answer) {
                $question = $answer->question;

                if (! $question) {
                    // Câu hỏi bị xoá cứng sau khi lượt thi bắt đầu: bỏ khỏi mẫu số
                    continue;
                }

                $maxScore += (float) $question->score;
                $selected = $answer->selected_option_ids ?? [];
                $isCorrect = $selected !== [] && $question->isAnsweredCorrectly($selected);
                $score = $isCorrect ? (float) $question->score : 0.0;
                $totalScore += $score;

                $answer->update(['is_correct' => $isCorrect, 'score' => $score]);
            }

            $percentage = $maxScore > 0 ? round($totalScore / $maxScore * 100, 2) : 0.0;

            $attempt->update([
                'status' => QuizAttempt::STATUS_GRADED,
                'score' => $totalScore,
                'max_score' => $maxScore,
                'percentage' => $percentage,
                'is_passed' => $percentage >= $attempt->quiz->pass_score,
                'submitted_at' => now(),
            ]);

            $attempt->refresh();

            // Đạt điểm thì bài học chứa quiz được tính hoàn thành (spec 3.2.4)
            $this->progress->applyQuizResult($attempt);

            return $attempt;
        });
    }

    /**
     * Chấm các lượt thi đã quá giờ mà chưa nộp.
     * Chạy định kỳ qua scheduler, tránh để lượt thi treo mãi ở in_progress.
     */
    public function closeExpiredAttempts(): int
    {
        $count = 0;

        QuizAttempt::query()
            ->where('status', QuizAttempt::STATUS_IN_PROGRESS)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with('quiz')
            ->chunkById(100, function ($attempts) use (&$count) {
                foreach ($attempts as $attempt) {
                    $this->submit($attempt);
                    $count++;
                }
            });

        return $count;
    }
}
