<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Enrollment;
use App\Models\SecurityAlert;
use App\Models\SupportRequest;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Số liệu trang Tổng quan đào tạo (SCR_001).
 *
 * Mọi phép tính là aggregate ở SQL, gom nhóm theo ngày/đơn vị/khóa trong một
 * query thay vì nạp từng bản ghi — bảng enrollments lớn dần theo số nhân viên ×
 * số khóa. Mọi khối dùng chung một phạm vi (DashboardFilter) để số khớp nhau.
 *
 * Định nghĩa chỉ số (giờ VN, kỳ = [đầu kỳ 00:00, cuối kỳ 23:59]):
 * - Phạm vi ghi danh: lượt chưa hủy, của nhân viên chưa xóa thỏa bộ lọc đơn vị
 *   (gồm phòng con) / chức danh / trạng thái nhân sự, loại khóa theo
 *   enrollments.is_mandatory.
 * - "Trạng thái" một lượt: completed → Hoàn thành; chưa xong mà due_date < hôm nay
 *   → Quá hạn (status 'overdue' trong CSDL không tự cập nhật nên không tin nó);
 *   chưa bắt đầu → Chưa bắt đầu; còn lại → Đang học.
 * - Lượt "trong kỳ" (donut, đơn vị, khóa, bảng nhắc): được giao trước cuối kỳ và
 *   chưa hoàn thành trước đầu kỳ — tức là còn mở ở một thời điểm nào đó trong kỳ.
 */
class DashboardMetricsService
{
    public const STATUS_LABELS = [
        Enrollment::STATUS_COMPLETED => 'Hoàn thành',
        Enrollment::STATUS_IN_PROGRESS => 'Đang học',
        Enrollment::STATUS_NOT_STARTED => 'Chưa bắt đầu',
        Enrollment::STATUS_OVERDUE => 'Quá hạn',
    ];

    public const STATUS_COLORS = [
        Enrollment::STATUS_COMPLETED => '#141821',
        Enrollment::STATUS_IN_PROGRESS => '#6b7280',
        Enrollment::STATUS_NOT_STARTED => '#d1d5db',
        Enrollment::STATUS_OVERDUE => '#c00016',
    ];

    /** Đơn vị / khóa có ít lượt hơn ngưỡng này thì tỷ lệ dễ nhiễu. */
    public const SMALL_SAMPLE = 5;

    /** Khóa cần can thiệp chỉ xét khóa có ít nhất từng này lượt ghi danh. */
    public const COURSE_MIN_ENROLLMENTS = 5;

    // ---- Phạm vi dùng chung -------------------------------------------------

    /** Lượt ghi danh thỏa bộ lọc (chưa giới hạn theo kỳ). Bảng có alias en / e / c. */
    public function enrollmentScope(DashboardFilter $f): Builder
    {
        return $this->applyScope(DB::table('enrollments as en'), $f);
    }

    /**
     * Áp bộ lọc lên query enrollments (alias en) — dùng được cho cả query builder
     * lẫn Eloquent (bảng nhắc học cần model để phân trang + gửi nhắc).
     */
    public function applyScope(Builder|EloquentBuilder $query, DashboardFilter $f): Builder|EloquentBuilder
    {
        $query->join('employees as e', 'e.id', '=', 'en.employee_id')
            ->join('courses as c', 'c.id', '=', 'en.course_id')
            ->whereNull('e.deleted_at')
            ->whereNull('c.deleted_at')
            ->where('en.status', '!=', Enrollment::STATUS_CANCELLED);

        $this->applyEmployeeFilters($query, $f);

        if ($f->courseType === DashboardFilter::COURSE_MANDATORY) {
            $query->where('en.is_mandatory', true);
        } elseif ($f->courseType === DashboardFilter::COURSE_ELECTIVE) {
            $query->where('en.is_mandatory', false);
        }

        return $query;
    }

    /** Lượt còn mở ở một thời điểm trong kỳ: giao trước cuối kỳ, chưa xong trước đầu kỳ. */
    public function applyOpenDuring(Builder|EloquentBuilder $query, ReportPeriod $period): Builder|EloquentBuilder
    {
        return $query
            ->whereRaw('COALESCE(en.assigned_at, en.created_at) <= ?', [$period->to()])
            ->where(fn ($q) => $q->whereNull('en.completed_at')->orWhere('en.completed_at', '>=', $period->from()));
    }

    /** Biểu thức SQL trạng thái hiển thị của một lượt (cần bind ngày hôm nay). */
    public static function statusSql(): string
    {
        return "(CASE
            WHEN en.status = 'completed' THEN 'completed'
            WHEN en.due_date IS NOT NULL AND en.due_date < ? THEN 'overdue'
            WHEN en.status = 'not_started' OR (en.status = 'overdue' AND COALESCE(en.progress_percent, 0) = 0) THEN 'not_started'
            ELSE 'in_progress'
        END)";
    }

    private function applyEmployeeFilters(Builder|EloquentBuilder $query, DashboardFilter $f, string $alias = 'e'): void
    {
        if ($ids = $f->departmentIds()) {
            $query->whereIn($alias . '.department_id', $ids);
        }
        if ($f->jobTitleId) {
            $query->where($alias . '.job_title_id', $f->jobTitleId);
        }
        if ($f->employmentStatuses) {
            $query->whereIn($alias . '.employment_status', $f->employmentStatuses);
        }
    }

    /** Ngày cuối để xét "đến hạn": không vượt quá hôm nay (kỳ tùy chọn có thể kéo vào tương lai). */
    private function dueEnd(ReportPeriod $period): string
    {
        return min($period->dateRange()[1], DashboardFilter::today());
    }

    // ---- KPI ----------------------------------------------------------------

    /**
     * Năm chỉ số của kỳ đang xem + kỳ so sánh + chuỗi theo ngày cho sparkline.
     *
     * @return array<string, array<string, mixed>>
     */
    public function kpis(DashboardFilter $f): array
    {
        $compare = $f->withPeriod($f->comparePeriod());

        $active = $this->activeLearners($f);
        $hours = $this->learningHours($f);

        return [
            'on_time' => ['current' => $this->onTime($f), 'previous' => $this->onTime($compare, false)],
            'active' => ['current' => $active, 'previous' => $this->activeLearners($compare, false)],
            'overdue' => ['current' => $this->overdue($f), 'previous' => $this->overdue($compare, false)],
            'first_pass' => ['current' => $this->firstPass($f), 'previous' => $this->firstPass($compare, false)],
            'hours' => [
                'current' => $hours + ['per_learner' => $active['value'] > 0 ? $hours['value'] / $active['value'] : null],
                'previous' => $this->learningHours($compare, false),
            ],
        ];
    }

    /**
     * Hoàn thành đúng hạn = lượt có hạn (due_date) rơi vào kỳ và đã hoàn thành
     * vào/trước ngày hạn (giờ VN) ÷ tổng lượt có hạn rơi vào kỳ (tới hôm nay).
     * Sparkline: tỷ lệ lũy kế từ đầu kỳ tới từng ngày — tỷ lệ từng ngày quá nhiễu.
     *
     * @return array{value: ?float, on_time: int, due: int, series: array<int, float>}
     */
    public function onTime(DashboardFilter $f, bool $withSeries = true): array
    {
        $start = $f->period->dateRange()[0];
        $end = $this->dueEnd($f->period);

        $rows = $this->enrollmentScope($f)
            ->whereBetween('en.due_date', [$start, $end])
            ->selectRaw('en.due_date as day, COUNT(*) as due')
            ->selectRaw("SUM(CASE WHEN en.status = 'completed' AND en.completed_at IS NOT NULL AND "
                . ReportPeriod::localDate('en.completed_at') . ' <= en.due_date THEN 1 ELSE 0 END) as on_time')
            ->groupBy('en.due_date')
            ->get()
            ->keyBy(fn ($r) => substr((string) $r->day, 0, 10));

        $due = (int) $rows->sum('due');
        $onTime = (int) $rows->sum('on_time');

        $series = [];
        if ($withSeries) {
            [$cumDue, $cumOk] = [0, 0];
            foreach ($f->period->dates() as $day) {
                $cumDue += (int) ($rows[$day]->due ?? 0);
                $cumOk += (int) ($rows[$day]->on_time ?? 0);
                $series[] = $cumDue > 0 ? round($cumOk / $cumDue * 100, 1) : 0;
            }
        }

        return [
            'value' => $due > 0 ? $onTime / $due * 100 : null,
            'on_time' => $onTime,
            'due' => $due,
            'series' => $series,
        ];
    }

    /**
     * Học viên hoạt động = số nhân viên (trong phạm vi lọc) có hoạt động học trong
     * kỳ: mở khóa (enrollments.last_accessed_at), cập nhật tiến độ bài học
     * (lesson_progresses.updated_at) hoặc hoàn thành khóa (completed_at).
     * Tỷ lệ "chiếm z% nhân sự" chia cho số nhân viên thỏa bộ lọc nhân sự.
     *
     * @return array{value: int, headcount: int, series: array<int, int>}
     */
    public function activeLearners(DashboardFilter $f, bool $withSeries = true): array
    {
        $pairs = $this->activityPairs($f);

        $value = (int) DB::query()->fromSub($pairs, 't')->selectRaw('COUNT(DISTINCT employee_id) as n')->value('n');

        $series = [];
        if ($withSeries) {
            $byDay = DB::query()->fromSub($this->activityPairs($f), 't')
                ->selectRaw('day, COUNT(DISTINCT employee_id) as n')
                ->groupBy('day')
                ->pluck('n', 'day');
            $series = $f->period->dates()->map(fn ($d) => (int) ($byDay[$d] ?? 0))->all();
        }

        return [
            'value' => $value,
            'headcount' => $this->headcount($f),
            'series' => $series,
        ];
    }

    /** Cặp (nhân viên, ngày VN) có hoạt động học — UNION đã khử trùng. */
    private function activityPairs(DashboardFilter $f): Builder
    {
        $range = $f->period->range();

        $opened = $this->enrollmentScope($f)
            ->whereBetween('en.last_accessed_at', $range)
            ->selectRaw('en.employee_id, ' . ReportPeriod::localDate('en.last_accessed_at') . ' as day');

        $completed = $this->enrollmentScope($f)
            ->whereBetween('en.completed_at', $range)
            ->selectRaw('en.employee_id, ' . ReportPeriod::localDate('en.completed_at') . ' as day');

        $lessons = $this->enrollmentScope($f)
            ->join('lesson_progresses as lp', 'lp.enrollment_id', '=', 'en.id')
            ->whereBetween('lp.updated_at', $range)
            ->selectRaw('en.employee_id, ' . ReportPeriod::localDate('lp.updated_at') . ' as day');

        return $opened->union($completed)->union($lessons);
    }

    /** Số nhân viên thỏa bộ lọc nhân sự — mẫu số của "chiếm z% nhân sự". */
    public function headcount(DashboardFilter $f): int
    {
        $query = DB::table('employees as e')->whereNull('e.deleted_at');
        $this->applyEmployeeFilters($query, $f);

        return $query->count();
    }

    /**
     * Lượt ghi danh quá hạn tính tại cuối kỳ (ngày D = min(cuối kỳ, hôm nay)):
     * due_date < D và chưa hoàn thành tới hết ngày D. Kỳ kết thúc hôm nay thì đúng
     * bằng số đang quá hạn hiện tại. Sparkline: số quá hạn tại từng ngày trong kỳ.
     *
     * @return array{value: int, employees: int, courses: int, series: array<int, int>}
     */
    public function overdue(DashboardFilter $f, bool $withSeries = true): array
    {
        $asOf = $this->dueEnd($f->period);
        $doneDay = ReportPeriod::localDate('en.completed_at');

        $snapshot = $this->overdueBase($f)
            ->where('en.due_date', '<', $asOf)
            ->where(fn ($q) => $q->whereNull('en.completed_at')->orWhereRaw($doneDay . ' > ?', [$asOf]))
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT en.employee_id) as employees, COUNT(DISTINCT en.course_id) as courses')
            ->first();

        $series = [];
        if ($withSeries) {
            // Gom theo (ngày hạn, ngày hoàn thành) rồi cộng dồn ở PHP: số nhóm nhỏ,
            // tránh chạy một query cho mỗi ngày trong kỳ
            $groups = $this->overdueBase($f)
                ->where('en.due_date', '<', $asOf)
                ->where(fn ($q) => $q->whereNull('en.completed_at')
                    ->orWhereRaw($doneDay . ' > ?', [$f->period->dateRange()[0]]))
                ->selectRaw('en.due_date as due, ' . $doneDay . ' as done, COUNT(*) as n')
                ->groupBy('due', 'done')
                ->get();

            foreach ($f->period->dates() as $day) {
                $series[] = (int) $groups->filter(fn ($g) => substr((string) $g->due, 0, 10) < $day
                    && ($g->done === null || (string) $g->done > $day))->sum('n');
            }
        }

        return [
            'value' => (int) ($snapshot->total ?? 0),
            'employees' => (int) ($snapshot->employees ?? 0),
            'courses' => (int) ($snapshot->courses ?? 0),
            'series' => $series,
        ];
    }

    /** Lượt có hạn, bỏ lượt "hoàn thành" thiếu ngày hoàn thành (không biết xong lúc nào). */
    private function overdueBase(DashboardFilter $f): Builder
    {
        return $this->enrollmentScope($f)
            ->whereNotNull('en.due_date')
            ->where(fn ($q) => $q->where('en.status', '!=', Enrollment::STATUS_COMPLETED)->orWhereNotNull('en.completed_at'));
    }

    /**
     * Đạt kiểm tra lần đầu = lượt thi attempt_no = 1 đã nộp trong kỳ có is_passed
     * ÷ tổng lượt thi lần đầu đã nộp trong kỳ. Điểm trung bình = AVG(percentage)
     * của các lượt đó. Lọc loại khóa theo lượt ghi danh của chính nhân viên đó vào
     * khóa chứa bài kiểm tra. Sparkline: tỷ lệ lũy kế.
     *
     * @return array{value: ?float, passed: int, total: int, avg_score: ?float, series: array<int, float>}
     */
    public function firstPass(DashboardFilter $f, bool $withSeries = true): array
    {
        $day = ReportPeriod::localDate('qa.submitted_at');

        $query = DB::table('quiz_attempts as qa')
            ->join('quizzes as q', 'q.id', '=', 'qa.quiz_id')
            ->join('employees as e', 'e.id', '=', 'qa.employee_id')
            ->whereNull('q.deleted_at')
            ->whereNull('e.deleted_at')
            ->where('qa.attempt_no', 1)
            ->where('qa.status', '!=', 'in_progress')
            ->whereBetween('qa.submitted_at', $f->period->range());

        $this->applyEmployeeFilters($query, $f);

        if ($f->courseType !== DashboardFilter::COURSE_ALL) {
            $query->whereExists(fn ($q) => $q->from('enrollments as en2')
                ->whereColumn('en2.course_id', 'q.course_id')
                ->whereColumn('en2.employee_id', 'qa.employee_id')
                ->where('en2.is_mandatory', $f->courseType === DashboardFilter::COURSE_MANDATORY));
        }

        $rows = $query
            ->selectRaw($day . ' as day, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN qa.is_passed = 1 THEN 1 ELSE 0 END) as passed')
            ->selectRaw('SUM(qa.percentage) as score_sum, COUNT(qa.percentage) as scored')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $total = (int) $rows->sum('total');
        $passed = (int) $rows->sum('passed');
        $scored = (int) $rows->sum('scored');

        $series = [];
        if ($withSeries) {
            [$cumTotal, $cumPassed] = [0, 0];
            foreach ($f->period->dates() as $d) {
                $cumTotal += (int) ($rows[$d]->total ?? 0);
                $cumPassed += (int) ($rows[$d]->passed ?? 0);
                $series[] = $cumTotal > 0 ? round($cumPassed / $cumTotal * 100, 1) : 0;
            }
        }

        return [
            'value' => $total > 0 ? $passed / $total * 100 : null,
            'passed' => $passed,
            'total' => $total,
            'avg_score' => $scored > 0 ? (float) $rows->sum('score_sum') / $scored : null,
            'series' => $series,
        ];
    }

    /**
     * Tổng giờ học = SUM(lesson_progresses.time_spent_seconds) / 3600.
     * Bảng chỉ lưu thời gian cộng dồn của mỗi bài, không có log từng phiên, nên
     * không cắt chính xác theo kỳ được: tính theo updated_at của bản ghi — toàn bộ
     * thời gian của một bài được ghi vào ngày cập nhật cuối cùng.
     *
     * @return array{value: float, series: array<int, float>}
     */
    public function learningHours(DashboardFilter $f, bool $withSeries = true): array
    {
        $byDay = $this->enrollmentScope($f)
            ->join('lesson_progresses as lp', 'lp.enrollment_id', '=', 'en.id')
            ->whereBetween('lp.updated_at', $f->period->range())
            ->selectRaw(ReportPeriod::localDate('lp.updated_at') . ' as day, SUM(lp.time_spent_seconds) as seconds')
            ->groupBy('day')
            ->pluck('seconds', 'day');

        return [
            'value' => (float) $byDay->sum() / 3600,
            'series' => $withSeries
                ? $f->period->dates()->map(fn ($d) => round((float) ($byDay[$d] ?? 0) / 3600, 2))->all()
                : [],
        ];
    }

    // ---- Việc cần xử lý -----------------------------------------------------

    /**
     * Việc vận hành toàn hệ thống (không theo bộ lọc đào tạo).
     *
     * @return array{open_alerts: int, over_sla: int}
     */
    public function actionItems(): array
    {
        return [
            'open_alerts' => SecurityAlert::query()
                ->whereIn('status', [SecurityAlert::STATUS_OPEN, SecurityAlert::STATUS_ACKNOWLEDGED])
                ->count(),
            'over_sla' => SupportRequest::overSla()->count(),
        ];
    }

    // ---- Biểu đồ ------------------------------------------------------------

    /**
     * Xu hướng theo ngày: lượt hoàn thành, học viên hoạt động, lượt hoàn thành kỳ
     * so sánh (căn theo thứ tự ngày trong kỳ).
     *
     * @return array{labels: array<int, string>, completions: array<int, int>, active: array<int, int>, previous: array<int, ?int>}
     */
    public function trend(DashboardFilter $f, array $activeSeries): array
    {
        $current = $this->completionsByDay($f->period, $f);
        $comparePeriod = $f->comparePeriod();
        $previous = $this->completionsByDay($comparePeriod, $f);

        $prevDates = $comparePeriod->dates()->values();

        return [
            'labels' => $f->period->dates()->map(fn ($d) => substr($d, 8, 2) . '/' . substr($d, 5, 2))->all(),
            'completions' => $f->period->dates()->map(fn ($d) => (int) ($current[$d] ?? 0))->all(),
            'active' => $activeSeries,
            'previous' => $f->period->dates()->values()->map(fn ($d, $i) => isset($prevDates[$i])
                ? (int) ($previous[$prevDates[$i]] ?? 0) : null)->all(),
        ];
    }

    /** @return Collection<string, int> ngày VN => lượt hoàn thành */
    private function completionsByDay(ReportPeriod $period, DashboardFilter $f): Collection
    {
        return $this->enrollmentScope($f)
            ->where('en.status', Enrollment::STATUS_COMPLETED)
            ->whereBetween('en.completed_at', $period->range())
            ->selectRaw(ReportPeriod::localDate('en.completed_at') . ' as day, COUNT(*) as n')
            ->groupBy('day')
            ->pluck('n', 'day');
    }

    /**
     * Phân bố trạng thái các lượt còn mở trong kỳ.
     *
     * @return array<string, int> status => số lượt, theo thứ tự STATUS_LABELS
     */
    public function statusBreakdown(DashboardFilter $f): array
    {
        $counts = $this->applyOpenDuring($this->enrollmentScope($f), $f->period)
            ->selectRaw(self::statusSql() . ' as st, COUNT(*) as n', [DashboardFilter::today()])
            ->groupBy('st')
            ->pluck('n', 'st');

        return collect(self::STATUS_LABELS)->map(fn ($label, $key) => (int) ($counts[$key] ?? 0))->all();
    }

    /**
     * Tiến độ theo đơn vị (phòng ban trực tiếp của nhân viên): số lượt theo trạng
     * thái + tỷ lệ hoàn thành. Đơn vị chậm nhất lên trên; đơn vị mẫu nhỏ xuống cuối.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, overall: ?float}
     */
    public function departmentProgress(DashboardFilter $f): array
    {
        $rows = $this->applyOpenDuring($this->enrollmentScope($f), $f->period)
            ->whereNotNull('e.department_id')
            ->selectRaw('e.department_id, ' . self::statusSql() . ' as st, COUNT(*) as n', [DashboardFilter::today()])
            ->groupBy('e.department_id', 'st')
            ->get();

        $departments = Department::query()->with('parent:id,name')->get(['id', 'name', 'parent_id'])->keyBy('id');

        $units = $rows->groupBy(fn ($r) => (int) $r->department_id)->map(function ($group, $deptId) use ($departments) {
            $counts = collect(self::STATUS_LABELS)->map(fn ($l, $key) => (int) $group->where('st', $key)->sum('n'))->all();
            $total = array_sum($counts);

            return [
                'id' => $deptId,
                'name' => $departments[$deptId]->name ?? 'Chưa gán đơn vị',
                'counts' => $counts,
                'total' => $total,
                'rate' => $total > 0 ? $counts[Enrollment::STATUS_COMPLETED] / $total * 100 : 0,
                'small' => $total < self::SMALL_SAMPLE,
            ];
        });

        $all = $units->sum('total');

        return [
            'rows' => $units->sortBy([['small', 'asc'], ['rate', 'asc'], ['name', 'asc']])->values(),
            'overall' => $all > 0 ? $units->sum(fn ($u) => $u['counts'][Enrollment::STATUS_COMPLETED]) / $all * 100 : null,
        ];
    }

    /**
     * Khóa cần can thiệp: khóa có ≥ COURSE_MIN_ENROLLMENTS lượt trong kỳ và có lượt
     * quá hạn, xếp theo số lượt quá hạn giảm dần. Tiến độ = trung bình % tiến độ
     * (lượt hoàn thành tính 100%).
     *
     * @return Collection<int, object>
     */
    public function coursesNeedingAction(DashboardFilter $f): Collection
    {
        $today = DashboardFilter::today();

        return $this->applyOpenDuring($this->enrollmentScope($f), $f->period)
            ->groupBy('c.id', 'c.title', 'c.is_onboarding')
            ->select('c.id', 'c.title', 'c.is_onboarding')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("AVG(CASE WHEN en.status = 'completed' THEN 100 ELSE COALESCE(en.progress_percent, 0) END) as avg_progress")
            ->selectRaw("SUM(CASE WHEN en.status != 'completed' AND en.due_date IS NOT NULL AND en.due_date < ? THEN 1 ELSE 0 END) as overdue", [$today])
            ->selectRaw('SUM(CASE WHEN en.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory')
            ->havingRaw('COUNT(*) >= ?', [self::COURSE_MIN_ENROLLMENTS])
            ->havingRaw("SUM(CASE WHEN en.status != 'completed' AND en.due_date IS NOT NULL AND en.due_date < ? THEN 1 ELSE 0 END) > 0", [$today])
            ->orderByDesc('overdue')
            ->orderBy('avg_progress')
            ->get();
    }

    // ---- Bảng nhân viên cần nhắc --------------------------------------------

    /**
     * Lượt ghi danh cần nhắc: cùng phạm vi với donut, mặc định mọi lượt chưa hoàn
     * thành; chọn lát donut thì chỉ lấy trạng thái đó. Quá hạn lâu nhất lên đầu.
     */
    public function reminderQuery(DashboardFilter $f, ?string $status): EloquentBuilder
    {
        $query = Enrollment::query()->from('enrollments as en')->select('en.*');
        $this->applyScope($query, $f);
        $this->applyOpenDuring($query, $f->period);

        if ($status && isset(self::STATUS_LABELS[$status])) {
            $query->whereRaw(self::statusSql() . ' = ?', [DashboardFilter::today(), $status]);
        } else {
            $query->where('en.status', '!=', Enrollment::STATUS_COMPLETED);
        }

        return $query
            ->with(['employee:id,employee_code,full_name,department_id', 'employee.department:id,name,parent_id',
                'employee.department.parent:id,name', 'course:id,title'])
            ->orderByRaw('en.due_date IS NULL')
            ->orderBy('en.due_date')
            ->orderBy('e.employee_code');
    }
}
