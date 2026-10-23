<?php

namespace App\Services\Reports;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttemptGrant;
use App\Services\NotificationCenter;
use App\Services\LearningReminderService;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Số liệu báo cáo Kết quả kiểm tra (SCR_003).
 *
 * Chỉ tính lượt đã nộp (submitted/graded) có submitted_at trong kỳ. "Đạt" so
 * percentage với quizzes.pass_score hiện tại — không đọc cột is_passed, để đổi
 * điểm đạt thì toàn bộ báo cáo đổi theo cùng một chuẩn.
 *
 * Bộ lọc truyền vào dạng mảng:
 *   course_id, quiz_id, department_id (tab nhân viên), result (passed|retry|exhausted),
 *   search, attempt (first|last), attempt_result (passed|failed), band (0–9, khoảng điểm).
 */
class QuizResultReportService
{
    public const RESULT_PASSED = 'passed';
    public const RESULT_RETRY = 'retry';
    public const RESULT_EXHAUSTED = 'exhausted';

    public const RESULT_LABELS = [
        self::RESULT_PASSED => 'Đạt',
        self::RESULT_RETRY => 'Chưa đạt còn lượt',
        self::RESULT_EXHAUSTED => 'Hết lượt chưa đạt',
    ];

    /** Đơn vị có tỷ lệ NV đạt dưới ngưỡng này bị tô đỏ. */
    public const DEPARTMENT_ALERT_RATE = 85.0;

    /** Bài kiểm tra có tỷ lệ lượt đạt dưới ngưỡng này bị tô đỏ. */
    public const QUIZ_ALERT_RATE = 70.0;

    /*
     * Cờ chất lượng câu hỏi (theo % trả lời đúng):
     *   < 20 lần ra          → "Chưa đủ mẫu" (tỷ lệ chưa đáng tin, không gắn cờ khác)
     *   < 35% đúng           → "Quá khó" (đáp án/nội dung giảng có thể sai lệch)
     *   35–50% đúng          → "Rà soát"
     *   câu nhiều lựa chọn < 50% → "Rà soát" thay vì "Quá khó": chấm trọn bộ nên
     *                          tỷ lệ thấp thường do cách hỏi, cần đọc lại trước khi kết luận
     *   > 95% đúng           → "Quá dễ" (không phân loại được người học)
     */
    public const MIN_SAMPLE = 20;
    public const TOO_HARD_BELOW = 35.0;
    public const REVIEW_BELOW = 50.0;
    public const TOO_EASY_ABOVE = 95.0;

    /** Điều kiện đạt của một lượt — dùng chung mọi truy vấn. */
    private const PASS_SQL = 'a.percentage >= q.pass_score';

    /** Khoảng điểm 0–9 (mỗi khoảng 10 điểm, 100 gộp vào khoảng cuối). */
    private const BAND_SQL = 'LEAST(FLOOR(COALESCE(a.percentage, 0) / 10), 9)';

    // ---- Tab "Kết quả theo nhân viên" ---------------------------------------

    /**
     * Một dòng = một nhân viên × một bài kiểm tra, gộp mọi lượt nộp trong kỳ.
     *
     * Số lượt đã dùng / tối đa tính trên MỌI lượt (cả lượt ngoài kỳ, đang làm)
     * và cộng lượt cấp thêm — khớp đúng Quiz::canAttempt(), nên "Hết lượt" trên
     * báo cáo nghĩa là nhân viên thực sự không bắt đầu được lượt mới.
     */
    public function pairRows(array $f, ReportPeriod $period, bool $withDepartment = true): Builder
    {
        $agg = $this->submitted($f, $period)
            ->groupBy('a.employee_id', 'a.quiz_id')
            ->selectRaw('a.employee_id, a.quiz_id, COUNT(*) AS submitted_count, MIN(a.attempt_no) AS first_no, '
                . 'MAX(a.attempt_no) AS last_no, MAX(a.percentage) AS best_pct, '
                . 'MIN(CASE WHEN ' . self::PASS_SQL . ' THEN a.attempt_no END) AS pass_no');

        $deptIds = $withDepartment ? $this->departmentIds($f['department_id'] ?? null) : null;
        $sameAttempt = fn (string $alias, string $noColumn) => function ($join) use ($alias, $noColumn) {
            $join->on("{$alias}.quiz_id", '=', 'g.quiz_id')
                ->on("{$alias}.employee_id", '=', 'g.employee_id')
                ->on("{$alias}.attempt_no", '=', "g.{$noColumn}");
        };

        $pairs = DB::query()->fromSub($agg, 'g')
            ->join('quizzes as q', 'q.id', '=', 'g.quiz_id')
            ->join('employees as e', 'e.id', '=', 'g.employee_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->join('quiz_attempts as fa', $sameAttempt('fa', 'first_no'))
            ->join('quiz_attempts as la', $sameAttempt('la', 'last_no'))
            ->leftJoin('quiz_attempts as pa', $sameAttempt('pa', 'pass_no'))
            ->whereNull('e.deleted_at')
            ->when($deptIds, fn ($q) => $q->whereIn('e.department_id', $deptIds))
            ->selectRaw('g.*, fa.percentage AS first_pct, la.percentage AS last_pct, pa.submitted_at AS passed_at, '
                . 'e.employee_code, e.full_name, e.department_id, d.name AS department_name, '
                . 'q.title AS quiz_title, q.pass_score, q.max_attempts')
            ->selectRaw('(SELECT COUNT(*) FROM quiz_attempts u WHERE u.quiz_id = g.quiz_id AND u.employee_id = g.employee_id) AS used')
            ->selectRaw('CASE WHEN q.max_attempts IS NULL THEN NULL ELSE q.max_attempts + '
                . '(SELECT COALESCE(SUM(gr.extra_attempts), 0) FROM quiz_attempt_grants gr '
                . 'WHERE gr.quiz_id = g.quiz_id AND gr.employee_id = g.employee_id) END AS allowed');

        $classified = DB::query()->fromSub($pairs, 'p')
            ->selectRaw("p.*, CASE WHEN p.pass_no IS NOT NULL THEN 'passed' "
                . "WHEN p.allowed IS NOT NULL AND p.used >= p.allowed THEN 'exhausted' ELSE 'retry' END AS final_result, "
                . 'CASE WHEN p.allowed IS NULL THEN NULL ELSE GREATEST(CAST(p.allowed AS SIGNED) - CAST(p.used AS SIGNED), 0) END AS remaining');

        return DB::query()->fromSub($classified, 'r');
    }

    /**
     * KPI + phân bố "lượt đến khi đạt" của tab nhân viên, một truy vấn.
     *
     * @return array<string, int|float|null>
     */
    public function pairKpis(array $f, ReportPeriod $period, bool $withDepartment = true): array
    {
        $row = $this->pairRows($f, $period, $withDepartment)
            ->selectRaw("COUNT(*) AS total, COUNT(DISTINCT employee_id) AS employees, "
                . "COALESCE(SUM(final_result = 'passed'), 0) AS passed, "
                . "COALESCE(SUM(final_result = 'retry'), 0) AS retry, "
                . "COALESCE(SUM(final_result = 'exhausted'), 0) AS exhausted, "
                . 'COALESCE(SUM(pass_no = 1), 0) AS pass1, COALESCE(SUM(pass_no = 2), 0) AS pass2, '
                . 'COALESCE(SUM(pass_no >= 3), 0) AS pass3, AVG(pass_no) AS avg_pass_no')
            ->first();

        $total = (int) $row->total;
        $kpi = [
            'total' => $total,
            'employees' => (int) $row->employees,
            'passed' => (int) $row->passed,
            'retry' => (int) $row->retry,
            'exhausted' => (int) $row->exhausted,
            'pass1' => (int) $row->pass1,
            'pass2' => (int) $row->pass2,
            'pass3' => (int) $row->pass3,
            'avg_pass_no' => $row->avg_pass_no !== null ? (float) $row->avg_pass_no : null,
            'passed_rate' => $this->rate((int) $row->passed, $total),
            'first_pass_rate' => $this->rate((int) $row->pass1, $total),
        ];

        // Đạt lần đầu so với kỳ trước cùng độ dài, cùng bộ lọc
        $prev = $this->pairRows($f, $period->previous(), $withDepartment)
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(pass_no = 1), 0) AS pass1')
            ->first();
        $kpi['prev_first_pass_rate'] = $this->rate((int) $prev->pass1, (int) $prev->total);

        return $kpi;
    }

    /**
     * Tỷ lệ dòng (NV × bài) đã đạt theo phòng ban của nhân viên — thấp nhất trước.
     *
     * @return Collection<int, object>
     */
    public function passRateByDepartment(array $f, ReportPeriod $period): Collection
    {
        return $this->pairRows($f, $period)
            ->groupBy('department_id', 'department_name')
            ->selectRaw("department_id, department_name, COUNT(*) AS total, COUNT(DISTINCT employee_id) AS people, "
                . "COALESCE(SUM(final_result = 'passed'), 0) AS passed")
            ->get()
            ->map(function ($r) {
                $r->rate = $this->rate((int) $r->passed, (int) $r->total);

                return $r;
            })
            ->sortBy([['rate', 'asc'], ['department_name', 'asc']])
            ->values();
    }

    /** Bảng nhân viên: lọc kết quả cuối + tìm kiếm + sắp xếp. Mặc định nhóm hết lượt lên đầu. */
    public function employeeTable(array $f, ReportPeriod $period, string $sort = '', string $dir = 'asc'): Builder
    {
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        $query = $this->pairRows($f, $period)
            ->when(isset(self::RESULT_LABELS[$f['result'] ?? '']), fn ($q) => $q->where('final_result', $f['result']))
            ->when(trim($f['search'] ?? '') !== '', function ($q) use ($f) {
                $term = '%' . trim($f['search']) . '%';
                $q->where(fn ($w) => $w->where('employee_code', 'like', $term)
                    ->orWhere('full_name', 'like', $term)
                    ->orWhere('department_name', 'like', $term)
                    ->orWhere('quiz_title', 'like', $term));
            });

        $columns = [
            'code' => 'employee_code', 'name' => 'full_name', 'department' => 'department_name',
            'quiz' => 'quiz_title', 'attempts' => 'used', 'first' => 'first_pct', 'best' => 'best_pct',
            'last' => 'last_pct', 'pass_no' => 'pass_no', 'passed_at' => 'passed_at',
        ];

        if (isset($columns[$sort])) {
            // Ô trống (chưa đạt) luôn nằm cuối dù sắp xếp chiều nào
            $col = $columns[$sort];
            $query->orderByRaw("{$col} IS NULL")->orderBy($col, $dir);
        } elseif ($sort === 'remaining' && $dir === 'desc') {
            $query->orderByRaw('remaining IS NULL DESC')->orderByDesc('remaining');
        } else {
            // Hết lượt → chưa đạt còn lượt → đạt; trong nhóm, ít lượt còn lại lên trước
            $query->orderByRaw("FIELD(final_result, 'exhausted', 'retry', 'passed')")
                ->orderByRaw('remaining IS NULL')
                ->orderBy('remaining');
        }

        return $query->orderBy('full_name')->orderBy('quiz_title');
    }

    /**
     * Đếm trạng thái của các dòng đang chọn (để thanh hành động nói rõ chọn gì).
     *
     * @param  array<int, array{0: int, 1: int}>  $pairs  [employee_id, quiz_id]
     * @return array<string, int>
     */
    public function selectionSummary(array $pairs, array $f, ReportPeriod $period): array
    {
        if ($pairs === []) {
            return [];
        }

        return $this->pairRows($f, $period)
            ->where(fn ($q) => $this->wherePairs($q, $pairs))
            ->groupBy('final_result')
            ->selectRaw('final_result, COUNT(*) AS c')
            ->pluck('c', 'final_result')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Cấp thêm lượt làm cho các dòng hết lượt và chưa đạt. Dòng đã đạt hoặc còn lượt bị bỏ qua.
     *
     * @param  array<int, array{0: int, 1: int}>  $pairs
     * @return array{granted: int, skipped: int}
     */
    public function grantExtraAttempts(array $pairs, ?int $byUserId, int $extra = 1, string $reason = 'Cấp từ báo cáo kết quả kiểm tra'): array
    {
        [$quizzes, $employees, $passed] = $this->loadPairs($pairs);
        $granted = 0;

        DB::transaction(function () use ($pairs, $quizzes, $employees, $passed, $byUserId, $extra, $reason, &$granted) {
            foreach ($pairs as [$employeeId, $quizId]) {
                $quiz = $quizzes->get($quizId);
                $employee = $employees->get($employeeId);

                // Chỉ cấp cho người thật sự bị chặn: chưa đạt và canAttempt() = false
                if (! $quiz || ! $employee || isset($passed["{$employeeId}:{$quizId}"]) || $quiz->canAttempt($employee)) {
                    continue;
                }

                QuizAttemptGrant::create([
                    'quiz_id' => $quizId,
                    'employee_id' => $employeeId,
                    'extra_attempts' => $extra,
                    'reason' => $reason,
                    'granted_by' => $byUserId,
                ]);
                // Nhân viên thấy ngay trên chuông (tab Học tập), bấm vào tới khóa chứa bài
                app(NotificationCenter::class)->attemptsGranted($quiz, $employee, $extra);
                $granted++;
            }
        });

        return ['granted' => $granted, 'skipped' => count($pairs) - $granted];
    }

    /**
     * Nhắc làm lại cho các dòng chưa đạt và còn được làm. Dòng đã đạt / hết lượt bị bỏ qua
     * (nhắc người hết lượt chỉ gây bối rối — phải cấp lượt trước).
     *
     * @param  array<int, array{0: int, 1: int}>  $pairs
     * @return array{sent: int, skipped: int}
     */
    public function remindRetake(array $pairs, ?int $byUserId): array
    {
        [$quizzes, $employees, $passed] = $this->loadPairs($pairs);
        $byQuiz = [];

        foreach ($pairs as [$employeeId, $quizId]) {
            $quiz = $quizzes->get($quizId);
            $employee = $employees->get($employeeId);

            if ($quiz && $employee && ! isset($passed["{$employeeId}:{$quizId}"]) && $quiz->canAttempt($employee)) {
                $byQuiz[$quizId][] = $employeeId;
            }
        }

        $sent = 0;
        $reminder = app(LearningReminderService::class);

        // Người vừa được nhắc cùng bài trong thời gian chờ bị bỏ qua bên trong remindRetake
        foreach ($byQuiz as $quizId => $employeeIds) {
            $sent += $reminder->remindRetake($quizzes->get($quizId), array_unique($employeeIds), $byUserId)['sent'];
        }

        return ['sent' => $sent, 'skipped' => count($pairs) - $sent];
    }

    // ---- Tab "Phân tích câu hỏi" --------------------------------------------

    /** Lượt nộp dùng cho tab phân tích: thêm lọc lần làm + kết quả lượt (+ khoảng điểm). */
    public function analysisAttempts(array $f, ReportPeriod $period, bool $withBand = false): Builder
    {
        $query = $this->submitted($f, $period);

        if (($f['attempt'] ?? '') === 'first') {
            $query->where('a.attempt_no', 1);
        } elseif (($f['attempt'] ?? '') === 'last') {
            // Lượt nộp cuối cùng của người đó trong kỳ
            [$from, $to] = $period->range();
            $query->whereRaw("a.attempt_no = (SELECT MAX(x.attempt_no) FROM quiz_attempts x WHERE x.quiz_id = a.quiz_id "
                . "AND x.employee_id = a.employee_id AND x.status IN ('submitted', 'graded') AND x.submitted_at BETWEEN ? AND ?)", [$from, $to]);
        }

        if (($f['attempt_result'] ?? '') === 'passed') {
            $query->whereRaw(self::PASS_SQL);
        } elseif (($f['attempt_result'] ?? '') === 'failed') {
            $query->whereRaw('COALESCE(a.percentage, 0) < q.pass_score');
        }

        if ($withBand && isset($f['band']) && $f['band'] !== null && $f['band'] !== '') {
            $query->whereRaw(self::BAND_SQL . ' = ?', [(int) $f['band']]);
        }

        return $query;
    }

    /** @return array{attempts: int, employees: int, passed: int, pass_rate: ?float, avg_score: ?float} */
    public function attemptKpis(array $f, ReportPeriod $period): array
    {
        $row = $this->analysisAttempts($f, $period)
            ->selectRaw('COUNT(*) AS attempts, COUNT(DISTINCT a.employee_id) AS employees, '
                . 'COALESCE(SUM(' . self::PASS_SQL . '), 0) AS passed, AVG(a.percentage) AS avg_score')
            ->first();

        return [
            'attempts' => (int) $row->attempts,
            'employees' => (int) $row->employees,
            'passed' => (int) $row->passed,
            'pass_rate' => $this->rate((int) $row->passed, (int) $row->attempts),
            'avg_score' => $row->avg_score !== null ? (float) $row->avg_score : null,
        ];
    }

    /**
     * Phân bố điểm 10 khoảng, tách lượt dưới / trên điểm đạt của chính bài đó.
     *
     * @return array<int, array{label: string, failed: int, passed: int}>
     */
    public function scoreHistogram(array $f, ReportPeriod $period): array
    {
        $counts = $this->analysisAttempts($f, $period)
            ->groupByRaw(self::BAND_SQL)
            ->selectRaw(self::BAND_SQL . ' AS band, COALESCE(SUM(' . self::PASS_SQL . '), 0) AS passed, COUNT(*) AS total')
            ->get()
            ->keyBy(fn ($r) => (int) $r->band);

        return collect(range(0, 9))->map(fn ($i) => [
            'label' => ($i * 10) . '–' . ($i === 9 ? 100 : $i * 10 + 10),
            'passed' => (int) ($counts[$i]->passed ?? 0),
            'failed' => (int) ($counts[$i]->total ?? 0) - (int) ($counts[$i]->passed ?? 0),
        ])->all();
    }

    /**
     * Tỷ lệ lượt đạt theo bài kiểm tra — thấp nhất trước.
     *
     * @return Collection<int, object>
     */
    public function passRateByQuiz(array $f, ReportPeriod $period): Collection
    {
        return $this->analysisAttempts($f, $period)
            ->groupBy('a.quiz_id', 'q.title')
            ->selectRaw('a.quiz_id, q.title, COUNT(*) AS total, COALESCE(SUM(' . self::PASS_SQL . '), 0) AS passed')
            ->get()
            ->map(function ($r) {
                $r->rate = $this->rate((int) $r->passed, (int) $r->total);

                return $r;
            })
            ->sortBy([['rate', 'asc'], ['title', 'asc']])
            ->values();
    }

    /**
     * Thống kê từng câu hỏi: số lần ra, % đúng — gom nhóm một truy vấn trên quiz_attempt_answers.
     * Sắp xếp: rate (mặc định, thấp → cao), shown, stt.
     */
    public function questionTable(array $f, ReportPeriod $period, string $sort = 'rate', string $dir = 'asc'): Builder
    {
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        $attemptIds = $this->analysisAttempts($f, $period, true)->select('a.id');

        $query = DB::table('quiz_attempt_answers as ans')
            ->join('questions as qs', 'qs.id', '=', 'ans.question_id')
            ->join('quizzes as qz', 'qz.id', '=', 'qs.quiz_id')
            ->whereIn('ans.quiz_attempt_id', $attemptIds)
            ->groupBy('qs.id', 'qs.quiz_id', 'qs.content', 'qs.type', 'qs.sort_order', 'qz.title')
            ->selectRaw('qs.id, qs.quiz_id, qs.content, qs.type, qs.sort_order, qz.title AS quiz_title, '
                . 'COUNT(*) AS shown, COALESCE(SUM(ans.is_correct = 1), 0) AS correct, '
                . 'COALESCE(SUM(ans.is_correct = 1), 0) * 100 / COUNT(*) AS correct_rate');

        return (match ($sort) {
            'shown' => $query->orderBy('shown', $dir)->orderBy('correct_rate'),
            'stt' => $query->orderBy('qz.title', $dir)->orderBy('qs.sort_order', $dir),
            default => $query->orderBy('correct_rate', $dir)->orderByDesc('shown'),
        })->orderBy('qs.id');
    }

    /**
     * Bổ sung cho các câu đang hiển thị: đáp án sai được chọn nhiều nhất, đáp án đúng, cờ chất lượng.
     * JSON selected_option_ids được gom nhóm theo nguyên chuỗi trong SQL rồi diễn giải ở PHP.
     *
     * @param  iterable<object>  $rows  dòng từ questionTable()
     * @return Collection<int, object>
     */
    public function enrichQuestions(iterable $rows, array $f, ReportPeriod $period): Collection
    {
        $rows = collect($rows);
        $ids = $rows->pluck('id')->all();

        if ($ids === []) {
            return $rows;
        }

        $options = DB::table('question_options')->whereIn('question_id', $ids)
            ->orderBy('sort_order')->get(['id', 'question_id', 'content', 'is_correct'])
            ->groupBy('question_id');

        $wrongPatterns = DB::table('quiz_attempt_answers as ans')
            ->whereIn('ans.quiz_attempt_id', $this->analysisAttempts($f, $period, true)->select('a.id'))
            ->whereIn('ans.question_id', $ids)
            ->where(fn ($q) => $q->where('ans.is_correct', false)->orWhereNull('ans.is_correct'))
            ->groupByRaw('ans.question_id, CAST(ans.selected_option_ids AS CHAR)')
            ->selectRaw('ans.question_id, CAST(ans.selected_option_ids AS CHAR) AS selected, COUNT(*) AS c')
            ->get()
            ->groupBy('question_id');

        return $rows->map(function ($row) use ($options, $wrongPatterns) {
            $opts = $options->get($row->id, collect());
            $row->shown = (int) $row->shown;
            $row->correct_rate = (float) $row->correct_rate;
            $row->correct_text = $opts->where('is_correct', true)->pluck('content')->implode('; ');
            $row->top_wrong = $this->topWrongAnswer($row, $opts, $wrongPatterns->get($row->id, collect()));
            $row->flag = self::qualityFlag($row->correct_rate, $row->shown, $row->type);

            return $row;
        });
    }

    /**
     * Cờ chất lượng của một câu (ngưỡng ở hằng số đầu lớp).
     *
     * @return array{label: string, tone: string}|null  tone: danger | outline | muted
     */
    public static function qualityFlag(float $correctRate, int $shown, string $type): ?array
    {
        if ($shown < self::MIN_SAMPLE) {
            return ['label' => 'Chưa đủ mẫu', 'tone' => 'muted'];
        }

        if ($type === Question::TYPE_MULTIPLE && $correctRate < self::REVIEW_BELOW) {
            return ['label' => 'Rà soát', 'tone' => 'outline'];
        }

        return match (true) {
            $correctRate < self::TOO_HARD_BELOW => ['label' => 'Quá khó', 'tone' => 'danger'],
            $correctRate < self::REVIEW_BELOW => ['label' => 'Rà soát', 'tone' => 'outline'],
            $correctRate > self::TOO_EASY_ABOVE => ['label' => 'Quá dễ', 'tone' => 'outline'],
            default => null,
        };
    }

    // ---- Danh mục cho bộ lọc ------------------------------------------------

    /** @return Collection<int, object> khóa học có bài kiểm tra */
    public function courseOptions(): Collection
    {
        return DB::table('courses as c')
            ->whereNull('c.deleted_at')
            ->whereExists(fn ($q) => $q->from('quizzes as q')->whereColumn('q.course_id', 'c.id')->whereNull('q.deleted_at'))
            ->orderBy('c.title')
            ->get(['c.id', 'c.title']);
    }

    /** @return Collection<int, object> */
    public function quizOptions(?int $courseId): Collection
    {
        return Quiz::query()
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->orderBy('title')
            ->get(['id', 'title', 'course_id', 'pass_score']);
    }

    // ---- Nội bộ ---------------------------------------------------------------

    /** Lượt đã nộp trong kỳ, đã nối quizzes (q) — gốc của mọi truy vấn. */
    private function submitted(array $f, ReportPeriod $period): Builder
    {
        return DB::table('quiz_attempts as a')
            ->join('quizzes as q', 'q.id', '=', 'a.quiz_id')
            ->whereIn('a.status', ['submitted', 'graded'])
            ->whereBetween('a.submitted_at', $period->range())
            ->whereNull('q.deleted_at')
            ->when($f['course_id'] ?? null, fn ($q, $id) => $q->where('q.course_id', (int) $id))
            ->when($f['quiz_id'] ?? null, fn ($q, $id) => $q->where('a.quiz_id', (int) $id));
    }

    /** Phòng ban được chọn + toàn bộ phòng con. */
    private function departmentIds($departmentId): ?array
    {
        if (! $departmentId) {
            return null;
        }

        return Department::find((int) $departmentId)?->descendantIds() ?? [(int) $departmentId];
    }

    private function wherePairs($query, array $pairs): void
    {
        foreach ($pairs as [$employeeId, $quizId]) {
            $query->orWhere(fn ($w) => $w->where('employee_id', (int) $employeeId)->where('quiz_id', (int) $quizId));
        }
    }

    /**
     * Nạp sẵn bài, nhân viên và các cặp đã đạt (mọi lượt đã nộp, không giới hạn kỳ).
     *
     * @return array{0: Collection, 1: Collection, 2: array<string, true>}
     */
    private function loadPairs(array $pairs): array
    {
        $quizIds = array_unique(array_map(fn ($p) => (int) $p[1], $pairs));
        $employeeIds = array_unique(array_map(fn ($p) => (int) $p[0], $pairs));

        $passed = DB::table('quiz_attempts as a')
            ->join('quizzes as q', 'q.id', '=', 'a.quiz_id')
            ->whereIn('a.quiz_id', $quizIds ?: [0])
            ->whereIn('a.employee_id', $employeeIds ?: [0])
            ->whereIn('a.status', ['submitted', 'graded'])
            ->whereRaw(self::PASS_SQL)
            ->distinct()
            ->get(['a.employee_id', 'a.quiz_id'])
            ->mapWithKeys(fn ($r) => ["{$r->employee_id}:{$r->quiz_id}" => true])
            ->all();

        return [
            Quiz::whereIn('id', $quizIds ?: [0])->get()->keyBy('id'),
            Employee::whereIn('id', $employeeIds ?: [0])->get()->keyBy('id'),
            $passed,
        ];
    }

    /**
     * Đáp án sai phổ biến nhất của một câu.
     * Câu nhiều lựa chọn: lỗi hay gặp là chọn thiếu — gom thành "Chọn thiếu k trong N đáp án đúng".
     *
     * @return array{text: string, quoted: bool, pct: float}|null
     */
    private function topWrongAnswer(object $row, Collection $options, Collection $patterns): ?array
    {
        if ($patterns->isEmpty() || $row->shown === 0) {
            return null;
        }

        $correctIds = $options->where('is_correct', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $contents = $options->pluck('content', 'id');
        $tally = [];

        foreach ($patterns as $p) {
            $selected = array_values(array_unique(array_map('intval', (array) json_decode((string) $p->selected, true))));
            $count = (int) $p->c;

            if ($selected === []) {
                $tally['blank'] = ($tally['blank'] ?? 0) + $count;

                continue;
            }

            $wrongPicked = array_diff($selected, $correctIds);

            if ($row->type === Question::TYPE_MULTIPLE && $wrongPicked === []) {
                $missing = count(array_diff($correctIds, $selected));
                $tally["missing:{$missing}"] = ($tally["missing:{$missing}"] ?? 0) + $count;

                continue;
            }

            foreach ($wrongPicked as $optionId) {
                $tally["option:{$optionId}"] = ($tally["option:{$optionId}"] ?? 0) + $count;
            }
        }

        if ($tally === []) {
            return null;
        }

        arsort($tally);
        $key = array_key_first($tally);
        $pct = $tally[$key] / $row->shown * 100;

        if ($key === 'blank') {
            return ['text' => 'Bỏ trống', 'quoted' => false, 'pct' => $pct];
        }

        if (str_starts_with($key, 'missing:')) {
            return ['text' => 'Chọn thiếu ' . substr($key, 8) . ' trong ' . count($correctIds) . ' đáp án đúng', 'quoted' => false, 'pct' => $pct];
        }

        $optionId = (int) substr($key, 7);

        return ['text' => (string) ($contents[$optionId] ?? 'Đáp án đã xóa'), 'quoted' => true, 'pct' => $pct];
    }

    private function rate(int $part, int $total): ?float
    {
        return $total > 0 ? $part / $total * 100 : null;
    }
}
