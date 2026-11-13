<?php

namespace App\Livewire\User;

use App\Models\Certificate;
use App\Models\Employee;
use App\Models\QuizAttempt;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Kết quả kiểm tra và chứng nhận hoàn thành của nhân viên đang đăng nhập.
 * Chứng nhận mở ở trang riêng (route learn.certificate) để in / lưu PDF.
 */
class LearningProgress extends Component
{
    public function render(): View
    {
        $employee = auth()->user()?->employee;

        return view('livewire.user.learning-progress', [
            'quizResults' => $this->bestQuizResults($employee),
            'certificates' => $this->certificates($employee),
        ])->layout('layouts.user', ['title' => 'Tiến độ học tập']);
    }

    /**
     * Mỗi bài kiểm tra một dòng: lượt có điểm cao nhất (bằng điểm thì lấy lượt
     * nộp sau cùng), kèm tổng số lượt đã nộp. Bài vừa làm gần đây nằm trên.
     *
     * @return Collection<int, array{attempt: QuizAttempt, attempts_count: int, last_submitted_at: mixed}>
     */
    private function bestQuizResults(?Employee $employee): Collection
    {
        if (! $employee) {
            return collect();
        }

        return QuizAttempt::with('quiz.course')
            ->where('employee_id', $employee->id)
            ->whereNot('status', QuizAttempt::STATUS_IN_PROGRESS)
            ->get()
            ->groupBy('quiz_id')
            ->map(function (Collection $attempts) {
                $best = $attempts->sort(function (QuizAttempt $a, QuizAttempt $b): int {
                    $scoreOrder = (float) $b->percentage <=> (float) $a->percentage;

                    return $scoreOrder !== 0
                        ? $scoreOrder
                        : (($b->submitted_at?->getTimestamp() ?? 0) <=> ($a->submitted_at?->getTimestamp() ?? 0));
                })->first();

                return [
                    'attempt' => $best,
                    'attempts_count' => $attempts->count(),
                    'last_submitted_at' => $attempts->max(fn (QuizAttempt $a) => $a->submitted_at?->getTimestamp() ?? 0),
                ];
            })
            ->sortByDesc('last_submitted_at')
            ->values();
    }

    private function certificates(?Employee $employee)
    {
        if (! $employee) {
            return collect();
        }

        return Certificate::with('course')
            ->where('employee_id', $employee->id)
            ->orderByDesc('issued_at')
            ->get();
    }
}
