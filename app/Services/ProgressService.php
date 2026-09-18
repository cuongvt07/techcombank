<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\QuizAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Theo dõi tiến độ học tập (spec 3.2.4 + 4.2).
 *
 * % tiến độ = số bài BẮT BUỘC đã hoàn thành / tổng số bài bắt buộc.
 * Bài tự chọn cố tình không tính vào mẫu số, để nhân viên hoàn thành đủ
 * phần bắt buộc là đạt 100%, không bị kẹt ở mức dở dang.
 */
class ProgressService
{
    /** Đánh dấu nhân viên bắt đầu/đang xem một bài học. */
    public function markLessonStarted(Enrollment $enrollment, Lesson $lesson): LessonProgress
    {
        $progress = LessonProgress::firstOrCreate(
            ['enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id],
            [
                'employee_id' => $enrollment->employee_id,
                'status' => LessonProgress::STATUS_IN_PROGRESS,
                'first_accessed_at' => now(),
            ]
        );

        if ($progress->status === LessonProgress::STATUS_NOT_STARTED) {
            $progress->update([
                'status' => LessonProgress::STATUS_IN_PROGRESS,
                'first_accessed_at' => $progress->first_accessed_at ?? now(),
            ]);
        }

        $this->touchEnrollmentStart($enrollment);

        return $progress;
    }

    /**
     * Cộng thêm thời gian người học ở lại một bài.
     *
     * Với video nhúng không đo được % xem, đây là bằng chứng duy nhất cho thấy
     * người học có thực sự dành thời gian cho bài hay chỉ bấm qua.
     */
    public function addTimeSpent(Enrollment $enrollment, Lesson $lesson, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        // Chặn giá trị vô lý: tab để mở qua đêm không tính là học 8 tiếng
        $seconds = min($seconds, 30 * 60);

        $progress = $this->markLessonStarted($enrollment, $lesson);

        $progress->increment('time_spent_seconds', $seconds);

        $enrollment->update(['last_accessed_at' => now()]);
    }

    /**
     * Cập nhật vị trí xem video. Bài video chỉ tự hoàn thành khi đạt
     * ngưỡng % xem tối thiểu của bài (spec 3.2.2).
     */
    public function updateVideoProgress(
        Enrollment $enrollment,
        Lesson $lesson,
        int $positionSecond,
        float $watchPercent,
        int $addedSeconds = 0,
    ): LessonProgress {
        $progress = $this->markLessonStarted($enrollment, $lesson);

        $progress->update([
            'last_position_second' => $positionSecond,
            // Chỉ tăng, không lùi: tua ngược xem lại không được làm giảm tiến độ đã đạt
            'watch_percent' => max((float) $progress->watch_percent, $watchPercent),
            'time_spent_seconds' => $progress->time_spent_seconds + max(0, $addedSeconds),
        ]);

        if ($progress->watch_percent >= $lesson->minWatchPercent()) {
            $this->completeLesson($enrollment, $lesson);
        }

        return $progress->refresh();
    }

    /** Đánh dấu hoàn thành một bài học và tính lại tiến độ khoá. */
    public function completeLesson(Enrollment $enrollment, Lesson $lesson): LessonProgress
    {
        $progress = $this->markLessonStarted($enrollment, $lesson);

        if (! $progress->isCompleted()) {
            $progress->update([
                'status' => LessonProgress::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        }

        $this->recalculate($enrollment);

        return $progress->refresh();
    }

    /** Ghi nhận kết quả một lượt thi vào tiến độ bài học chứa quiz đó. */
    public function applyQuizResult(QuizAttempt $attempt): void
    {
        if (! $attempt->lesson_id || ! $attempt->is_passed) {
            return;
        }

        $enrollment = Enrollment::where('employee_id', $attempt->employee_id)
            ->where('course_id', $attempt->lesson->course_id)
            ->first();

        if ($enrollment) {
            $this->completeLesson($enrollment, $attempt->lesson);
        }
    }

    /**
     * Tính lại % tiến độ và trạng thái của một enrollment.
     * Chạy trong transaction vì cập nhật enrollment kéo theo phát hành chứng nhận.
     */
    public function recalculate(Enrollment $enrollment): Enrollment
    {
        return DB::transaction(function () use ($enrollment) {
            $requiredLessonIds = $enrollment->course
                ->lessons()
                ->where('is_required', true)
                ->pluck('id');

            $total = $requiredLessonIds->count();

            $completed = $total === 0 ? 0 : LessonProgress::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereIn('lesson_id', $requiredLessonIds)
                ->where('status', LessonProgress::STATUS_COMPLETED)
                ->count();

            $percent = $total > 0 ? round($completed / $total * 100, 2) : 0.0;
            $isCompleted = $total > 0 && $completed >= $total;

            $enrollment->fill([
                'total_lessons' => $total,
                'completed_lessons' => $completed,
                'progress_percent' => $percent,
                'last_accessed_at' => now(),
                'status' => $this->resolveStatus($enrollment, $percent, $isCompleted),
            ]);

            if ($isCompleted && ! $enrollment->completed_at) {
                $enrollment->completed_at = now();
                $enrollment->final_score = $this->averageQuizScore($enrollment);
            }

            $enrollment->save();

            if ($isCompleted && $enrollment->course->issue_certificate) {
                $this->issueCertificate($enrollment);
            }

            return $enrollment;
        });
    }

    /** Phát hành chứng nhận hoàn thành, mỗi enrollment chỉ một lần (spec 4.2). */
    public function issueCertificate(Enrollment $enrollment): ?Certificate
    {
        if ($enrollment->certificate()->exists()) {
            return $enrollment->certificate;
        }

        return Certificate::create([
            'certificate_no' => $this->generateCertificateNo($enrollment),
            'enrollment_id' => $enrollment->id,
            'employee_id' => $enrollment->employee_id,
            'course_id' => $enrollment->course_id,
            'score' => $enrollment->final_score,
            'issued_at' => now(),
            'verification_code' => Str::lower(Str::random(24)),
        ]);
    }

    /**
     * Trạng thái enrollment. Quá hạn chỉ áp cho khoá chưa xong —
     * hoàn thành muộn vẫn tính là completed, không đánh dấu overdue.
     */
    private function resolveStatus(Enrollment $enrollment, float $percent, bool $isCompleted): string
    {
        if ($isCompleted) {
            return Enrollment::STATUS_COMPLETED;
        }

        if ($enrollment->due_date && $enrollment->due_date->isPast()) {
            return Enrollment::STATUS_OVERDUE;
        }

        return $percent > 0 ? Enrollment::STATUS_IN_PROGRESS : Enrollment::STATUS_NOT_STARTED;
    }

    private function touchEnrollmentStart(Enrollment $enrollment): void
    {
        if ($enrollment->started_at) {
            return;
        }

        $enrollment->update([
            'started_at' => now(),
            'status' => $enrollment->status === Enrollment::STATUS_NOT_STARTED
                ? Enrollment::STATUS_IN_PROGRESS
                : $enrollment->status,
        ]);
    }

    /** Điểm tổng kết = trung bình % của lượt thi đạt điểm cao nhất mỗi bài kiểm tra. */
    private function averageQuizScore(Enrollment $enrollment): ?float
    {
        $quizLessonIds = $enrollment->course
            ->lessons()
            ->where('content_type', Lesson::TYPE_QUIZ)
            ->pluck('id');

        if ($quizLessonIds->isEmpty()) {
            return null;
        }

        $best = QuizAttempt::query()
            ->where('employee_id', $enrollment->employee_id)
            ->whereIn('lesson_id', $quizLessonIds)
            ->whereNotNull('percentage')
            ->selectRaw('lesson_id, MAX(percentage) as best_percentage')
            ->groupBy('lesson_id')
            ->pluck('best_percentage');

        return $best->isEmpty() ? null : round((float) $best->avg(), 2);
    }

    private function generateCertificateNo(Enrollment $enrollment): string
    {
        return sprintf(
            'CERT-%s-%s-%s',
            now()->format('Y'),
            str_pad((string) $enrollment->course_id, 4, '0', STR_PAD_LEFT),
            str_pad((string) $enrollment->employee_id, 6, '0', STR_PAD_LEFT),
        );
    }
}
