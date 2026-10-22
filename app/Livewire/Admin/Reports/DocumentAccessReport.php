<?php

namespace App\Livewire\Admin\Reports;

use App\Livewire\Concerns\ChecksPermissions;
use App\Livewire\Concerns\HasReportPeriod;
use App\Services\Reports\DocumentAccessReportService;
use App\Support\Reports\ReportExport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Báo cáo truy cập và sử dụng tài liệu (SCR_007).
 *
 * Tab "Nhật ký truy cập" lộ từng lượt xem kèm IP và mã watermark — dữ liệu truy vết
 * rò rỉ — nên chỉ người có quyền security.view mới mở được, và kỳ bị giới hạn 92 ngày.
 */
class DocumentAccessReport extends Component
{
    use ChecksPermissions;
    use HasReportPeriod;
    use WithPagination;

    public const LOG_MAX_DAYS = 92;

    private const PER_PAGE = 10;

    private const LOG_PER_PAGE = 20;

    private const TOP_LIMIT = 5;

    private const SORTABLE = ['title', 'category', 'version', 'published_at', 'granted', 'viewers', 'reach', 'downloads', 'denied'];

    #[Url(as: 'dm')]
    public ?int $categoryId = null;

    #[Url(as: 'mat')]
    public ?string $confidentiality = null;

    #[Url(as: 'hd')]
    public ?string $action = null;

    #[Url(as: 'dv')]
    public ?int $ownerDepartmentId = null;

    #[Url(as: 'tab')]
    public string $tab = 'documents';

    // Mặc định xếp tỷ lệ tiếp cận tăng dần: tài liệu ít người đọc nhất cần xử lý trước
    #[Url(as: 'sx')]
    public string $sort = 'reach';

    #[Url(as: 'chieu')]
    public string $direction = 'asc';

    #[Url(as: 'top')]
    public string $topMode = 'least';

    public function updated(string $property): void
    {
        if (in_array($property, ['categoryId', 'confidentiality', 'action', 'ownerDepartmentId'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['categoryId', 'confidentiality', 'action', 'ownerDepartmentId', 'preset', 'from', 'to']);
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        if ($tab === 'log') {
            $this->requirePermission('security.view');
        }

        $this->tab = $tab === 'log' ? 'log' : 'documents';
        $this->resetPage();
    }

    public function setTopMode(string $mode): void
    {
        $this->topMode = $mode === 'most' ? 'most' : 'least';
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = in_array($column, ['title', 'category', 'reach'], true) ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    /** Ghi đè kỳ của trait: tab nhật ký chỉ cho tối đa 92 ngày. */
    public function period(): ReportPeriod
    {
        return ReportPeriod::make($this->preset, $this->from, $this->to, $this->showingLog() ? self::LOG_MAX_DAYS : 366);
    }

    public function export(string $format = 'xlsx')
    {
        $service = app(DocumentAccessReportService::class);
        $period = $this->period();
        $filters = $this->filters();

        if ($this->tab === 'log') {
            $this->requirePermission('security.view');

            $rows = $service->accessLogQuery($period, $filters)->get()->map(fn ($log) => [
                ReportPeriod::local($log->accessed_at)->format('d/m/Y H:i:s'),
                $log->employee_name ?? $log->user_name ?? '',
                $log->employee_code ?? '',
                $log->document_title,
                DocumentAccessReportService::ACTION_LABELS[$log->action] ?? $log->action,
                $log->deny_reason ?? '',
                $log->ip_address ?? '',
                $log->watermark_token ?? '',
            ])->all();

            return ReportExport::download(
                'nhat-ky-truy-cap-tai-lieu',
                ['Thời điểm', 'Nhân viên', 'Mã NV', 'Tài liệu', 'Hành động', 'Lý do chặn', 'IP', 'Mã watermark'],
                $rows,
                $format,
                'Nhật ký truy cập',
            );
        }

        $rows = $this->documentRows($service, $period, $filters)->map(fn (array $r) => [
            $r['title'],
            $r['category'] ?? '',
            DocumentAccessReportService::CONFIDENTIALITY_LABELS[$r['confidentiality']] ?? $r['confidentiality'],
            $r['version'] ? 'v' . $r['version'] : '',
            $r['published_at'] ? ReportPeriod::local($r['published_at'])->format('d/m/Y') : '',
            $r['granted'],
            $r['views'] ?? '—',
            $r['viewers'] ?? '—',
            $r['reach'] !== null ? number_format($r['reach'], 1, ',', '.') . '%' : '—',
            $r['is_video'] || $r['downloads'] === null ? '—' : $r['downloads'],
            $r['denied'] ?? '—',
            $r['watermark'] ? 'Có' : 'Không',
            $r['course_count'],
        ])->all();

        return ReportExport::download(
            'truy-cap-tai-lieu',
            ['Tiêu đề', 'Danh mục', 'Độ mật', 'Bản', 'Xuất bản', 'Được cấp quyền', 'Lượt xem', 'Người xem', 'Tỷ lệ tiếp cận', 'Tải', 'Bị chặn', 'Watermark', 'Dùng trong khóa'],
            $rows,
            $format,
            'Theo tài liệu',
        );
    }

    public function render(DocumentAccessReportService $service): View
    {
        $period = $this->period();
        $filters = $this->filters();
        $canViewLog = $this->canViewLog();

        // Tab nhật ký mở qua link nhưng thiếu quyền: hiện tab tài liệu thay vì lộ dữ liệu
        $tab = $this->showingLog() ? 'log' : 'documents';

        $rows = $this->documentRows($service, $period, $filters);

        $documents = null;
        $logs = null;

        if ($tab === 'log') {
            $logs = $service->accessLogQuery($period, $filters)->paginate(self::LOG_PER_PAGE);
        } else {
            $page = $this->getPage();
            $documents = new LengthAwarePaginator(
                $rows->forPage($page, self::PER_PAGE)->values(),
                $rows->count(),
                self::PER_PAGE,
                $page,
                ['path' => request()->url(), 'pageName' => 'page'],
            );
        }

        $weeks = $service->weekly($period, $filters);
        $requested = ReportPeriod::make($this->preset, $this->from, $this->to);

        return view('livewire.admin.reports.document-access-report', [
            'period' => $period,
            'kpis' => $service->kpis($period, $filters),
            'weeks' => $weeks,
            'weekNote' => $this->weekNote($weeks),
            'top' => $this->topRows($rows),
            'documents' => $documents,
            'logs' => $logs,
            'activeTab' => $tab,
            'canViewLog' => $canViewLog,
            'logPeriodClamped' => $tab === 'log' && $requested->days() > $period->days(),
            'categories' => $service->categoryOptions(),
            'departmentOptions' => $service->departmentOptions(),
            'actionLabels' => DocumentAccessReportService::ACTION_LABELS,
            'confidentialityLabels' => DocumentAccessReportService::CONFIDENTIALITY_LABELS,
            'lowReach' => DocumentAccessReportService::LOW_REACH_PERCENT,
            'burstThreshold' => DocumentAccessReportService::BLOCKED_BURST_PER_HOUR,
        ])->layout('layouts.admin', ['title' => 'Báo cáo truy cập và sử dụng tài liệu']);
    }

    private function filters(): array
    {
        return [
            'category_id' => $this->categoryId,
            'confidentiality' => $this->confidentiality ?: null,
            'action' => $this->action ?: null,
            'owner_department_id' => $this->ownerDepartmentId,
        ];
    }

    private function canViewLog(): bool
    {
        return (bool) auth()->user()?->can('security.view');
    }

    private function showingLog(): bool
    {
        return $this->tab === 'log' && $this->canViewLog();
    }

    /**
     * Mọi dòng tài liệu đã sắp xếp, kèm số người được cấp quyền.
     *
     * Cần số được cấp quyền của mọi tài liệu khớp lọc (không chỉ trang đang xem) vì cả thứ tự
     * mặc định lẫn khối "Top tài liệu" đều xếp theo tỷ lệ tiếp cận. Chi phí được chặn bởi
     * cache 1 ngày theo id tài liệu trong service.
     */
    private function documentRows(DocumentAccessReportService $service, ReportPeriod $period, array $filters): Collection
    {
        $rows = $service->withReach($service->documentRows($period, $filters));
        $sort = in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'reach';

        return $service->sortRows($rows, $sort, $this->direction === 'desc' ? 'desc' : 'asc');
    }

    private function topRows(Collection $rows): Collection
    {
        $withReach = $rows->filter(fn ($r) => $r['reach'] !== null);

        $sorted = $this->topMode === 'most'
            ? $withReach->sortByDesc('reach')
            : $withReach->sortBy('reach');

        return $sorted->take(self::TOP_LIMIT)->values();
    }

    /** Nhận định cho tuần cuối khi tuần đó chưa đủ 7 ngày (so với tuần đủ ngày liền trước). */
    private function weekNote(Collection $weeks): ?string
    {
        $last = $weeks->last();
        $previous = $weeks->count() > 1 ? $weeks[$weeks->count() - 2] : null;

        if (! $last || $last['days'] >= 7) {
            return null;
        }

        $days = $last['days'] . ' ngày';

        if ($previous && $last['denied'] > 0 && $last['denied'] > $previous['denied']) {
            return "Tuần cuối mới có {$days} nhưng số lượt bị chặn đã vượt cả tuần trước đó.";
        }

        if ($previous && $last['views'] > $previous['views']) {
            return "Tuần cuối mới có {$days} nhưng lượt xem đã vượt cả tuần trước đó.";
        }

        return "Tuần cuối mới có {$days} — chưa đủ tuần, đừng so trực tiếp với các tuần trước.";
    }
}
