<?php

namespace App\Livewire\Admin\Reports;

use App\Livewire\Concerns\ChecksPermissions;
use App\Livewire\Concerns\HasReportPeriod;
use App\Models\SupportRequest;
use App\Services\Reports\SupportReportService;
use App\Support\Reports\ReportExport;
use App\Support\Reports\ReportPeriod;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Báo cáo yêu cầu hỗ trợ (SCR_009): khối lượng, tồn đọng, đúng SLA, phiếu quá hạn.
 *
 * Nội dung phiếu (content, resolution) không hiện ở đây: báo cáo dành cho người
 * theo dõi vận hành, chi tiết phiếu chỉ mở ở màn Phiếu hỗ trợ.
 */
class SupportReport extends Component
{
    use ChecksPermissions;
    use HasReportPeriod;
    use WithPagination;

    public const REPORT_MAX_DAYS = 184;

    private const FILTERS = ['topic', 'priority', 'status', 'assignee', 'overSla'];

    /** Các cột ẩn được qua menu "Cột hiển thị" (Mã yêu cầu luôn hiện). */
    public const COLUMNS = [
        'created_at' => 'Ngày gửi',
        'employee' => 'Người gửi',
        'topic' => 'Chủ đề',
        'subject' => 'Tiêu đề',
        'priority' => 'Ưu tiên',
        'status' => 'Trạng thái',
        'assignee' => 'Phụ trách',
        'sla_due_at' => 'Hạn SLA',
        'overdue' => 'Quá',
    ];

    #[Url(as: 'chu_de')]
    public string $topic = '';

    #[Url(as: 'uu_tien')]
    public string $priority = '';

    /** Mặc định chỉ phiếu còn mở: báo cáo dùng để đuổi việc tồn. */
    #[Url(as: 'trang_thai')]
    public string $status = 'open';

    /** '' = tất cả, 'none' = chưa phân công, hoặc id người phụ trách */
    #[Url(as: 'phu_trach')]
    public string $assignee = '';

    #[Url(as: 'qua_sla')]
    public bool $overSla = true;

    #[Url(as: 'sap_xep')]
    public string $sort = 'overdue';

    #[Url(as: 'chieu')]
    public string $direction = 'desc';

    /** @var array<int, string> */
    public array $hiddenColumns = [];

    /** @var array<int, string> id phiếu đang tick ở bảng */
    public array $selected = [];

    public bool $showAssign = false;

    public ?string $assignTo = null;

    public function render(): View
    {
        $service = $this->service();
        $period = $this->period();
        $filters = $this->filters();

        $rows = $service->listQuery($period, $filters, $this->sort, $this->direction)->paginate(15);

        return view('livewire.admin.reports.support-report', [
            'period' => $period,
            'kpi' => $service->kpis($period, $filters),
            'weekly' => $service->weekly($period, $filters),
            'topics' => $service->topics($period, $filters),
            'rows' => $rows,
            'topicOptions' => $service->topicOptions(),
            'assigneeOptions' => $service->assigneeOptions(),
            'assignableUsers' => $this->showAssign ? $service->assignableUsers() : collect(),
            'slaHours' => SupportRequest::SLA_HOURS,
        ])->layout('layouts.admin', ['title' => 'Báo cáo yêu cầu hỗ trợ']);
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['topic', 'priority', 'status', 'assignee', 'overSla', 'sort', 'direction', 'preset', 'from', 'to', 'selected']);
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['overdue', 'created_at', 'sla_due_at', 'priority'], true)) {
            return;
        }

        $this->direction = $this->sort === $field && $this->direction === 'desc' ? 'asc' : 'desc';
        $this->sort = $field;
        $this->resetPage();
    }

    public function openAssign(): void
    {
        $this->requirePermission('report_support.update');

        $this->assignTo = null;
        $this->resetValidation();
        $this->showAssign = true;
    }

    /** Phân công hàng loạt các phiếu đang tick cho một người. */
    public function bulkAssign(): void
    {
        $this->requirePermission('report_support.update');
        $service = $this->service();

        $allowed = $service->assignableUsers()->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->validate([
            'selected' => ['required', 'array', 'min:1'],
            'assignTo' => ['required', 'in:' . implode(',', $allowed ?: ['0'])],
        ], [
            'selected.required' => 'Hãy tick chọn ít nhất một yêu cầu ở bảng.',
            'selected.min' => 'Hãy tick chọn ít nhất một yêu cầu ở bảng.',
            'assignTo.required' => 'Hãy chọn người phụ trách.',
            'assignTo.in' => 'Người này không có quyền xử lý phiếu hỗ trợ.',
        ]);

        $result = $service->bulkAssign($this->selected, (int) $this->assignTo);

        $message = "Đã phân công {$result['assigned']} yêu cầu.";
        if ($result['skipped'] > 0) {
            $message .= " Bỏ qua {$result['skipped']} yêu cầu đã xong hoặc đã do người này phụ trách.";
        }

        session()->flash('status', $message);
        $this->selected = [];
        $this->showAssign = false;
    }

    /** Xuất mọi dòng khớp bộ lọc của bảng (không phân trang, không có nội dung phiếu). */
    public function export(string $format = 'xlsx')
    {
        $rows = $this->service()->listQuery($this->period(), $this->filters(), $this->sort, $this->direction)
            ->get()
            ->map(fn (SupportRequest $r) => [
                $r->ticket_no,
                ReportPeriod::local($r->created_at)?->format('d/m/Y H:i'),
                $r->employee?->full_name,
                $r->employee?->employee_code,
                $r->employee?->department?->name,
                $r->topic,
                $r->subject,
                SupportRequest::PRIORITY_LABELS[$r->priority] ?? $r->priority,
                SupportRequest::STATUS_LABELS[$r->status] ?? $r->status,
                $r->assignedTo?->name ?? 'Chưa phân công',
                ReportPeriod::local($r->sla_due_at)?->format('d/m/Y H:i'),
                ReportPeriod::local($r->resolved_at ?? $r->closed_at)?->format('d/m/Y H:i'),
                $r->overdue_seconds !== null && $r->overdue_seconds > 0 ? round($r->overdue_seconds / 3600, 1) : 0,
            ])
            ->all();

        return ReportExport::download('bao-cao-yeu-cau-ho-tro', [
            'Mã yêu cầu', 'Ngày gửi', 'Người gửi', 'Mã NV', 'Đơn vị', 'Chủ đề', 'Tiêu đề',
            'Ưu tiên', 'Trạng thái', 'Phụ trách', 'Hạn SLA', 'Xong lúc', 'Quá hạn (giờ)',
        ], $rows, $format, 'Yêu cầu hỗ trợ');
    }

    private function service(): SupportReportService
    {
        return app(SupportReportService::class);
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'topic' => $this->topic,
            'priority' => array_key_exists($this->priority, SupportRequest::PRIORITY_LABELS) ? $this->priority : '',
            'status' => $this->status,
            'assignee' => $this->assignee,
            'over_sla' => $this->overSla,
        ];
    }
}
