<?php

namespace App\Services\Reports;

use App\Models\Department;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Collection;

/**
 * Số liệu mẫu "Báo cáo hiệu quả chương trình đào tạo" (mở từ Báo cáo tiến độ học tập).
 *
 * Phạm vi = tập nền của LearningProgressReportService (mốc thời gian + kỳ, đơn vị,
 * khóa, loại khóa, tiến độ, nguồn giao, bỏ NV đã nghỉ) — cùng tập với các thẻ KPI.
 * Bộ lọc chỉ áp cho bảng (trạng thái, ngày hoàn thành bấm từ biểu đồ, ô tìm) KHÔNG áp:
 * lọc "Quá hạn" rồi đo tỷ lệ hoàn thành đúng hạn thì chỉ số nào cũng bằng 0, vô nghĩa.
 *
 * Một dòng = một khóa học (chương trình) × một đơn vị. "Đơn vị" là cấp ngay dưới
 * phạm vi đang xem: không chọn đơn vị → khối cấp cao nhất chứa phòng của nhân viên
 * (giống mẫu "Khối KHCN"); chọn một đơn vị → các đơn vị con trực tiếp của nó (nhân viên
 * thuộc thẳng đơn vị đã chọn đứng riêng một dòng mang tên đơn vị đó). Nhờ vậy bảng
 * không vỡ thành hàng trăm dòng phòng nhỏ, mà chọn khối nào thì xem được chi tiết khối đó.
 *
 * Chỉ số mỗi dòng (gom bằng SQL theo khóa × phòng, rồi cộng dồn lên đơn vị ở PHP —
 * mọi chỉ số đều là tổng / số đếm nên cộng dồn chính xác):
 *  - Số NV: số nhân viên ghi danh (mỗi NV một lượt / khóa).
 *  - Hoàn thành đúng hạn: lượt đã hoàn thành vào/trước hạn (ngày VN); lượt không có hạn
 *    mà đã hoàn thành tính là đúng hạn. Tỷ lệ = số đó ÷ Số NV (như mẫu).
 *  - Điểm KT TB, Tỷ lệ đạt lần 1: lượt làm LẦN ĐẦU (attempt_no = 1, đã nộp) của các NV đó
 *    trên bài kiểm tra thuộc khóa — cùng quy ước với "Đạt kiểm tra lần đầu" ở Tổng quan, để
 *    lượt làm lại không kéo điểm lên. Đạt = percentage ≥ pass_score hiện tại của bài.
 *  - TG ĐT TB (ngày): trung bình (completed_at − assigned_at) của các lượt đã hoàn thành.
 *  - Đánh giá: chỉ số tổng hợp = trung bình các chỉ số có dữ liệu trong
 *    (tỷ lệ HT đúng hạn, điểm KT TB, tỷ lệ đạt lần 1), xếp theo RATING_THRESHOLDS.
 */
class TrainingEffectivenessReportService
{
    /** Ngưỡng chỉ số tổng hợp → xếp loại (xét từ trên xuống). */
    public const RATING_THRESHOLDS = [
        90 => 'Xuất sắc',
        80 => 'Rất tốt',
        70 => 'Tốt',
        0 => 'Cần cải thiện',
    ];

    public const RATING_TONES = [
        'Xuất sắc' => 'great',
        'Rất tốt' => 'great',
        'Tốt' => 'good',
        'Cần cải thiện' => 'bad',
    ];

    /** Ngưỡng tô màu số: [xanh lá từ, xanh dương từ] — dưới mức sau là đỏ. */
    public const TONE_ON_TIME = [85, 70];
    public const TONE_SCORE = [80, 70];
    public const TONE_FIRST_PASS = [85, 70];

    public function __construct(private readonly LearningProgressReportService $learning)
    {
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, mixed>, employees: int}
     */
    public function build(array $filters, ReportPeriod $period): array
    {
        $f = $this->learning->normalize($filters);
        $completedLocal = ReportPeriod::localDate('e.completed_at');

        $base = $this->learning->baseQuery($f, $period)->toBase()
            ->groupBy('e.course_id', 'c.title', 'emp.department_id')
            ->selectRaw('e.course_id, c.title AS course_title, emp.department_id, '
                . 'COUNT(DISTINCT e.employee_id) AS employees, '
                . "COALESCE(SUM(e.status = 'completed' AND (e.due_date IS NULL OR {$completedLocal} <= e.due_date)), 0) AS on_time, "
                . "COALESCE(SUM(e.status = 'completed' AND e.completed_at IS NOT NULL AND e.assigned_at IS NOT NULL), 0) AS finished, "
                . "COALESCE(SUM(CASE WHEN e.status = 'completed' AND e.completed_at IS NOT NULL AND e.assigned_at IS NOT NULL "
                . 'THEN GREATEST(TIMESTAMPDIFF(SECOND, e.assigned_at, e.completed_at), 0) END), 0) AS seconds')
            ->get();

        $quiz = $this->learning->baseQuery($f, $period)->toBase()
            ->join('quiz_attempts as qa', function ($join) {
                $join->on('qa.employee_id', '=', 'e.employee_id')
                    ->where('qa.attempt_no', '=', 1)
                    ->whereIn('qa.status', ['submitted', 'graded']);
            })
            ->join('quizzes as q', function ($join) {
                $join->on('q.id', '=', 'qa.quiz_id')->on('q.course_id', '=', 'e.course_id');
            })
            ->whereNull('q.deleted_at')
            ->groupBy('e.course_id', 'emp.department_id')
            ->selectRaw('e.course_id, emp.department_id, COUNT(*) AS attempts, '
                . 'COALESCE(SUM(qa.percentage), 0) AS score_sum, COALESCE(SUM(qa.percentage >= q.pass_score), 0) AS passed')
            ->get()
            ->keyBy(fn ($r) => $r->course_id . ':' . (int) $r->department_id);

        $units = $this->unitResolver($f['department'] ? (int) $f['department'] : null);
        $rows = [];

        foreach ($base as $g) {
            [$unitId, $unitName] = $units((int) $g->department_id ?: null);
            $key = $g->course_id . ':' . $unitId;
            $q = $quiz->get($g->course_id . ':' . (int) $g->department_id);

            $rows[$key] ??= [
                'course_id' => (int) $g->course_id,
                'course' => (string) $g->course_title,
                'unit_id' => $unitId,
                'unit' => $unitName,
                'employees' => 0, 'on_time' => 0, 'finished' => 0, 'seconds' => 0,
                'attempts' => 0, 'score_sum' => 0.0, 'passed' => 0,
            ];
            $rows[$key]['employees'] += (int) $g->employees;
            $rows[$key]['on_time'] += (int) $g->on_time;
            $rows[$key]['finished'] += (int) $g->finished;
            $rows[$key]['seconds'] += (int) $g->seconds;
            $rows[$key]['attempts'] += (int) ($q->attempts ?? 0);
            $rows[$key]['score_sum'] += (float) ($q->score_sum ?? 0);
            $rows[$key]['passed'] += (int) ($q->passed ?? 0);
        }

        $rows = collect($rows)
            ->map(fn (array $row) => $this->metrics($row))
            ->sortBy([['course', 'asc'], ['unit', 'asc']])
            ->values();

        $totals = $this->metrics([
            'employees' => $rows->sum('employees'),
            'on_time' => $rows->sum('on_time'),
            'finished' => $rows->sum('finished'),
            'seconds' => $rows->sum('seconds'),
            'attempts' => $rows->sum('attempts'),
            'score_sum' => $rows->sum('score_sum'),
            'passed' => $rows->sum('passed'),
        ]);

        // Một nhân viên học nhiều khóa chỉ đếm một lần ở dòng tóm tắt
        $employees = (int) $this->learning->baseQuery($f, $period)->toBase()
            ->selectRaw('COUNT(DISTINCT e.employee_id) AS c')->value('c');

        return ['rows' => $rows, 'totals' => $totals, 'employees' => $employees];
    }

    /** Tỷ lệ / trung bình từ các tổng đã cộng dồn. */
    public function metrics(array $row): array
    {
        $row['on_time_rate'] = $row['employees'] > 0 ? $row['on_time'] / $row['employees'] * 100 : null;
        $row['avg_score'] = $row['attempts'] > 0 ? $row['score_sum'] / $row['attempts'] : null;
        $row['first_pass_rate'] = $row['attempts'] > 0 ? $row['passed'] / $row['attempts'] * 100 : null;
        $row['avg_days'] = $row['finished'] > 0 ? $row['seconds'] / $row['finished'] / 86400 : null;
        $row['index'] = self::compositeIndex($row['on_time_rate'], $row['avg_score'], $row['first_pass_rate']);
        $row['rating'] = self::rating($row['index']);

        return $row;
    }

    public static function compositeIndex(?float ...$values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));

        return $values === [] ? null : array_sum($values) / count($values);
    }

    public static function rating(?float $index): ?string
    {
        if ($index === null) {
            return null;
        }

        foreach (self::RATING_THRESHOLDS as $min => $label) {
            if ($index >= $min) {
                return $label;
            }
        }

        return 'Cần cải thiện';
    }

    /**
     * Hàm đổi phòng của nhân viên → [id, tên] đơn vị hiển thị (cấp ngay dưới phạm vi).
     * Nạp cây phòng ban một lần; có chặn vòng lặp nếu dữ liệu cha–con lỗi.
     */
    private function unitResolver(?int $scopeId): \Closure
    {
        $departments = Department::query()->get(['id', 'name', 'parent_id'])->keyBy('id');
        $cache = [];

        return function (?int $departmentId) use ($departments, $scopeId, &$cache): array {
            if (! $departmentId || ! $departments->has($departmentId)) {
                return [0, 'Chưa gán đơn vị'];
            }

            if (isset($cache[$departmentId])) {
                return $cache[$departmentId];
            }

            $current = $departments[$departmentId];
            $guard = 0;
            while ($current->id !== $scopeId
                && $current->parent_id
                && (int) $current->parent_id !== $scopeId
                && $departments->has((int) $current->parent_id)
                && $guard++ < 50) {
                $current = $departments[(int) $current->parent_id];
            }

            return $cache[$departmentId] = [(int) $current->id, (string) $current->name];
        };
    }
}
