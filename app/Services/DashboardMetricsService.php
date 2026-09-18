<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use App\Models\SecurityAlert;
use App\Models\SupportRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Số liệu cho dashboard quản trị (spec mục 5: báo cáo tiến độ toàn công ty).
 *
 * Mọi phép tính đều dùng aggregate ở SQL thay vì nạp collection rồi đếm ở PHP —
 * bảng enrollments lớn dần theo số nhân viên × số khoá, nạp hết sẽ chết bộ nhớ.
 */
class DashboardMetricsService
{
    /**
     * Chỉ số tổng quan kèm so sánh với kỳ trước, để biết đang tốt lên hay xấu đi.
     *
     * @return array<string, array{value: int|float, delta: ?float, trend: string}>
     */
    public function summary(int $days = 30): array
    {
        $from = now()->subDays($days);
        $prevFrom = now()->subDays($days * 2);

        $completed = Enrollment::where('status', Enrollment::STATUS_COMPLETED)
            ->where('completed_at', '>=', $from)
            ->count();

        $prevCompleted = Enrollment::where('status', Enrollment::STATUS_COMPLETED)
            ->whereBetween('completed_at', [$prevFrom, $from])
            ->count();

        $newAssignments = Enrollment::where('assigned_at', '>=', $from)->count();
        $prevAssignments = Enrollment::whereBetween('assigned_at', [$prevFrom, $from])->count();

        return [
            'active_employees' => $this->metric(Employee::active()->count()),
            'published_courses' => $this->metric(Course::published()->count()),
            'completed' => $this->metric($completed, $prevCompleted),
            'new_assignments' => $this->metric($newAssignments, $prevAssignments),
            'unfinished' => $this->metric(Enrollment::mandatory()->unfinished()->count()),
            'overdue' => $this->metric(Enrollment::overdue()->count()),
        ];
    }

    /**
     * Số lượt hoàn thành khoá theo từng ngày — dữ liệu cho biểu đồ xu hướng.
     *
     * @return Collection<int, array{label: string, value: int}>
     */
    public function completionTrend(int $days = 14): Collection
    {
        $rows = Enrollment::query()
            ->where('status', Enrollment::STATUS_COMPLETED)
            ->where('completed_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        // Điền đủ mọi ngày kể cả ngày không có dữ liệu, nếu không biểu đồ sẽ
        // bóp méo khoảng thời gian và đọc sai xu hướng.
        return collect(range($days - 1, 0))
            ->map(function (int $offset) use ($rows) {
                $date = now()->subDays($offset);
                $key = $date->toDateString();

                return [
                    'label' => $date->format('d/m'),
                    'value' => (int) ($rows[$key] ?? 0),
                ];
            });
    }

    /**
     * Tiến độ trung bình theo phòng ban, kèm số người để biết mẫu có đủ lớn không.
     *
     * @return Collection<int, object>
     */
    public function departmentProgress(int $limit = 8): Collection
    {
        return Enrollment::query()
            ->join('employees', 'employees.id', '=', 'enrollments.employee_id')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->whereNot('enrollments.status', Enrollment::STATUS_CANCELLED)
            ->groupBy('departments.id', 'departments.name')
            ->select('departments.name')
            ->selectRaw('ROUND(AVG(enrollments.progress_percent)) as avg_percent')
            ->selectRaw('COUNT(DISTINCT employees.id) as employee_count')
            ->selectRaw('SUM(CASE WHEN enrollments.status = "completed" THEN 1 ELSE 0 END) as completed_count')
            ->selectRaw('SUM(CASE WHEN enrollments.status = "overdue" THEN 1 ELSE 0 END) as overdue_count')
            ->orderByDesc('avg_percent')
            ->limit($limit)
            ->get();
    }

    /**
     * Phân bố trạng thái học tập — dữ liệu cho biểu đồ tròn.
     *
     * @return Collection<int, array{status: string, label: string, value: int, tone: string}>
     */
    public function statusBreakdown(): Collection
    {
        $counts = Enrollment::query()
            ->whereNot('status', Enrollment::STATUS_CANCELLED)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $map = [
            Enrollment::STATUS_COMPLETED => ['Hoàn thành', 'success'],
            Enrollment::STATUS_IN_PROGRESS => ['Đang học', 'warning'],
            Enrollment::STATUS_NOT_STARTED => ['Chưa bắt đầu', 'muted'],
            Enrollment::STATUS_OVERDUE => ['Quá hạn', 'danger'],
        ];

        return collect($map)->map(fn ($info, $status) => [
            'status' => $status,
            'label' => $info[0],
            'value' => (int) ($counts[$status] ?? 0),
            'tone' => $info[1],
        ])->values();
    }

    /**
     * Khoá học có tỉ lệ hoàn thành thấp nhất — chỗ cần can thiệp trước.
     * Chỉ xét khoá đã giao cho ít nhất 3 người, tránh nhiễu vì mẫu quá nhỏ.
     */
    public function strugglingCourses(int $limit = 5): Collection
    {
        return Enrollment::query()
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->whereNot('enrollments.status', Enrollment::STATUS_CANCELLED)
            ->groupBy('courses.id', 'courses.title')
            ->select('courses.id', 'courses.title')
            ->selectRaw('COUNT(*) as assigned_count')
            ->selectRaw('ROUND(AVG(enrollments.progress_percent)) as avg_percent')
            ->selectRaw('SUM(CASE WHEN enrollments.status = "overdue" THEN 1 ELSE 0 END) as overdue_count')
            ->havingRaw('COUNT(*) >= 3')
            ->orderBy('avg_percent')
            ->limit($limit)
            ->get();
    }

    /** Tỉ lệ đạt bài kiểm tra trong kỳ. */
    public function quizPassRate(int $days = 30): array
    {
        $attempts = QuizAttempt::query()
            ->whereNot('status', QuizAttempt::STATUS_IN_PROGRESS)
            ->where('submitted_at', '>=', now()->subDays($days))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_passed = 1 THEN 1 ELSE 0 END) as passed')
            ->first();

        $total = (int) ($attempts->total ?? 0);
        $passed = (int) ($attempts->passed ?? 0);

        return [
            'total' => $total,
            'passed' => $passed,
            'rate' => $total > 0 ? (int) round($passed / $total * 100) : 0,
        ];
    }

    /** Việc cần xử lý gấp, gom từ nhiều nguồn để admin không phải đi tìm. */
    public function actionItems(): array
    {
        return [
            'overdue_enrollments' => Enrollment::overdue()->count(),
            'expiring_contracts' => Contract::needsExpiryAlert()->count(),
            'open_alerts' => SecurityAlert::open()->count(),
            'open_tickets' => SupportRequest::open()->count(),
        ];
    }

    /**
     * @return array{value: int|float, delta: ?float, trend: string}
     */
    private function metric(int|float $value, int|float|null $previous = null): array
    {
        if ($previous === null) {
            return ['value' => $value, 'delta' => null, 'trend' => 'flat'];
        }

        // Kỳ trước bằng 0 thì % thay đổi vô nghĩa, chỉ báo có tăng hay không
        if ($previous == 0) {
            return [
                'value' => $value,
                'delta' => null,
                'trend' => $value > 0 ? 'up' : 'flat',
            ];
        }

        $delta = round(($value - $previous) / $previous * 100);

        return [
            'value' => $value,
            'delta' => $delta,
            'trend' => match (true) {
                $delta > 0 => 'up',
                $delta < 0 => 'down',
                default => 'flat',
            },
        ];
    }
}
