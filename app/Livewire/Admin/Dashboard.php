<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ChecksPermissions;
use App\Livewire\Concerns\HasReportPeriod;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\JobTitle;
use App\Services\DashboardFilter;
use App\Services\DashboardMetricsService;
use App\Services\LearningReminderService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tổng quan đào tạo (SCR_001).
 *
 * Thứ tự khối theo câu hỏi người vận hành: chỉ số then chốt so với kỳ so sánh,
 * cảnh báo nghiệp vụ, xu hướng, chỗ chậm (đơn vị / khóa), rồi danh sách người cần
 * nhắc để hành động ngay. Mọi khối dùng chung một bộ lọc (DashboardFilter).
 * Định nghĩa từng chỉ số nằm ở DashboardMetricsService.
 */
class Dashboard extends Component
{
    use ChecksPermissions, HasReportPeriod, WithPagination;

    /** Biểu đồ vẽ theo ngày — kỳ dài hơn một quý thì quá dày để đọc. */
    public const REPORT_MAX_DAYS = 92;

    /** Số dòng/trang của bảng "Nhân viên cần nhắc"; phần tử đầu là mặc định. */
    public const PER_PAGE_OPTIONS = [20, 50, 100];

    #[Url(as: 'ss')]
    public string $compare = DashboardFilter::COMPARE_PREVIOUS;

    #[Url(as: 'dv')]
    public ?int $departmentId = null;

    #[Url(as: 'cd')]
    public ?int $jobTitleId = null;

    #[Url(as: 'loai')]
    public string $courseType = DashboardFilter::COURSE_ALL;

    /** @var array<int, string> rỗng = mọi trạng thái nhân sự */
    #[Url(as: 'nv')]
    public array $employmentStatuses = DashboardFilter::DEFAULT_STATUSES;

    /** Lát donut / thẻ KPI đang chọn — lọc bảng "Nhân viên cần nhắc". */
    #[Url(as: 'tt')]
    public ?string $statusFilter = null;

    /** Số dòng mỗi trang của bảng nhắc (20 / 50 / 100). Kiểu lỏng vì nhận từ URL / select. */
    #[Url(as: 'so-dong', except: 20)]
    public $perPage = 20;

    /** @var array<int, string> id lượt ghi danh đã tick trong bảng */
    public array $selected = [];

    /** Kết quả gửi nhắc gần nhất, hiện trên bảng. */
    public ?string $notice = null;

    public function mount()
    {
        // Người chỉ được cấp vài màn (không có quyền Tổng quan) vào /quan-tri:
        // đưa tới màn đầu tiên họ được xem thay vì báo lỗi 403
        if (! auth()->user()?->can('dashboard.view')) {
            foreach (\App\Support\PermissionCatalog::ROUTE_MODULES as $route => $module) {
                if ($route !== 'admin.dashboard' && \Illuminate\Support\Facades\Route::has($route)
                    && ! str_contains($route, '.show')
                    && auth()->user()?->can($module . '.view')) {
                    return $this->redirectRoute($route, navigate: true);
                }
            }

            abort(403, 'Bạn chưa được cấp quyền màn quản trị nào.');
        }
    }

    // ---- Bộ lọc -------------------------------------------------------------

    /** Đổi bất kỳ bộ lọc nào: về trang 1 và bỏ các dòng đã tick (có thể không còn trong danh sách). */
    public function updated(string $property): void
    {
        if (in_array(strtok($property, '.'), ['compare', 'departmentId', 'jobTitleId', 'courseType', 'employmentStatuses', 'statusFilter', 'perPage'], true)) {
            $this->selected = [];
            $this->resetPage();
        }
    }

    protected function onPeriodChanged(): void
    {
        $this->selected = [];
        $this->resetPage();
    }

    /** Bấm lát donut / thẻ quá hạn: lọc bảng theo trạng thái đó; bấm lại mục đang chọn thì bỏ lọc. */
    public function filterStatus(string $status): void
    {
        $this->statusFilter = $this->statusFilter === $status || ! isset(DashboardMetricsService::STATUS_LABELS[$status])
            ? null
            : $status;
        $this->selected = [];
        $this->resetPage();
    }

    /** Bấm × trên chip bộ lọc: chỉ bỏ bộ lọc đó. */
    public function clearFilter(string $key): void
    {
        match ($key) {
            'departmentId', 'jobTitleId', 'courseType', 'compare', 'employmentStatuses', 'statusFilter' => $this->reset($key),
            'period' => $this->reset(['preset', 'from', 'to']),
            default => null,
        };
        $this->selected = [];
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['preset', 'from', 'to', 'compare', 'departmentId', 'jobTitleId', 'courseType',
            'employmentStatuses', 'statusFilter', 'selected', 'notice']);
        $this->resetPage();
    }

    public function filter(): DashboardFilter
    {
        return new DashboardFilter(
            period: $this->period(),
            compare: array_key_exists($this->compare, DashboardFilter::COMPARES) ? $this->compare : DashboardFilter::COMPARE_PREVIOUS,
            departmentId: $this->departmentId ?: null,
            jobTitleId: $this->jobTitleId ?: null,
            courseType: array_key_exists($this->courseType, DashboardFilter::COURSE_TYPES) ? $this->courseType : DashboardFilter::COURSE_ALL,
            employmentStatuses: array_values(array_intersect($this->employmentStatuses, array_keys(Employee::STATUS_LABELS))),
        );
    }

    /** Số dòng/trang hợp lệ; giá trị lạ trên URL quay về mặc định. */
    public function perPageValue(): int
    {
        $value = filter_var($this->perPage, FILTER_VALIDATE_INT);

        return in_array($value, self::PER_PAGE_OPTIONS, true) ? $value : self::PER_PAGE_OPTIONS[0];
    }

    // ---- Gửi nhắc -----------------------------------------------------------

    public function remind(int $enrollmentId): void
    {
        $this->requirePermission('report_learning.update');

        $this->sendReminders(Enrollment::query()->whereKey($enrollmentId)->get());
    }

    public function remindSelected(): void
    {
        $this->requirePermission('report_learning.update');

        $ids = array_filter(array_map('intval', $this->selected));
        if (! $ids) {
            $this->notice = 'Chưa chọn dòng nào để gửi nhắc.';

            return;
        }

        $this->sendReminders(Enrollment::query()->whereIn('id', $ids)->get());
        $this->selected = [];
    }

    /** Tick/bỏ tick cả trang hiện tại. */
    public function togglePage(array $ids): void
    {
        $ids = array_map('strval', $ids);
        $this->selected = array_diff($ids, $this->selected) === []
            ? array_values(array_diff($this->selected, $ids))
            : array_values(array_unique([...$this->selected, ...$ids]));
    }

    private function sendReminders($enrollments): void
    {
        $result = app(LearningReminderService::class)->remindEnrollments($enrollments, auth()->id());

        $this->notice = "Đã gửi nhắc {$result['sent']} lượt"
            . ($result['skipped'] ? " · bỏ qua {$result['skipped']} lượt (đã nhắc trong "
                . LearningReminderService::COOLDOWN_HOURS . ' giờ qua hoặc đã hoàn thành)' : '') . '.';
    }

    /** Link "Báo cáo mẫu": bản in / Excel tổng quan theo bộ lọc đang xem. */
    public function templateUrl(): string
    {
        return route('admin.reports.template', array_filter([
            'report' => 'tong-quan',
            'ky' => $this->preset, 'tu' => $this->from, 'den' => $this->to,
            'ss' => $this->compare, 'dv' => $this->departmentId, 'cd' => $this->jobTitleId, 'loai' => $this->courseType,
            // Rỗng = mọi trạng thái nhân sự; gửi 'tat-ca' để không bị hiểu là mặc định
            'nv' => implode(',', $this->employmentStatuses) ?: 'tat-ca',
        ], fn ($v) => $v !== null && $v !== ''));
    }

    // ---- Hiển thị -----------------------------------------------------------

    public function render(): View
    {
        $metrics = app(DashboardMetricsService::class);
        $filter = $this->filter();
        $this->perPage = $this->perPageValue();

        $kpis = $metrics->kpis($filter);
        $courses = $metrics->coursesNeedingAction($filter);
        $departmentOptions = $this->departmentOptions();
        $jobTitleOptions = JobTitle::query()->orderBy('name')->pluck('name', 'id');

        return view('livewire.admin.dashboard', [
            'filter' => $filter,
            'cards' => $this->kpiCards($kpis),
            'headcount' => $kpis['active']['current']['headcount'],
            'alerts' => $metrics->trainingAlerts($filter),
            'trend' => $metrics->trend($filter, $kpis['active']['current']['series']),
            'statusCounts' => $metrics->statusBreakdown($filter),
            'units' => $metrics->departmentProgress($filter),
            'courses' => $courses->take(5),
            'courseCount' => $courses->count(),
            'rows' => $metrics->reminderQuery($filter, $this->statusFilter)->paginate($this->perPageValue()),
            'departmentOptions' => $departmentOptions,
            'jobTitleOptions' => $jobTitleOptions,
            'chips' => $this->filterChips($departmentOptions, $jobTitleOptions->all()),
        ])->layout('layouts.admin', ['title' => 'Tổng quan đào tạo']);
    }

    /**
     * Năm thẻ KPI đã định dạng kiểu VN. Delta: tỷ lệ so bằng điểm phần trăm (pp),
     * số lượng so bằng %; màu theo ý nghĩa (quá hạn tăng là xấu). 'empty' = chưa có
     * dữ liệu để tính (thẻ hiện trạng thái trống thay cho dấu "—").
     *
     * @return array<string, array<string, mixed>>
     */
    private function kpiCards(array $kpis): array
    {
        $pct = fn (?float $v) => $v === null ? '—' : number_format($v, 1, ',', '.') . '%';
        $int = fn ($v) => number_format((float) $v, 0, ',', '.');

        $pp = function (?float $now, ?float $before, bool $upIsGood = true): array {
            if ($now === null || $before === null) {
                return [null, 'neutral'];
            }
            $diff = round($now - $before, 1);

            return [self::arrow($diff) . number_format(abs($diff), 1, ',', '.') . ' pp', self::tone($diff, $upIsGood)];
        };
        $change = function (float $now, float $before, bool $upIsGood = true): array {
            // Kỳ so sánh bằng 0 thì % thay đổi vô nghĩa
            if ($before <= 0) {
                return [$now > 0 ? '▲ mới phát sinh' : null, $now > 0 ? self::tone(1, $upIsGood) : 'neutral'];
            }
            $diff = round(($now - $before) / $before * 100, 1);

            return [self::arrow($diff) . number_format(abs($diff), 1, ',', '.') . '%', self::tone($diff, $upIsGood)];
        };

        ['current' => $onTime, 'previous' => $onTimePrev] = $kpis['on_time'];
        ['current' => $active, 'previous' => $activePrev] = $kpis['active'];
        ['current' => $overdue, 'previous' => $overduePrev] = $kpis['overdue'];
        ['current' => $pass, 'previous' => $passPrev] = $kpis['first_pass'];
        ['current' => $hours, 'previous' => $hoursPrev] = $kpis['hours'];

        $hoursText = fn (float $h) => number_format($h, $h < 100 ? 1 : 0, ',', '.');

        return [
            'on_time' => [
                'label' => 'Hoàn thành đúng hạn',
                'value' => $pct($onTime['value']),
                'delta' => $pp($onTime['value'], $onTimePrev['value']),
                'note' => $onTime['due'] > 0
                    ? $int($onTime['on_time']) . ' / ' . $int($onTime['due']) . ' lượt đến hạn'
                    : 'Không có lượt nào đến hạn trong kỳ',
                'spark' => $onTime['series'],
                'alert' => false,
                'empty' => $onTime['value'] === null ? 'Chưa có lượt nào đến hạn trong kỳ' : null,
            ],
            'active' => [
                'label' => 'Học viên hoạt động',
                'value' => $int($active['value']),
                'delta' => $change($active['value'], $activePrev['value']),
                'note' => $active['headcount'] > 0
                    ? 'chiếm ' . $pct($active['value'] / $active['headcount'] * 100) . ' nhân sự'
                    : 'Không có nhân sự trong phạm vi lọc',
                'spark' => $active['series'],
                'alert' => false,
                'empty' => null,
            ],
            'overdue' => [
                'label' => 'Lượt ghi danh quá hạn',
                'short' => 'Ghi danh quá hạn',
                'value' => $int($overdue['value']),
                'delta' => $change($overdue['value'], $overduePrev['value'], upIsGood: false),
                'note' => $int($overdue['employees']) . ' nhân viên · ' . $int($overdue['courses']) . ' khóa',
                'spark' => $overdue['series'],
                // Số quá hạn tô đỏ (cảnh báo); thẻ bấm được để lọc bảng nhắc theo quá hạn
                'alert' => $overdue['value'] > 0,
                'filter' => Enrollment::STATUS_OVERDUE,
                'empty' => null,
            ],
            'first_pass' => [
                'label' => 'Đạt kiểm tra lần đầu',
                'value' => $pct($pass['value']),
                'delta' => $pp($pass['value'], $passPrev['value']),
                'note' => $pass['avg_score'] !== null
                    ? 'điểm trung bình ' . number_format($pass['avg_score'], 1, ',', '.')
                    : 'Chưa có lượt thi lần đầu trong kỳ',
                'spark' => $pass['series'],
                'alert' => false,
                'empty' => $pass['value'] === null ? 'Chưa có lượt thi lần đầu nào trong kỳ' : null,
            ],
            'hours' => [
                'label' => 'Tổng giờ học',
                'value' => $hoursText($hours['value']),
                'delta' => $change($hours['value'], $hoursPrev['value']),
                'note' => $hours['per_learner'] !== null
                    ? 'trung bình ' . number_format($hours['per_learner'], 1, ',', '.') . ' giờ/học viên'
                    : 'Chưa ghi nhận giờ học trong kỳ',
                'spark' => $hours['series'],
                'alert' => false,
                'empty' => null,
            ],
        ];
    }

    private static function arrow(float $diff): string
    {
        return $diff > 0 ? '▲ ' : ($diff < 0 ? '▼ ' : '');
    }

    private static function tone(float $diff, bool $upIsGood): string
    {
        if ($diff == 0) {
            return 'neutral';
        }

        return ($diff > 0) === $upIsGood ? 'good' : 'bad';
    }

    /**
     * Đơn vị theo cây (cha trước con, thụt lề theo cấp) để chọn một khối là thấy
     * ngay khối đó gồm những phòng nào.
     *
     * @return array<int, string>
     */
    private function departmentOptions(): array
    {
        $all = Department::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'parent_id']);
        $byParent = $all->groupBy(fn ($d) => (int) $d->parent_id);
        $options = [];

        $walk = function (int $parentId, int $depth) use (&$walk, &$options, $byParent) {
            foreach ($byParent[$parentId] ?? [] as $dept) {
                $options[$dept->id] = str_repeat('  ', $depth) . ($depth ? '› ' : '') . $dept->name;
                $walk($dept->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $options;
    }

    /**
     * Chip của các bộ lọc trong popover "Bộ lọc" đang khác mặc định (khóa = tên bộ lọc
     * để bấm × bỏ riêng). Số chip = số trên nút "Bộ lọc (n)".
     *
     * @return array<string, string>
     */
    private function filterChips(array $departmentOptions, array $jobTitleOptions): array
    {
        $statuses = $this->employmentStatuses;
        sort($statuses);
        $defaults = DashboardFilter::DEFAULT_STATUSES;
        sort($defaults);

        return array_filter([
            'departmentId' => $this->departmentId && isset($departmentOptions[$this->departmentId])
                ? 'Đơn vị: ' . trim(ltrim($departmentOptions[$this->departmentId], ' ›')) : null,
            'jobTitleId' => $this->jobTitleId && isset($jobTitleOptions[$this->jobTitleId])
                ? 'Chức danh: ' . $jobTitleOptions[$this->jobTitleId] : null,
            'courseType' => $this->courseType !== DashboardFilter::COURSE_ALL && isset(DashboardFilter::COURSE_TYPES[$this->courseType])
                ? 'Loại khóa: ' . DashboardFilter::COURSE_TYPES[$this->courseType] : null,
            'employmentStatuses' => $statuses !== $defaults
                ? 'Nhân sự: ' . (collect($this->employmentStatuses)->map(fn ($s) => Employee::statusLabel($s))->implode(', ') ?: 'Tất cả') : null,
            'compare' => $this->compare !== DashboardFilter::COMPARE_PREVIOUS && isset(DashboardFilter::COMPARES[$this->compare])
                ? 'So với: ' . DashboardFilter::COMPARES[$this->compare] : null,
        ]);
    }
}
