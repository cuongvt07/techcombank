<?php

namespace App\Livewire\User;

use App\Models\Certificate;
use App\Models\Employee;
use App\Models\QuizAttempt;
use Illuminate\View\View;
use Livewire\Component;

/** Kết quả kiểm tra và chứng nhận hoàn thành của nhân viên đang đăng nhập. */
class LearningProgress extends Component
{
    public ?int $viewingCertificateId = null;

    public function render(): View
    {
        $employee = auth()->user()?->employee;

        return view('livewire.user.learning-progress', [
            'quizAttempts' => $this->quizAttempts($employee),
            'certificates' => $this->certificates($employee),
            'viewingCertificate' => $this->viewingCertificate($employee),
        ])->layout('layouts.user', ['title' => 'Tiến độ học tập']);
    }

    public function showCertificate(int $certificateId): void
    {
        $employee = auth()->user()?->employee;

        if ($employee && Certificate::query()
            ->where('employee_id', $employee->id)
            ->whereKey($certificateId)
            ->exists()) {
            $this->viewingCertificateId = $certificateId;
        }
    }

    public function closeCertificate(): void
    {
        $this->viewingCertificateId = null;
    }

    private function quizAttempts(?Employee $employee)
    {
        if (! $employee) {
            return collect();
        }

        $attempts = QuizAttempt::with('quiz.course')
            ->where('employee_id', $employee->id)
            ->whereNot('status', QuizAttempt::STATUS_IN_PROGRESS)
            ->orderByDesc('submitted_at')
            ->get();

        return $attempts
            ->groupBy('quiz_id')
            ->flatMap(fn ($quizAttempts) => $quizAttempts->sort(function (QuizAttempt $a, QuizAttempt $b): int {
                $scoreOrder = (float) $b->percentage <=> (float) $a->percentage;

                return $scoreOrder !== 0
                    ? $scoreOrder
                    : (($b->submitted_at?->getTimestamp() ?? 0) <=> ($a->submitted_at?->getTimestamp() ?? 0));
            }))
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

    private function viewingCertificate(?Employee $employee): ?Certificate
    {
        if (! $employee || ! $this->viewingCertificateId) {
            return null;
        }

        return Certificate::with(['course', 'employee'])
            ->where('employee_id', $employee->id)
            ->find($this->viewingCertificateId);
    }
}
