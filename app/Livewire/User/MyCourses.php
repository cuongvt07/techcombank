<?php

namespace App\Livewire\User;

use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\LessonProgress;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Danh mục khoá học của nhân viên (spec 4.1 + 4.2).
 * Chỉ hiển thị khoá đã được gán cho chính nhân viên đang đăng nhập.
 */
class MyCourses extends Component
{
    public string $filter = 'all';

    public function render(): View
    {
        $employee = auth()->user()->employee;

        return view('livewire.user.my-courses', [
            'employee' => $employee,
            'enrollments' => $this->enrollments(),
            'summary' => $this->summary(),
        ])->layout('layouts.user', ['title' => 'Khóa học của tôi']);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    private function enrollments()
    {
        $employee = auth()->user()->employee;

        if (! $employee) {
            return collect();
        }

        return Enrollment::query()
            ->with('course')
            ->where('employee_id', $employee->id)
            ->where('status', '!=', Enrollment::STATUS_CANCELLED)
            ->when($this->filter === 'mandatory', fn ($q) => $q->where('is_mandatory', true))
            ->when($this->filter === 'in_progress', fn ($q) => $q->unfinished())
            ->when($this->filter === 'completed', fn ($q) => $q->where('status', Enrollment::STATUS_COMPLETED))
            // Khoá quá hạn lên đầu, rồi đến khoá có hạn gần nhất
            ->orderByRaw("FIELD(status, 'overdue', 'in_progress', 'not_started', 'completed')")
            ->orderByRaw('due_date IS NULL, due_date')
            ->get();
    }

    /**
     * Số ngày học liên tiếp tính đến hôm nay.
     *
     * Đếm ngược từ hôm nay: gặp ngày không có hoạt động nào là dừng. Cho phép
     * chuỗi bắt đầu từ hôm qua — người chưa học hôm nay vẫn thấy chuỗi cũ,
     * đó chính là thứ thúc họ vào học tiếp.
     */
    private function learningStreak(Employee $employee): int
    {
        $days = LessonProgress::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('first_accessed_at')
            ->selectRaw('DATE(GREATEST(first_accessed_at, COALESCE(completed_at, first_accessed_at))) as day')
            ->distinct()
            ->orderByDesc('day')
            ->pluck('day')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        if ($days->isEmpty()) {
            return 0;
        }

        $streak = 0;
        $cursor = now();

        // Hôm nay chưa học thì bắt đầu đếm từ hôm qua
        if (! $days->has($cursor->toDateString())) {
            $cursor = $cursor->subDay();

            if (! $days->has($cursor->toDateString())) {
                return 0;
            }
        }

        while ($days->has($cursor->toDateString())) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        $employee = auth()->user()->employee;

        if (! $employee) {
            return [
                'total' => 0, 'completed' => 0, 'overdue' => 0, 'in_progress' => 0,
                'overall_percent' => 0, 'certificates' => 0, 'streak' => 0,
            ];
        }

        $base = Enrollment::where('employee_id', $employee->id)
            ->where('status', '!=', Enrollment::STATUS_CANCELLED);

        $total = (clone $base)->count();
        $completed = (clone $base)->where('status', Enrollment::STATUS_COMPLETED)->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'overdue' => (clone $base)->where('status', Enrollment::STATUS_OVERDUE)->count(),
            'in_progress' => (clone $base)->where('status', Enrollment::STATUS_IN_PROGRESS)->count(),

            // Tiến độ chung: trung bình phần trăm của tất cả khoá, không phải
            // tỉ lệ khoá đã xong. Người học xong 66% của cả hai khoá phải thấy
            // 66% chứ không phải 0% — đếm theo khoá hoàn thành sẽ xoá sạch công
            // sức đang dở và làm nản lòng đúng lúc cần động viên nhất.
            'overall_percent' => $total > 0
                ? (int) round((clone $base)->avg('progress_percent') ?? 0)
                : 0,

            'certificates' => $employee->certificates()->count(),

            // Chuỗi ngày học liên tiếp: động lực duy trì thói quen
            'streak' => $this->learningStreak($employee),
        ];
    }
}
