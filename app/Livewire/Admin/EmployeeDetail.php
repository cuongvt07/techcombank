<?php

namespace App\Livewire\Admin;

use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Services\CourseAssignmentService;
use App\Services\EmployeeTimelineService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Trang chi tiết một nhân viên (kiểu hồ sơ bản ghi của CRM).
 *
 * Gom mọi thứ liên quan đến một người vào một chỗ: hồ sơ, khoá học được giao,
 * kết quả kiểm tra, hợp đồng, dòng thời gian hoạt động — thay vì bắt người dùng
 * mở năm màn hình rồi tự ghép lại.
 */
class EmployeeDetail extends Component
{
    public Employee $employee;

    #[Url]
    public string $tab = 'overview';

    public bool $showAssignModal = false;
    public ?int $assignCourseId = null;
    public ?int $assignDueDays = null;
    public bool $assignMandatory = true;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee->load([
            'department', 'jobTitle', 'jobGrade', 'manager', 'user',
        ]);
    }

    public function render(): View
    {
        return view('livewire.admin.employee-detail', [
            'stats' => $this->stats(),
            'enrollments' => $this->enrollments(),
            'quizAttempts' => $this->quizAttempts(),
            'contracts' => $this->contracts(),
            'certificates' => $this->certificates(),
            'timeline' => app(EmployeeTimelineService::class)->build($this->employee),
            'lessonProgress' => $this->lessonProgress(),
            'assignableCourses' => $this->assignableCourses(),
            'recentLogins' => $this->employee->user?->loginHistories()->limit(8)->get() ?? collect(),
        ])->layout('layouts.admin', ['title' => $this->employee->full_name]);
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'courses', 'lessons', 'quizzes', 'contracts', 'activity'], true)) {
            $this->tab = $tab;
        }
    }

    public function openAssign(): void
    {
        $this->reset(['assignCourseId', 'assignDueDays']);
        $this->assignMandatory = true;
        $this->showAssignModal = true;
    }

    public function assignCourse(CourseAssignmentService $service): void
    {
        $this->validate([
            'assignCourseId' => ['required', 'exists:courses,id'],
            'assignDueDays' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [
            'assignCourseId.required' => 'Hãy chọn khóa học.',
        ]);

        $service->assignManually(
            $this->employee,
            Course::findOrFail($this->assignCourseId),
            mandatory: $this->assignMandatory,
            dueDays: $this->assignDueDays,
            assignedBy: auth()->id(),
        );

        session()->flash('status', 'Đã giao khóa học cho nhân viên.');

        $this->showAssignModal = false;
        $this->reset(['assignCourseId', 'assignDueDays']);
    }

    public function unassign(int $enrollmentId, CourseAssignmentService $service): void
    {
        $enrollment = Enrollment::where('employee_id', $this->employee->id)->findOrFail($enrollmentId);
        $service->unassign($enrollment);

        session()->flash('status', 'Đã gỡ khóa học. Lịch sử học tập vẫn được giữ lại.');
    }

    /** @return array<string, int|float> */
    private function stats(): array
    {
        $base = Enrollment::where('employee_id', $this->employee->id)
            ->whereNot('status', Enrollment::STATUS_CANCELLED);

        return [
            'assigned' => (clone $base)->count(),
            'completed' => (clone $base)->where('status', Enrollment::STATUS_COMPLETED)->count(),
            'unfinished' => (clone $base)->unfinished()->count(),
            'overdue' => (clone $base)->where('status', Enrollment::STATUS_OVERDUE)->count(),
            'avg_progress' => round((float) (clone $base)->avg('progress_percent'), 0),
            'certificates' => $this->employee->certificates()->count(),
        ];
    }

    private function enrollments()
    {
        return Enrollment::with('course')
            ->where('employee_id', $this->employee->id)
            ->orderByRaw("FIELD(status, 'overdue', 'in_progress', 'not_started', 'completed', 'cancelled')")
            ->orderByDesc('assigned_at')
            ->get();
    }

    /** Chi tiết từng bài học: đã vào chưa, ở lại bao lâu, xong chưa. */
    private function lessonProgress()
    {
        return \App\Models\LessonProgress::query()
            ->with(['lesson.course'])
            ->where('employee_id', $this->employee->id)
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();
    }

    private function quizAttempts()
    {
        return $this->employee->quizAttempts()
            ->with('quiz')
            ->whereNot('status', 'in_progress')
            ->orderByDesc('submitted_at')
            ->get();
    }

    private function contracts()
    {
        return $this->employee->contracts()
            ->with('contractType')
            ->orderByDesc('effective_from')
            ->get();
    }

    private function certificates()
    {
        return $this->employee->certificates()->with('course')->orderByDesc('issued_at')->get();
    }

    /** Khoá đã xuất bản mà nhân viên chưa được giao. */
    private function assignableCourses()
    {
        $assigned = Enrollment::where('employee_id', $this->employee->id)
            ->whereNot('status', Enrollment::STATUS_CANCELLED)
            ->pluck('course_id');

        return Course::published()
            ->whereNotIn('id', $assigned)
            ->orderBy('title')
            ->get();
    }
}
