<?php

namespace App\Livewire\User;

use App\Models\Certificate;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Lịch sử học tập và nhắc việc (spec 4.2).
 * Nhắc việc đặt lên đầu vì đó là lý do chính nhân viên mở màn hình này.
 */
class LearningProgress extends Component
{
    public function render(): View
    {
        $employee = auth()->user()?->employee;

        return view('livewire.user.learning-progress', [
            'employee' => $employee,
            'summary' => $this->summary($employee),
            'dueSoon' => $this->dueSoon($employee),
            'enrollments' => $this->enrollments($employee),
            'quizAttempts' => $this->quizAttempts($employee),
            'certificates' => $this->certificates($employee),
        ])->layout('layouts.user', ['title' => 'Tiến độ học tập']);
    }

    /** @return array<string, int|float> */
    private function summary(?Employee $employee): array
    {
        if (! $employee) {
            return ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'overdue' => 0, 'avg' => 0];
        }

        $base = Enrollment::where('employee_id', $employee->id)
            ->whereNot('status', Enrollment::STATUS_CANCELLED);

        return [
            'total' => (clone $base)->count(),
            'completed' => (clone $base)->where('status', Enrollment::STATUS_COMPLETED)->count(),
            'in_progress' => (clone $base)->where('status', Enrollment::STATUS_IN_PROGRESS)->count(),
            'overdue' => (clone $base)->where('status', Enrollment::STATUS_OVERDUE)->count(),
            'avg' => round((float) (clone $base)->avg('progress_percent'), 0),
        ];
    }

    /** Khoá bắt buộc chưa xong, sắp đến hạn hoặc đã quá hạn (spec 4.2: nhắc việc). */
    private function dueSoon(?Employee $employee)
    {
        if (! $employee) {
            return collect();
        }

        return Enrollment::with('course')
            ->where('employee_id', $employee->id)
            ->mandatory()
            ->unfinished()
            ->whereNotNull('due_date')
            ->orderBy('due_date')
            ->limit(5)
            ->get();
    }

    private function enrollments(?Employee $employee)
    {
        if (! $employee) {
            return collect();
        }

        return Enrollment::with('course')
            ->where('employee_id', $employee->id)
            ->whereNot('status', Enrollment::STATUS_CANCELLED)
            ->orderByRaw("FIELD(status, 'overdue', 'in_progress', 'not_started', 'completed')")
            ->orderByDesc('last_accessed_at')
            ->get();
    }

    /** Kết quả các lần thi, mới nhất trước (spec 3.2.3: lưu lịch sử từng lần). */
    private function quizAttempts(?Employee $employee)
    {
        if (! $employee) {
            return collect();
        }

        return QuizAttempt::with('quiz')
            ->where('employee_id', $employee->id)
            ->whereNot('status', QuizAttempt::STATUS_IN_PROGRESS)
            ->orderByDesc('submitted_at')
            ->limit(20)
            ->get();
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
