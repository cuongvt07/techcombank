<?php

namespace App\Services\Reports;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Số liệu màn "Báo cáo tiến độ học tập" (SCR_002).
 *
 * Một dòng = một lượt ghi danh (nhân viên × khóa học). Mọi con số tính bằng SQL
 * gom nhóm / phân trang ở CSDL — trang có thể có vài nghìn lượt ghi danh nên
 * không nạp cả tập vào PHP.
 *
 * Hai tầng bộ lọc:
 *  - "nền" (mốc thời gian + kỳ, đơn vị, khóa, bộ lọc nâng cao): áp cho KPI, biểu đồ và bảng;
 *  - "bảng" (trạng thái, ngày hoàn thành bấm từ biểu đồ, ô tìm): chỉ áp cho bảng,
 *    để bấm thẻ KPI / cột biểu đồ không làm chính KPI và biểu đồ co lại.
 *
 * Lượt ghi danh đã hủy không thuộc nghĩa vụ học nên bị loại khỏi báo cáo.
 */
class LearningProgressReportService
{
    /** "Sắp đến hạn" = còn tối đa ngần này ngày tới hạn. */
    public const DUE_SOON_DAYS = 7;

    /** Mục tiêu tỷ lệ hoàn thành (đúng hạn / lũy kế), vẽ vạch đỏ trên biểu đồ. */
    public const TARGET_PERCENT = 90.0;

    public const BASIS_LABELS = [
        'due' => 'Hạn hoàn thành',
        'assigned' => 'Ngày giao',
        'completed' => 'Ngày hoàn thành',
    ];

    public const STATE_LABELS = [
        'completed' => 'Hoàn thành',
        'in_progress' => 'Đang học',
        'not_started' => 'Chưa bắt đầu',
        'overdue' => 'Quá hạn',
    ];

    public const STATUS_FILTERS = [
        '' => 'Tất cả',
        'attention' => 'Quá hạn + Sắp đến hạn',
        'overdue' => 'Quá hạn',
        'due_soon' => 'Sắp đến hạn (≤ 7 ngày)',
        'in_progress' => 'Đang học',
        'not_started' => 'Chưa bắt đầu',
        'completed' => 'Hoàn thành',
    ];

    /** Cột sắp xếp được → biểu thức SQL. */
    private const SORTS = [
        'code' => 'emp.employee_code',
        'name' => 'emp.full_name',
        'department' => 'department_name',
        'job_title' => 'job_title_name',
        'course' => 'c.title',
        'type' => 'c.is_onboarding',
        'source' => 'e.course_assignment_rule_id',
        'assigned' => 'e.assigned_at',
        'due' => 'e.due_date',
        'overdue_days' => 'e.due_date',
        'completed' => 'e.completed_at',
        'lessons' => 'e.completed_lessons',
        'progress' => 'e.progress_percent',
        'hours' => 'time_spent',
        'score' => 'e.final_score',
        'status' => 'state',
        'reminded' => 'e.last_reminded_at',
    ];

    /** Ngày hôm nay theo giờ VN — mốc quá hạn tính bằng ngày VN, không dùng CURDATE() của MySQL. */
    public function today(): string
    {
        return CarbonImmutable::now(ReportPeriod::TIMEZONE)->toDateString();
    }

    /**
     * Biểu thức trạng thái hiệu lực, cần 1 binding (hôm nay).
     *
     * Cột status trong CSDL không tự chuyển sang overdue khi qua hạn, nên "quá hạn"
     * tính từ due_date. Bốn trạng thái loại trừ nhau → cộng lại bằng tổng lượt.
     */
    public function stateSql(): string
    {
        return "CASE WHEN e.status = 'completed' THEN 'completed'"
            . ' WHEN e.due_date IS NOT NULL AND e.due_date < ? THEN \'overdue\''
            . " WHEN e.status = 'in_progress' OR e.progress_percent > 0 THEN 'in_progress'"
            . " ELSE 'not_started' END";
    }

    /** Giữ lại các khóa bộ lọc hợp lệ, điền mặc định cho khóa thiếu. */
    public function normalize(array $filters): array
    {
        return array_merge([
            'basis' => 'due',
            'department' => null,
            'course' => null,
            'status' => '',
            'courseType' => '',
            'progressMin' => null,
            'progressMax' => null,
            'source' => '',
            'excludeResigned' => false,
            'completedOn' => null,
            'search' => '',
        ], $filters);
    }

    /** Tập nền: áp cho KPI, biểu đồ và bảng. */
    public function baseQuery(array $filters, ReportPeriod $period): Builder
    {
        $f = $this->normalize($filters);

        $query = Enrollment::query()
            ->from('enrollments as e')
            ->join('employees as emp', 'emp.id', '=', 'e.employee_id')
            ->join('courses as c', 'c.id', '=', 'e.course_id')
            ->whereNull('c.deleted_at')
            ->whereNull('emp.deleted_at')
            ->where('e.status', '!=', Enrollment::STATUS_CANCELLED);

        match ($f['basis']) {
            'assigned' => $query->whereBetween('e.assigned_at', $period->range()),
            'completed' => $query->whereBetween('e.completed_at', $period->range()),
            default => $query->whereBetween('e.due_date', $period->dateRange()),
        };

        if ($f['department']) {
            $department = Department::find((int) $f['department']);
            $query->whereIn('emp.department_id', $department ? $department->descendantIds() : [(int) $f['department']]);
        }

        if ($f['course']) {
            $query->where('e.course_id', (int) $f['course']);
        }

        if ($f['courseType'] === 'mandatory') {
            $query->where('e.is_mandatory', true);
        } elseif ($f['courseType'] === 'elective') {
            $query->where('e.is_mandatory', false);
        }

        if ($f['progressMin'] !== null && $f['progressMin'] !== '') {
            $query->where('e.progress_percent', '>=', (int) $f['progressMin']);
        }
        if ($f['progressMax'] !== null && $f['progressMax'] !== '') {
            $query->where('e.progress_percent', '<=', (int) $f['progressMax']);
        }

        if ($f['source'] === 'rule') {
            $query->whereNotNull('e.course_assignment_rule_id');
        } elseif ($f['source'] === 'manual') {
            $query->whereNull('e.course_assignment_rule_id');
        }

        if ($f['excludeResigned']) {
            $query->where('emp.employment_status', '!=', Employee::STATUS_RESIGNED);
        }

        return $query;
    }

    /** Tập của bảng = tập nền + trạng thái + ngày hoàn thành (bấm từ biểu đồ) + ô tìm. */
    public function tableQuery(array $filters, ReportPeriod $period): Builder
    {
        $f = $this->normalize($filters);
        $query = $this->baseQuery($f, $period)
            ->leftJoin('departments as d', 'd.id', '=', 'emp.department_id');

        $today = $this->today();
        $soon = CarbonImmutable::parse($today)->addDays(self::DUE_SOON_DAYS)->toDateString();
        $state = '(' . $this->stateSql() . ')';
        $dueSoon = "(e.status != 'completed' AND e.due_date BETWEEN ? AND ?)";

        match ($f['status']) {
            'attention' => $query->where(fn ($q) => $q->whereRaw("{$state} = 'overdue'", [$today])->orWhereRaw($dueSoon, [$today, $soon])),
            'due_soon' => $query->whereRaw($dueSoon, [$today, $soon]),
            'overdue', 'in_progress', 'not_started', 'completed' => $query->whereRaw("{$state} = ?", [$today, $f['status']]),
            default => null,
        };

        if ($f['completedOn']) {
            $query->whereRaw(ReportPeriod::localDate('e.completed_at') . ' = ?', [$f['completedOn']]);
        }

        $term = trim((string) $f['search']);
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->where(fn ($q) => $q->where('emp.employee_code', 'like', $like)
                ->orWhere('emp.full_name', 'like', $like)
                ->orWhere('d.name', 'like', $like)
                ->orWhere('c.title', 'like', $like));
        }

        return $query;
    }

    /**
     * Sáu thẻ KPI, một query gom nhóm trên tập nền.
     *
     * "Hoàn thành đúng hạn" = lượt xong trước/đúng hạn ÷ lượt đã có kết quả so với hạn
     * (đã hoàn thành, hoặc đã qua hạn mà chưa xong). Lượt chưa tới hạn và chưa xong
     * chưa thể nói đúng hay trễ nên không tính.
     */
    public function kpis(array $filters, ReportPeriod $period): array
    {
        $today = $this->today();
        $completedLocal = ReportPeriod::localDate('x.completed_at');

        $inner = $this->baseQuery($filters, $period)
            ->toBase()
            ->selectRaw('e.employee_id, e.due_date, e.completed_at, (' . $this->stateSql() . ') as state', [$today]);

        $row = DB::query()->fromSub($inner, 'x')->selectRaw(
            "COUNT(*) as total,
             SUM(x.state = 'completed') as completed,
             SUM(x.state = 'in_progress') as in_progress,
             SUM(x.state = 'not_started') as not_started,
             SUM(x.state = 'overdue') as overdue,
             COUNT(DISTINCT CASE WHEN x.state = 'overdue' THEN x.employee_id END) as overdue_employees,
             SUM(x.due_date IS NOT NULL AND (x.state = 'overdue' OR x.state = 'completed')) as judged,
             SUM(x.state = 'completed' AND x.due_date IS NOT NULL AND {$completedLocal} <= x.due_date) as on_time"
        )->first();

        $total = (int) $row->total;
        $pct = fn ($n) => $total > 0 ? round($n / $total * 100, 1) : null;
        $judged = (int) $row->judged;

        return [
            'total' => $total,
            'completed' => (int) $row->completed,
            'completed_pct' => $pct((int) $row->completed),
            'in_progress' => (int) $row->in_progress,
            'in_progress_pct' => $pct((int) $row->in_progress),
            'not_started' => (int) $row->not_started,
            'not_started_pct' => $pct((int) $row->not_started),
            'overdue' => (int) $row->overdue,
            'overdue_pct' => $pct((int) $row->overdue),
            'overdue_employees' => (int) $row->overdue_employees,
            'on_time' => (int) $row->on_time,
            'judged' => $judged,
            'on_time_pct' => $judged > 0 ? round($row->on_time / $judged * 100, 1) : null,
        ];
    }

    /**
     * Biểu đồ: lượt hoàn thành mỗi ngày trong kỳ + tỷ lệ hoàn thành lũy kế.
     *
     * Lũy kế tại ngày D = số lượt (trong tập nền) đã hoàn thành tới hết ngày D ÷ tổng lượt
     * của tập nền — gồm cả lượt hoàn thành trước đầu kỳ, nên đường không bắt đầu từ 0 giả tạo.
     */
    public function chart(array $filters, ReportPeriod $period, int $total): array
    {
        $day = ReportPeriod::localDate('e.completed_at');

        $perDay = $this->baseQuery($filters, $period)
            ->toBase()
            ->where('e.status', Enrollment::STATUS_COMPLETED)
            ->whereNotNull('e.completed_at')
            ->where('e.completed_at', '<=', $period->to())
            ->selectRaw("{$day} as d, COUNT(*) as n")
            ->groupByRaw($day)
            ->pluck('n', 'd')
            ->map(fn ($n) => (int) $n);

        $dates = $period->dates();
        $start = $dates->first();
        $running = $perDay->filter(fn ($n, $d) => $d < $start)->sum();

        $bars = [];
        $line = [];
        foreach ($dates as $date) {
            $count = $perDay[$date] ?? 0;
            $running += $count;
            $bars[] = $count;
            $line[] = $total > 0 ? round($running / $total * 100, 1) : null;
        }

        return [
            'dates' => $dates->all(),
            'labels' => $dates->map(fn ($d) => CarbonImmutable::parse($d)->format('d/m'))->all(),
            'bars' => $bars,
            'line' => $line,
            'completions' => array_sum($bars),
        ];
    }

    /** Một trang của bảng, phân trang ở SQL. */
    public function rows(array $filters, ReportPeriod $period, string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        return $this->sorted($this->selectRows($this->tableQuery($filters, $period)), $sort, $dir)
            ->paginate($perPage);
    }

    /** Toàn bộ dòng khớp bộ lọc cho file xuất — đọc dần bằng cursor, không giữ cả tập trong bộ nhớ. */
    public function exportCursor(array $filters, ReportPeriod $period, string $sort, string $dir): LazyCollection
    {
        return $this->sorted($this->selectRows($this->tableQuery($filters, $period)), $sort, $dir)->cursor();
    }

    /** Dòng "Tổng cộng" cuối bảng. */
    public function totals(array $filters, ReportPeriod $period): array
    {
        $today = $this->today();
        $inner = $this->tableQuery($filters, $period)->toBase()
            ->selectRaw('e.id, e.completed_lessons, e.total_lessons, e.progress_percent, e.due_date, (' . $this->stateSql() . ') as state', [$today]);

        $row = DB::query()->fromSub($inner, 'x')->selectRaw(
            "COUNT(*) as total,
             COALESCE(SUM(x.completed_lessons), 0) as lessons_done,
             COALESCE(SUM(x.total_lessons), 0) as lessons_total,
             AVG(x.progress_percent) as avg_progress,
             SUM(x.state = 'completed') as completed,
             SUM(x.state = 'overdue') as overdue"
        )->first();

        $ids = $this->tableQuery($filters, $period)->toBase()->select('e.id');
        $seconds = (int) DB::table('lesson_progresses')->whereIn('enrollment_id', $ids)->sum('time_spent_seconds');

        return [
            'total' => (int) $row->total,
            'lessons_done' => (int) $row->lessons_done,
            'lessons_total' => (int) $row->lessons_total,
            'avg_progress' => $row->avg_progress !== null ? round((float) $row->avg_progress, 1) : null,
            'unfinished' => (int) $row->total - (int) $row->completed,
            'overdue' => (int) $row->overdue,
            'hours' => round($seconds / 3600, 1),
        ];
    }

    /** Các lượt chưa hoàn thành của bảng — đích của "Gửi nhắc hàng loạt" khi không chọn dòng nào. */
    public function unfinishedQuery(array $filters, ReportPeriod $period): Builder
    {
        return $this->tableQuery($filters, $period)
            ->where('e.status', '!=', Enrollment::STATUS_COMPLETED)
            ->select('e.*');
    }

    /** Danh sách đơn vị dạng cây (thụt lề theo cấp) cho ô chọn Đơn vị. */
    public function departmentOptions(): array
    {
        $all = Department::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'parent_id']);
        $byParent = $all->groupBy(fn ($d) => (int) $d->parent_id);
        $ids = $all->pluck('id')->all();
        $options = [];

        $walk = function (int $parentId, int $depth) use (&$walk, &$options, $byParent) {
            foreach ($byParent[$parentId] ?? [] as $dept) {
                $options[$dept->id] = str_repeat('— ', $depth) . $dept->name;
                $walk($dept->id, $depth + 1);
            }
        };
        $walk(0, 0);

        // Phòng có cha đã ngừng hoạt động: vẫn liệt kê để lọc được
        foreach ($all as $dept) {
            if (! isset($options[$dept->id]) && ! in_array((int) $dept->parent_id, $ids, true)) {
                $options[$dept->id] = $dept->name;
                $walk($dept->id, 1);
            }
        }

        return $options;
    }

    /** Khóa học đang có lượt ghi danh (bỏ khóa đã xóa). */
    public function courseOptions(): array
    {
        return DB::table('courses')
            ->whereNull('deleted_at')
            ->whereExists(fn ($q) => $q->from('enrollments')->whereColumn('enrollments.course_id', 'courses.id'))
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    private function selectRows(Builder $query): Builder
    {
        $today = $this->today();
        $soon = CarbonImmutable::parse($today)->addDays(self::DUE_SOON_DAYS)->toDateString();

        return $query
            ->leftJoin('job_titles as jt', 'jt.id', '=', 'emp.job_title_id')
            ->select('e.*')
            ->addSelect([
                'emp.employee_code', 'emp.full_name', 'emp.employment_status',
                'd.name as department_name', 'jt.name as job_title_name',
                'c.title as course_title', 'c.is_onboarding',
            ])
            ->selectRaw('(' . $this->stateSql() . ') as state', [$today])
            ->selectRaw("(e.status != 'completed' AND e.due_date BETWEEN ? AND ?) as due_soon", [$today, $soon])
            ->selectRaw('(SELECT COALESCE(SUM(lp.time_spent_seconds), 0) FROM lesson_progresses lp WHERE lp.enrollment_id = e.id) as time_spent');
    }

    private function sorted(Builder $query, string $sort, string $dir): Builder
    {
        $column = self::SORTS[$sort] ?? self::SORTS['due'];
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        // Lượt không có hạn / chưa hoàn thành / chưa nhắc luôn nằm cuối, cả khi đảo chiều
        if (str_starts_with($column, 'e.') && in_array($sort, ['due', 'overdue_days', 'completed', 'reminded', 'score', 'assigned'], true)) {
            $query->orderByRaw("{$column} IS NULL");
        }

        // "Quá hạn (ngày)" giảm dần = hạn cũ nhất trước
        if ($sort === 'overdue_days') {
            $dir = $dir === 'asc' ? 'desc' : 'asc';
        }

        return $query->orderBy($column, $dir)->orderBy('e.id');
    }

    /** Có đúng một khóa sắp xếp hợp lệ không. */
    public static function sortable(string $sort): bool
    {
        return array_key_exists($sort, self::SORTS);
    }
}
