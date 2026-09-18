<?php

namespace App\Livewire\User;

use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\QuizAttempt;
use App\Services\QuizService;
use Illuminate\View\View;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Làm bài trắc nghiệm (spec 3.2.3 phía người học).
 *
 * Đề được đóng băng khi bắt đầu, mỗi câu trả lời lưu ngay về server — mất mạng
 * hay đóng nhầm tab thì bài làm vẫn còn, quay lại làm tiếp được.
 */
class QuizPlayer extends Component
{
    public Course $course;
    public Lesson $lesson;

    public ?int $attemptId = null;

    /** @var array<int, array<int>> [question_id => selected_option_ids] */
    public array $answers = [];

    public int $currentIndex = 0;
    public bool $showConfirmSubmit = false;

    public function mount(Course $course, Lesson $lesson): void
    {
        $this->course = $course;
        $this->lesson = $lesson;

        if (! $this->enrollment() || $lesson->course_id !== $course->id || ! $lesson->quiz) {
            throw new NotFoundHttpException();
        }

        // Nối lại lượt đang làm dở nếu có, thay vì bắt làm lại từ đầu
        $inProgress = QuizAttempt::where('quiz_id', $lesson->quiz_id)
            ->where('employee_id', $this->employee()->id)
            ->where('status', QuizAttempt::STATUS_IN_PROGRESS)
            ->latest('id')
            ->first();

        if ($inProgress) {
            $this->attemptId = $inProgress->id;
            $this->loadSavedAnswers($inProgress);
        }
    }

    public function render(): View
    {
        $attempt = $this->attempt();

        return view('livewire.user.quiz-player', [
            'quiz' => $this->lesson->quiz,
            'attempt' => $attempt,
            'questions' => $this->questions($attempt),
            'history' => $this->history(),
            'canAttempt' => $this->lesson->quiz->canAttempt($this->employee()),
            'attemptsUsed' => $this->lesson->quiz->attemptCountFor($this->employee()),
        ])->layout('layouts.user', ['title' => $this->lesson->quiz->title]);
    }

    public function startAttempt(QuizService $service): void
    {
        try {
            $attempt = $service->start($this->lesson->quiz, $this->employee(), $this->lesson);
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->attemptId = $attempt->id;
        $this->answers = [];
        $this->currentIndex = 0;
    }

    /** Lưu câu trả lời ngay khi chọn, không đợi nộp bài. */
    public function selectOption(int $questionId, int $optionId, bool $multiple, QuizService $service): void
    {
        $attempt = $this->attempt();

        if (! $attempt?->isInProgress()) {
            return;
        }

        $current = $this->answers[$questionId] ?? [];

        if ($multiple) {
            $current = in_array($optionId, $current, true)
                ? array_values(array_diff($current, [$optionId]))
                : [...$current, $optionId];
        } else {
            $current = [$optionId];
        }

        $this->answers[$questionId] = $current;

        try {
            $service->saveAnswer($attempt, $questionId, $current);
        } catch (RuntimeException $e) {
            // Hết giờ giữa chừng: service đã tự nộp bài
            session()->flash('error', $e->getMessage());
        }
    }

    public function goToQuestion(int $index): void
    {
        $this->currentIndex = max(0, $index);
    }

    public function nextQuestion(): void
    {
        $this->currentIndex++;
    }

    public function previousQuestion(): void
    {
        $this->currentIndex = max(0, $this->currentIndex - 1);
    }

    public function confirmSubmit(): void
    {
        $this->showConfirmSubmit = true;
    }

    public function submit(QuizService $service): void
    {
        $attempt = $this->attempt();

        if (! $attempt?->isInProgress()) {
            return;
        }

        $service->submit($attempt);

        $this->showConfirmSubmit = false;
        session()->flash('status', 'Đã nộp bài.');
    }

    /** Trình duyệt báo hết giờ — server vẫn tự kiểm tra lại mốc expires_at. */
    public function timeUp(QuizService $service): void
    {
        $attempt = $this->attempt();

        if ($attempt?->isInProgress() && $attempt->hasExpired()) {
            $service->submit($attempt);
            session()->flash('error', 'Đã hết thời gian làm bài. Bài thi được nộp tự động.');
        }
    }

    public function retry(): void
    {
        $this->reset(['attemptId', 'answers', 'currentIndex', 'showConfirmSubmit']);
    }

    // ---- Trợ giúp -----------------------------------------------------------

    private function employee(): ?Employee
    {
        return auth()->user()?->employee;
    }

    private function enrollment(): ?Enrollment
    {
        $employee = $this->employee();

        if (! $employee) {
            return null;
        }

        return Enrollment::where('course_id', $this->course->id)
            ->where('employee_id', $employee->id)
            ->whereNot('status', Enrollment::STATUS_CANCELLED)
            ->first();
    }

    private function attempt(): ?QuizAttempt
    {
        if (! $this->attemptId) {
            return null;
        }

        $attempt = QuizAttempt::with('quiz')->find($this->attemptId);

        // Không cho xem lượt thi của người khác qua id trên URL
        return $attempt?->employee_id === $this->employee()?->id ? $attempt : null;
    }

    /**
     * Câu hỏi của lượt thi, lấy từ quiz_attempt_answers để giữ đúng thứ tự đã trộn
     * và đúng bộ câu đã rút — không rút lại từ ngân hàng.
     */
    private function questions(?QuizAttempt $attempt)
    {
        if (! $attempt) {
            return collect();
        }

        return $attempt->answers()
            ->with('question.options')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($answer) => $answer->question !== null)
            ->values();
    }

    private function history()
    {
        return QuizAttempt::where('quiz_id', $this->lesson->quiz_id)
            ->where('employee_id', $this->employee()->id)
            ->whereNot('status', QuizAttempt::STATUS_IN_PROGRESS)
            ->orderByDesc('attempt_no')
            ->get();
    }

    private function loadSavedAnswers(QuizAttempt $attempt): void
    {
        $this->answers = $attempt->answers()
            ->get()
            ->mapWithKeys(fn ($a) => [$a->question_id => $a->selected_option_ids ?? []])
            ->all();
    }
}
