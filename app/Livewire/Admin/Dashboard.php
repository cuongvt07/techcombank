<?php

namespace App\Livewire\Admin;

use App\Models\Contract;
use App\Models\Enrollment;
use App\Services\DashboardMetricsService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tổng quan đào tạo toàn công ty (spec mục 5).
 *
 * Bố cục theo thứ tự người vận hành cần: việc cần xử lý gấp trước, rồi chỉ số
 * tổng quan, rồi xu hướng và phân tích. Ai mở dashboard cũng để trả lời câu
 * "hôm nay tôi phải làm gì" trước khi quan tâm số liệu tổng.
 */
class Dashboard extends Component
{
    /** Khoảng thời gian phân tích, ảnh hưởng tới chỉ số so sánh và biểu đồ. */
    #[Url]
    public int $period = 30;

    public function render(): View
    {
        $metrics = app(DashboardMetricsService::class);

        return view('livewire.admin.dashboard', [
            'summary' => $metrics->summary($this->period),
            'actions' => $metrics->actionItems(),
            'trend' => $metrics->completionTrend(min(30, $this->period)),
            'departments' => $metrics->departmentProgress(),
            'statusBreakdown' => $metrics->statusBreakdown(),
            'strugglingCourses' => $metrics->strugglingCourses(),
            'quizStats' => $metrics->quizPassRate($this->period),
            'trackedEmployees' => $this->trackedEmployees(),
            'expiringContracts' => $this->expiringContracts(),
        ])->layout('layouts.admin', ['title' => 'Tổng quan']);
    }

    public function setPeriod(int $days): void
    {
        if (in_array($days, [7, 30, 90], true)) {
            $this->period = $days;
        }
    }

    /** Nhân viên có khoá bắt buộc chưa xong, ưu tiên quá hạn trước. */
    private function trackedEmployees()
    {
        return Enrollment::query()
            ->with(['employee.department', 'course'])
            ->mandatory()
            ->unfinished()
            ->orderByRaw("FIELD(status, 'overdue', 'in_progress', 'not_started')")
            ->orderByRaw('due_date IS NULL, due_date')
            ->limit(8)
            ->get();
    }

    private function expiringContracts()
    {
        return Contract::query()
            ->with('employee')
            ->needsExpiryAlert()
            ->orderBy('effective_to')
            ->limit(5)
            ->get();
    }
}
