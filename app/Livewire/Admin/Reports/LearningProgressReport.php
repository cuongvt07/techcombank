<?php

namespace App\Livewire\Admin\Reports;

use App\Livewire\Concerns\HasSearchFilters;
use App\Livewire\Concerns\ChecksPermissions;
use App\Livewire\Concerns\HasReportPeriod;
use App\Models\Enrollment;
use App\Services\LearningReminderService;
use App\Services\Reports\LearningProgressReportService;
use App\Support\Reports\ReportExport;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Báo cáo tiến độ học tập (SCR_002): một dòng là một lượt ghi danh nhân viên × khóa học.
 * Truy vấn nằm ở LearningProgressReportService; component chỉ giữ trạng thái bộ lọc.
 */
class LearningProgressReport extends Component
{
    use HasSearchFilters;

    use ChecksPermissions;
    use HasReportPeriod;
    use WithPagination;

    public const REPORT_MAX_DAYS = 366;

    /** Tất cả cột có thể bật/tắt → nhãn. Thứ tự = thứ tự hiển thị. */
    public const COLUMNS = [
        'code' => 'Mã NV',
        'name' => 'Họ tên',
        'department' => 'Phòng ban',
        'job_title' => 'Chức danh',
        'course' => 'Tên khóa',
        'type' => 'Loại',
        'source' => 'Nguồn giao',
        'assigned' => 'Ngày giao',
        'due' => 'Hạn',
        'completed' => 'Ngày hoàn thành',
        'lessons' => 'Bài xong',
        'progress' => 'Tiến độ',
        'hours' => 'Giờ học',
        'score' => 'Điểm',
        'status' => 'Trạng thái',
        'overdue_days' => 'Quá hạn (ngày)',
        'reminded' => 'Nhắc gần nhất',
    ];

    /** Ẩn mặc định: ít khi cần, bật qua "Cột hiển thị". */
    private const HIDDEN_BY_DEFAULT = ['source', 'completed', 'score'];

    private const PER_PAGE_OPTIONS = [10, 20, 50];

    #[Url(as: 'moc')]
    public string $basis = 'due';

    #[Url(as: 'don-vi')]
    public string $department = '';

    #[Url(as: 'khoa')]
    public string $course = '';

    #[Url(as: 'trang-thai')]
    public string $status = '';

    #[Url(as: 'loai')]
    public string $courseType = '';

    #[Url(as: 'tien-do-tu')]
    public string $progressMin = '';

    #[Url(as: 'tien-do-den')]
    public string $progressMax = '';

    #[Url(as: 'nguon')]
    public string $source = '';

    #[Url(as: 'bo-nghi')]
    public bool $excludeResigned = false;

    /** Ngày hoàn thành (Y-m-d, giờ VN) chọn bằng cách bấm một cột biểu đồ. */
    #[Url(as: 'ngay-xong')]
    public string $completedOn = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sap-xep')]
    public string $sort = 'due';

    #[Url(as: 'chieu')]
    public string $direction = 'asc';

    #[Url(as: 'so-dong')]
    public int $perPage = 20;

    /** @var array<int, string> Cột đang hiển thị */
    public array $columns = [];

    /** @var array<int, string> Id lượt ghi danh đang chọn để gửi nhắc */
    public array $selected = [];

    /** Kết quả thao tác gần nhất (layout không vẽ lại flash trong request Livewire). */
    public ?string $notice = null;

    private const FILTERS = [
        'basis', 'department', 'course', 'status', 'courseType', 'progressMin', 'progressMax',
        'source', 'excludeResigned', 'completedOn', 'search', 'perPage',
    ];

    public function mount(): void
    {
        $this->columns = array_values(array_diff(array_keys(self::COLUMNS), self::HIDDEN_BY_DEFAULT));
        $this->sanitize();
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->sanitize();
            $this->selected = [];
            $this->resetPage();
        }

        // Không cho tắt hết cột
        if (str_starts_with($property, 'columns') && $this->columns === []) {
            $this->columns = ['code'];
        }
    }

    public function sortBy(string $field): void
    {
        if (! LearningProgressReportService::sortable($field)) {
            return;
        }

        $this->direction = $this->sort === $field && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $field;
        $this->resetPage();
    }

    /** Bấm thẻ KPI: lọc bảng theo trạng thái đó; bấm lại để bỏ. */
    public function filterStatus(string $status): void
    {
        $this->status = $this->status === $status ? '' : $status;
        $this->updated('status');
    }

    /** Bấm một cột biểu đồ: lọc bảng theo ngày hoàn thành đó; bấm lại để bỏ. */
    public function filterCompletedOn(int $index): void
    {
        $date = $this->period()->dates()->get($index);
        if (! $date) {
            return;
        }

        $this->completedOn = $this->completedOn === $date ? '' : $date;
        $this->updated('completedOn');
    }

    /** Gỡ một bộ lọc từ chip "Đang áp dụng". */
    public function clearFilter(string $filter): void
    {
        if ($filter === 'progress') {
            $this->progressMin = '';
            $this->progressMax = '';
        } elseif ($filter === 'excludeResigned') {
            $this->excludeResigned = false;
        } elseif (in_array($filter, ['courseType', 'source', 'completedOn', 'status'], true)) {
            $this->{$filter} = '';
        }

        $this->updated('status');
    }

    public function resetFilters(): void
    {
        $this->reset(['basis', 'department', 'course', 'status', 'courseType', 'progressMin', 'progressMax',
            'source', 'excludeResigned', 'completedOn', 'search', 'preset', 'from', 'to', 'selected', 'notice']);
        $this->resetPage();
    }

    /** @param  array<int, int|string>  $ids  Id các dòng trên trang hiện tại */
    public function togglePage(array $ids): void
    {
        $ids = array_map('strval', $ids);
        $this->selected = array_diff($ids, $this->selected) === []
            ? array_values(array_diff($this->selected, $ids))
            : array_values(array_unique([...$this->selected, ...$ids]));
    }

    /**
     * Gửi nhắc: các dòng đang chọn, hoặc (không chọn gì) mọi lượt chưa hoàn thành khớp bộ lọc.
     * Lượt đã hoàn thành / vừa nhắc trong 12 giờ được dịch vụ nhắc tự bỏ qua.
     */
    public function sendReminders(): void
    {
        $this->requirePermission('report_learning.update');

        // Chỉ lấy id (vài nghìn số nguyên), rồi nạp từng lô 200 lượt kèm khóa học để gửi
        $ids = $this->selected !== []
            ? array_map('intval', $this->selected)
            : $this->report()->unfinishedQuery($this->filters(), $this->period())->pluck('e.id')->all();

        ['sent' => $sent, 'skipped' => $skipped] = app(LearningReminderService::class)->remindEnrollments(
            Enrollment::query()->whereIn('id', $ids)->with('course')->lazyById(200),
            auth()->id(),
        );

        $this->selected = [];
        $this->notice = 'Đã gửi nhắc ' . number_format($sent, 0, ',', '.') . ' lượt'
            . ($skipped > 0 ? ' · bỏ qua ' . number_format($skipped, 0, ',', '.') . ' lượt đã hoàn thành hoặc vừa được nhắc trong ' . LearningReminderService::COOLDOWN_HOURS . ' giờ' : '')
            . '.';
    }

    /** Xuất mọi dòng khớp bộ lọc, đúng các cột đang hiển thị. */
    public function export(string $format = 'xlsx')
    {
        $report = $this->report();
        $columns = $this->visibleColumns();
        $headings = array_values(array_map(fn ($key) => self::COLUMNS[$key], $columns));

        $rows = [];
        foreach ($report->exportCursor($this->filters(), $this->period(), $this->sort, $this->direction) as $row) {
            $rows[] = array_map(fn ($key) => $this->exportCell($row, $key), $columns);
        }

        return ReportExport::download('tien-do-hoc-tap', $headings, $rows, $format, 'Tiến độ học tập');
    }

    public function render(): View
    {
        $report = $this->report();
        $period = $this->period();
        $filters = $this->filters();
        $kpis = $report->kpis($filters, $period);
        $chart = $report->chart($filters, $period, $kpis['total']);
        $rows = $report->rows($filters, $period, $this->sort, $this->direction, $this->perPage);
        $totals = $report->totals($filters, $period);

        return view('livewire.admin.reports.learning-progress-report', [
            'period' => $period,
            'kpis' => $kpis,
            'chart' => $chart,
            'chartActive' => $this->completedOn !== '' ? array_search($this->completedOn, $chart['dates'], true) : false,
            'rows' => $rows,
            'totals' => $totals,
            'visible' => $this->visibleColumns(),
            'departments' => $report->departmentOptions(),
            'courses' => $report->courseOptions(),
            'chips' => $this->chips(),
            'remindCount' => $this->selected !== [] ? count($this->selected) : $totals['unfinished'],
            'today' => $report->today(),
            'target' => LearningProgressReportService::TARGET_PERCENT,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ])->layout('layouts.admin', ['title' => 'Báo cáo tiến độ học tập']);
    }

    public function filters(): array
    {
        return [
            'basis' => $this->basis,
            'department' => $this->department !== '' ? (int) $this->department : null,
            'course' => $this->course !== '' ? (int) $this->course : null,
            'status' => $this->status,
            'courseType' => $this->courseType,
            'progressMin' => $this->progressMin !== '' ? (int) $this->progressMin : null,
            'progressMax' => $this->progressMax !== '' ? (int) $this->progressMax : null,
            'source' => $this->source,
            'excludeResigned' => $this->excludeResigned,
            'completedOn' => $this->completedOn !== '' ? $this->completedOn : null,
            'search' => $this->search,
        ];
    }

    private function report(): LearningProgressReportService
    {
        return app(LearningProgressReportService::class);
    }

    /** @return array<int, string> */
    private function visibleColumns(): array
    {
        $visible = array_values(array_intersect(array_keys(self::COLUMNS), $this->columns));

        return $visible !== [] ? $visible : ['code'];
    }

    /** Chip "Đang áp dụng" cho bộ lọc nâng cao + ngày bấm từ biểu đồ. */
    private function chips(): array
    {
        $chips = [];

        if ($this->courseType !== '') {
            $chips['courseType'] = 'Loại khóa: ' . ($this->courseType === 'mandatory' ? 'Bắt buộc' : 'Tự chọn');
        }
        if ($this->progressMin !== '' || $this->progressMax !== '') {
            $chips['progress'] = 'Tiến độ: ' . ($this->progressMin !== '' ? $this->progressMin : '0') . '–' . ($this->progressMax !== '' ? $this->progressMax : '100') . '%';
        }
        if ($this->source !== '') {
            $chips['source'] = 'Nguồn giao: ' . ($this->source === 'rule' ? 'Rule tự động' : 'Giao tay');
        }
        if ($this->excludeResigned) {
            $chips['excludeResigned'] = 'Bỏ nhân viên đã nghỉ';
        }
        if ($this->completedOn !== '') {
            $chips['completedOn'] = 'Ngày hoàn thành: ' . CarbonImmutable::parse($this->completedOn)->format('d/m/Y');
        }

        return $chips;
    }

    /** Giá trị URL do người dùng gõ tay → đưa về miền hợp lệ. */
    private function sanitize(): void
    {
        if (! array_key_exists($this->basis, LearningProgressReportService::BASIS_LABELS)) {
            $this->basis = 'due';
        }
        if (! array_key_exists($this->status, LearningProgressReportService::STATUS_FILTERS)) {
            $this->status = '';
        }
        if (! in_array($this->courseType, ['', 'mandatory', 'elective'], true)) {
            $this->courseType = '';
        }
        if (! in_array($this->source, ['', 'rule', 'manual'], true)) {
            $this->source = '';
        }
        foreach (['progressMin', 'progressMax'] as $prop) {
            $this->{$prop} = is_numeric($this->{$prop}) ? (string) max(0, min(100, (int) $this->{$prop})) : '';
        }
        if (! ctype_digit($this->department)) {
            $this->department = '';
        }
        if (! ctype_digit($this->course)) {
            $this->course = '';
        }
        if ($this->completedOn !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->completedOn)) {
            $this->completedOn = '';
        }
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 20;
        }
        if (! LearningProgressReportService::sortable($this->sort)) {
            $this->sort = 'due';
        }
        $this->direction = $this->direction === 'desc' ? 'desc' : 'asc';
    }

    private function exportCell(Enrollment $row, string $key): string|int|float|null
    {
        $date = fn ($value) => $value ? ReportPeriod::local($value)->format('d/m/Y') : '';

        return match ($key) {
            'code' => $row->employee_code,
            'name' => $row->full_name,
            'department' => $row->department_name,
            'job_title' => $row->job_title_name,
            'course' => $row->course_title,
            'type' => self::typeLabel($row),
            'source' => $row->course_assignment_rule_id ? 'Rule tự động' : 'Giao tay',
            'assigned' => $date($row->assigned_at),
            'due' => $row->due_date?->format('d/m/Y') ?? '',
            'completed' => $date($row->completed_at),
            'lessons' => $row->completed_lessons . '/' . $row->total_lessons,
            'progress' => round((float) $row->progress_percent, 1),
            'hours' => round($row->time_spent / 3600, 1),
            'score' => $row->final_score !== null ? (float) $row->final_score : '',
            'status' => self::statusLabel($row),
            'overdue_days' => self::overdueDays($row, ReportPeriod::local(now())->toDateString()) ?? '',
            'reminded' => $row->last_reminded_at ? ReportPeriod::local($row->last_reminded_at)->format('d/m/Y H:i') : '',
            default => '',
        };
    }

    public static function typeLabel(Enrollment $row): string
    {
        return match (true) {
            (bool) $row->is_onboarding => 'Onboarding',
            $row->is_mandatory => 'Bắt buộc',
            default => 'Tự chọn',
        };
    }

    public static function statusLabel(Enrollment $row): string
    {
        if ($row->state !== 'completed' && $row->state !== 'overdue' && $row->due_soon) {
            return 'Sắp đến hạn';
        }

        return LearningProgressReportService::STATE_LABELS[$row->state] ?? $row->state;
    }

    public static function overdueDays(Enrollment $row, string $today): ?int
    {
        if ($row->state !== 'overdue' || ! $row->due_date) {
            return null;
        }

        // Không gọi startOfDay() trên due_date: Carbon thường sẽ sửa luôn thuộc tính của model
        return (int) CarbonImmutable::parse($row->due_date->toDateString())->diffInDays(CarbonImmutable::parse($today));
    }
}
