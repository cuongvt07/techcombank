<?php

namespace App\Livewire\Admin\Reports;

use App\Livewire\Concerns\HasReportPeriod;
use App\Models\SecurityAlert;
use App\Services\Reports\SecurityReportService;
use App\Support\Reports\ReportExport;
use App\Support\Reports\ReportPeriod;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Báo cáo bảo mật và đăng nhập (SCR_008).
 *
 * Màn thống kê, không có thao tác ghi: ghi nhận / đóng cảnh báo vẫn làm ở
 * Trung tâm bảo mật (admin.security) để mỗi thay đổi trạng thái chỉ có một
 * nơi phát sinh.
 */
class SecurityReport extends Component
{
    use HasReportPeriod;
    use WithPagination;

    /** Nhật ký đăng nhập rất dày — kỳ dài hơn một quý làm truy vấn gom giờ quá nặng. */
    public const REPORT_MAX_DAYS = 92;

    public const DEFAULT_PRESET = '7d';

    #[Url(as: 'loai')]
    public ?string $type = null;

    #[Url(as: 'muc-do')]
    public ?string $severity = null;

    #[Url(as: 'trang-thai')]
    public ?string $status = null;

    #[Url(as: 'bang')]
    public string $tab = 'alerts';

    /** Ô bản đồ nhiệt đang lọc tab Lịch sử đăng nhập: thứ 1..7 (T2..CN) và giờ 0..23, giờ VN. */
    #[Url(as: 'thu')]
    public ?int $loginWeekday = null;

    #[Url(as: 'gio')]
    public ?int $loginHour = null;

    #[Url(as: 'sap-xep')]
    public string $sortDir = 'desc';

    public function mount(): void
    {
        // Kỳ mặc định 7 ngày (trait để 30 ngày); link có sẵn ?ky= thì giữ nguyên
        if (! request()->query('ky')) {
            $this->preset = self::DEFAULT_PRESET;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'severity', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'logins' ? 'logins' : 'alerts';
        $this->resetPage();
    }

    public function sortByTime(): void
    {
        $this->sortDir = $this->sortDir === 'desc' ? 'asc' : 'desc';
        $this->resetPage();
    }

    /** Bấm một ô bản đồ nhiệt: lọc lịch sử đăng nhập theo thứ + giờ đó. */
    public function selectHeatCell(int $row, int $col): void
    {
        $this->loginWeekday = max(1, min(7, $row + 1));
        $this->loginHour = max(0, min(23, $col));
        $this->tab = 'logins';
        $this->resetPage();
    }

    public function clearHeatCell(): void
    {
        $this->loginWeekday = null;
        $this->loginHour = null;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['type', 'severity', 'status', 'loginWeekday', 'loginHour', 'sortDir', 'from', 'to']);
        $this->preset = self::DEFAULT_PRESET;
        $this->resetPage();
    }

    /** Xuất đúng tab đang xem, theo bộ lọc hiện tại. */
    public function export(string $format = 'xlsx')
    {
        $service = app(SecurityReportService::class);
        $period = $this->period();
        $canSeeCodes = $this->canSeeEmployeeCodes();

        if ($this->tab === 'logins') {
            $rows = $this->loginRowsQuery($service, $period)->with('user.employee')->get()
                ->map(fn ($l) => [
                    ReportPeriod::local($l->logged_in_at)->format('d/m/Y H:i'),
                    $l->user?->name ?? '—',
                    $this->employeeCode($l->user?->employee?->employee_code, $canSeeCodes),
                    $l->ip_address ?? '—',
                    $l->device_label ?? $l->user_agent ?? '—',
                    SecurityReportService::RESULT_LABELS[$l->result] ?? $l->result,
                    $l->failure_reason ?? '',
                ])->all();

            return ReportExport::download(
                'lich-su-dang-nhap',
                ['Thời điểm', 'Tài khoản', 'Mã NV', 'IP', 'Thiết bị', 'Kết quả', 'Lý do thất bại'],
                $rows, $format, 'Lịch sử đăng nhập',
            );
        }

        $rows = $this->alertRowsQuery($service, $period)->with(['user.employee', 'handledBy'])->get()
            ->map(fn (SecurityAlert $a) => [
                SecurityReportService::alertCode($a),
                ReportPeriod::local($a->created_at)->format('d/m/Y H:i'),
                $a->typeLabel(),
                SecurityReportService::SEVERITY_LABELS[$a->severity] ?? $a->severity,
                $this->employeeCode($a->user?->employee?->employee_code, $canSeeCodes),
                $a->detail ?: $a->title,
                $this->statusLabel($a),
                $a->handledBy?->name ?? '',
                SecurityReportService::hours(SecurityReportService::handlingHours($a)),
            ])->all();

        return ReportExport::download(
            'canh-bao-bao-mat',
            ['Mã', 'Phát sinh', 'Loại', 'Mức độ', 'Mã NV', 'Tóm tắt', 'Trạng thái', 'Người xử lý', 'Thời gian xử lý (giờ)'],
            $rows, $format, 'Cảnh báo bảo mật',
        );
    }

    public function render(SecurityReportService $service): View
    {
        $period = $this->period();
        $filters = $this->filters();

        $kpis = $service->kpis($period, $filters);
        $daily = $service->dailyLogins($period);
        $byType = $service->alertsByType($period, $filters);
        $heatmap = $service->loginHeatmap($period);
        $flagged = $service->flaggedCells($heatmap);

        $rows = $this->tab === 'logins'
            ? $this->loginRowsQuery($service, $period)->with('user.employee')->paginate(15)
            : $this->alertRowsQuery($service, $period)->with(['user.employee', 'handledBy'])->paginate(15);

        return view('livewire.admin.reports.security-report', [
            'period' => $period,
            'clamped' => $this->wasClamped($period),
            'kpis' => $kpis,
            'daily' => $daily,
            'dailyInsight' => $service->dailyInsight($daily, $kpis['fail_rate']),
            'byType' => $byType,
            'typeInsight' => $service->typeInsight($byType),
            'heatmap' => $heatmap,
            'flagged' => $flagged,
            'heatmapInsight' => $service->heatmapInsight($heatmap, $flagged),
            'rows' => $rows,
            'canSeeCodes' => $this->canSeeEmployeeCodes(),
        ])->layout('layouts.admin', ['title' => 'Báo cáo bảo mật và đăng nhập']);
    }

    // ---- Dùng chung cho view và file xuất -----------------------------------

    public function employeeCode(?string $code, bool $canSee): string
    {
        return $canSee ? ($code ?: '—') : SecurityReportService::maskCode($code);
    }

    public function statusLabel(SecurityAlert $alert): string
    {
        $label = SecurityReportService::STATUS_LABELS[$alert->status] ?? $alert->status;

        return SecurityReportService::isOpenOverdue($alert)
            ? $label . ' · quá ' . SecurityReportService::OPEN_OVERDUE_HOURS . ' giờ'
            : $label;
    }

    private function filters(): array
    {
        return [
            'type' => array_key_exists((string) $this->type, SecurityAlert::TYPE_LABELS) ? $this->type : null,
            'severity' => array_key_exists((string) $this->severity, SecurityReportService::SEVERITY_LABELS) ? $this->severity : null,
            'status' => array_key_exists((string) $this->status, SecurityReportService::STATUS_LABELS) ? $this->status : null,
        ];
    }

    private function alertRowsQuery(SecurityReportService $service, ReportPeriod $period)
    {
        return $service->alertQuery($period, $this->filters())
            ->orderBy('created_at', $this->sortDir === 'asc' ? 'asc' : 'desc')
            ->orderBy('id', $this->sortDir === 'asc' ? 'asc' : 'desc');
    }

    private function loginRowsQuery(SecurityReportService $service, ReportPeriod $period)
    {
        return $service->loginQuery($period, $this->loginWeekday, $this->loginHour)
            ->orderBy('logged_in_at', $this->sortDir === 'asc' ? 'asc' : 'desc')
            ->orderBy('id', $this->sortDir === 'asc' ? 'asc' : 'desc');
    }

    /** Mã nhân viên là trường nhạy cảm: chỉ người có quyền Xem nhân sự mới thấy đầy đủ. */
    private function canSeeEmployeeCodes(): bool
    {
        return (bool) auth()->user()?->can('employees.view');
    }

    /** Kỳ tùy chọn dài hơn giới hạn đã bị ReportPeriod cắt bớt — báo cho người xem biết. */
    private function wasClamped(ReportPeriod $period): bool
    {
        if ($this->preset !== 'custom' || ! $this->from || ! $this->to) {
            return false;
        }

        return $period->startLocal->toDateString() !== min($this->from, $this->to);
    }
}
