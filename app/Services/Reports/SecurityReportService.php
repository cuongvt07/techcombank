<?php

namespace App\Services\Reports;

use App\Models\LoginHistory;
use App\Models\SecurityAlert;
use App\Models\User;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Số liệu cho "Báo cáo bảo mật và đăng nhập" (SCR_008).
 *
 * Màn này chỉ thống kê. Xử lý từng cảnh báo vẫn ở Trung tâm bảo mật — tách ra
 * để người xem báo cáo định kỳ (lãnh đạo, kiểm toán) không vô tình đổi trạng
 * thái cảnh báo.
 */
class SecurityReportService
{
    /** Khung giờ làm việc theo giờ VN: ngoài [06:00, 22:00) là "ngoài giờ". */
    public const WORK_HOUR_START = 6;
    public const WORK_HOUR_END = 22;

    /** Ô ngoài giờ có nhiều hơn số lượt này thì viền đỏ trên bản đồ nhiệt. */
    public const OFF_HOURS_THRESHOLD = 10;

    /** Cảnh báo "Mới" để quá số giờ này là chậm ghi nhận. */
    public const OPEN_OVERDUE_HOURS = 4;

    public const SEVERITY_LABELS = [
        'critical' => 'Nghiêm trọng',
        'high' => 'Cao',
        'medium' => 'Trung bình',
        'low' => 'Thấp',
    ];

    public const STATUS_LABELS = [
        SecurityAlert::STATUS_OPEN => 'Mới',
        SecurityAlert::STATUS_ACKNOWLEDGED => 'Đã ghi nhận',
        SecurityAlert::STATUS_RESOLVED => 'Đã đóng',
        SecurityAlert::STATUS_FALSE_POSITIVE => 'Nhầm',
    ];

    public const RESULT_LABELS = [
        'success' => 'Thành công',
        'failed' => 'Thất bại',
        'blocked' => 'Bị chặn',
    ];

    /** Khớp ReportPeriod::localWeekday: 1 = Thứ 2 … 7 = Chủ nhật. */
    public const WEEKDAY_LABELS = [
        1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5',
        5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'Chủ nhật',
    ];

    private const WEEKDAY_SHORT = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];

    // ---- Truy vấn gốc -------------------------------------------------------

    /**
     * Cảnh báo phát sinh trong kỳ, theo bộ lọc loại / mức độ / trạng thái.
     * Mọi khối liên quan cảnh báo (KPI, biểu đồ, bảng, file xuất) dùng chung
     * truy vấn này để con số khớp nhau.
     *
     * @param  array{type?: ?string, severity?: ?string, status?: ?string}  $filters
     */
    public function alertQuery(ReportPeriod $period, array $filters = []): Builder
    {
        return SecurityAlert::query()
            ->whereBetween('created_at', $period->range())
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
    }

    /**
     * Lượt đăng nhập trong kỳ, có thể thu hẹp theo một ô bản đồ nhiệt (thứ + giờ VN).
     */
    public function loginQuery(ReportPeriod $period, ?int $weekday = null, ?int $hour = null): Builder
    {
        return LoginHistory::query()
            ->whereBetween('logged_in_at', $period->range())
            ->when($weekday !== null, fn ($q) => $q->whereRaw(ReportPeriod::localWeekday('logged_in_at') . ' = ?', [$weekday]))
            ->when($hour !== null, fn ($q) => $q->whereRaw(ReportPeriod::localHour('logged_in_at') . ' = ?', [$hour]));
    }

    // ---- Chỉ số -------------------------------------------------------------

    /**
     * @return array{total: int, failed: int, rate: ?float}
     *         failed gồm cả "blocked" (tài khoản khóa vẫn cố đăng nhập) — cả hai đều là lượt không vào được.
     */
    public function loginStats(ReportPeriod $period): array
    {
        $row = $this->loginQuery($period)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN result IN ('failed', 'blocked') THEN 1 ELSE 0 END) as failed")
            ->first();

        $total = (int) ($row->total ?? 0);
        $failed = (int) ($row->failed ?? 0);

        return [
            'total' => $total,
            'failed' => $failed,
            'rate' => $total > 0 ? $failed / $total * 100 : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function kpis(ReportPeriod $period, array $filters = []): array
    {
        $current = $this->loginStats($period);
        $previous = $this->loginStats($period->previous());

        $alerts = $this->alertQuery($period, $filters)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('open', 'acknowledged') THEN 1 ELSE 0 END) as unclosed")
            ->first();

        // Thời gian phản hồi lấy bằng giây từ SQL, trung vị tính trong PHP:
        // MySQL không có hàm MEDIAN, còn trung bình bị vài cảnh báo bỏ quên kéo lệch.
        $ackSeconds = $this->alertQuery($period, $filters)
            ->whereNotNull('acknowledged_at')
            ->selectRaw('TIMESTAMPDIFF(SECOND, created_at, acknowledged_at) as secs')
            ->pluck('secs')->all();
        $resolveSeconds = $this->alertQuery($period, $filters)
            ->whereNotNull('resolved_at')
            ->selectRaw('TIMESTAMPDIFF(SECOND, created_at, resolved_at) as secs')
            ->pluck('secs')->all();

        $toHours = fn (?float $s) => $s === null ? null : $s / 3600;

        return [
            'logins' => $current['total'],
            'fail_rate' => $current['rate'],
            'failed' => $current['failed'],
            // Chênh lệch điểm phần trăm; null khi kỳ trước không có lượt nào để so
            'fail_rate_delta' => $current['rate'] !== null && $previous['rate'] !== null
                ? $current['rate'] - $previous['rate']
                : null,
            // "Tài khoản bị khóa" = số tài khoản ĐANG ở trạng thái khóa (users.status = locked).
            // Không lọc theo kỳ vì users không lưu thời điểm bị khóa; số lượt bị chặn
            // trong kỳ chỉ đi kèm làm ghi chú.
            'locked_accounts' => User::where('status', User::STATUS_LOCKED)->count(),
            'blocked_attempts' => $this->loginQuery($period)->where('result', 'blocked')->count(),
            'alerts' => (int) ($alerts->total ?? 0),
            'alerts_unclosed' => (int) ($alerts->unclosed ?? 0),
            'mtta_hours' => $toHours(self::median($ackSeconds)),
            'mtta_count' => count($ackSeconds),
            'mttr_hours' => $toHours(self::median($resolveSeconds)),
            'mttr_count' => count($resolveSeconds),
        ];
    }

    // ---- Biểu đồ ------------------------------------------------------------

    /**
     * Đăng nhập theo ngày (giờ VN), đủ mọi ngày trong kỳ.
     *
     * @return Collection<int, array{date: string, label: string, success: int, failed: int, rate: ?float}>
     */
    public function dailyLogins(ReportPeriod $period): Collection
    {
        $day = ReportPeriod::localDate('logged_in_at');

        $rows = $this->loginQuery($period)
            ->selectRaw($day . ' as day')
            ->selectRaw("SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END) as success")
            ->selectRaw("SUM(CASE WHEN result IN ('failed', 'blocked') THEN 1 ELSE 0 END) as failed")
            ->groupByRaw($day)
            ->get()
            ->keyBy(fn ($r) => (string) $r->day);

        return $period->dates()->map(function (string $date) use ($rows) {
            $success = (int) ($rows[$date]->success ?? 0);
            $failed = (int) ($rows[$date]->failed ?? 0);
            $d = CarbonImmutable::parse($date);

            return [
                'date' => $date,
                'label' => self::WEEKDAY_SHORT[$d->dayOfWeekIso] . ' ' . $d->format('d/m'),
                'success' => $success,
                'failed' => $failed,
                'rate' => $success + $failed > 0 ? $failed / ($success + $failed) * 100 : null,
            ];
        })->values();
    }

    /**
     * Cảnh báo theo loại, tách mức độ. "Nghiêm trọng" gộp vào nhóm Cao như thiết kế (3 nhóm màu).
     *
     * @return Collection<int, array{type: string, label: string, high: int, medium: int, low: int, total: int}>
     */
    public function alertsByType(ReportPeriod $period, array $filters = []): Collection
    {
        $rows = $this->alertQuery($period, $filters)
            ->selectRaw('type, severity, COUNT(*) as total')
            ->groupBy('type', 'severity')
            ->get();

        return $rows->groupBy('type')
            ->map(function (Collection $group, string $type) {
                $by = $group->pluck('total', 'severity')->map(fn ($v) => (int) $v);
                $high = ($by['critical'] ?? 0) + ($by['high'] ?? 0);

                return [
                    'type' => $type,
                    'label' => SecurityAlert::TYPE_LABELS[$type] ?? $type,
                    'high' => $high,
                    'medium' => $by['medium'] ?? 0,
                    'low' => $by['low'] ?? 0,
                    'total' => $high + ($by['medium'] ?? 0) + ($by['low'] ?? 0),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Ma trận lượt đăng nhập [thứ 0..6][giờ 0..23] theo giờ VN.
     *
     * @return array<int, array<int, int>>
     */
    public function loginHeatmap(ReportPeriod $period): array
    {
        $weekday = ReportPeriod::localWeekday('logged_in_at');
        $hour = ReportPeriod::localHour('logged_in_at');

        $matrix = array_fill(0, 7, array_fill(0, 24, 0));

        $this->loginQuery($period)
            ->selectRaw($weekday . ' as wd')
            ->selectRaw($hour . ' as hr')
            ->selectRaw('COUNT(*) as total')
            ->groupByRaw($weekday . ', ' . $hour)
            ->get()
            ->each(function ($r) use (&$matrix) {
                $matrix[(int) $r->wd - 1][(int) $r->hr] = (int) $r->total;
            });

        return $matrix;
    }

    /**
     * Ô ngoài giờ làm việc vượt ngưỡng.
     *
     * @return array<int, array{0: int, 1: int}> [[hàng, cột], ...]
     */
    public function flaggedCells(array $matrix): array
    {
        $flagged = [];
        foreach ($matrix as $r => $hours) {
            foreach ($hours as $h => $count) {
                if (self::isOffHours($h) && $count > self::OFF_HOURS_THRESHOLD) {
                    $flagged[] = [$r, $h];
                }
            }
        }

        return $flagged;
    }

    // ---- Nhận định tự sinh --------------------------------------------------

    /** Cảnh báo khi ngày cuối kỳ có tỷ lệ thất bại cao hơn trung bình kỳ. */
    public function dailyInsight(Collection $daily, ?float $periodRate): ?string
    {
        $last = $daily->last();
        if (! $last || $last['rate'] === null || $periodRate === null || $last['rate'] <= $periodRate) {
            return null;
        }

        $volumes = $daily->map(fn ($d) => $d['success'] + $d['failed']);
        $avgVolume = $volumes->avg();
        $lastVolume = $last['success'] + $last['failed'];

        return 'Ngày cuối kỳ (' . $last['label'] . ') có tỷ lệ thất bại ' . self::pct($last['rate'])
            . ', cao hơn trung bình kỳ ' . self::pct($periodRate)
            . ($lastVolume < $avgVolume ? ' dù lượt đăng nhập ít hơn bình thường' : '')
            . '. Kiểm tra các tài khoản đăng nhập sai liên tiếp.';
    }

    /** Loại cảnh báo có tỷ lệ mức Cao lớn nhất — nhóm nên xử lý trước. */
    public function typeInsight(Collection $byType): ?string
    {
        $top = $byType->filter(fn ($r) => $r['high'] > 0)
            ->sortByDesc(fn ($r) => [$r['high'] / $r['total'], $r['high']])
            ->first();

        if (! $top) {
            return null;
        }

        $fewer = $top['total'] < $byType->max('total');

        return $top['label'] . ($fewer ? ' ít về số lượng nhưng' : '')
            . ' có tỷ lệ mức Cao lớn nhất: ' . $top['high'] . ' trong ' . $top['total']
            . ' cảnh báo. Xử lý nhóm này trước.';
    }

    public function heatmapInsight(array $matrix, array $flagged): string
    {
        $tail = 'Bấm một ô để lọc lịch sử đăng nhập theo khung giờ đó.';

        if ($flagged === []) {
            return 'Không có khung ngoài giờ nào vượt ' . self::OFF_HOURS_THRESHOLD . ' lượt. ' . $tail;
        }

        $parts = collect($flagged)
            ->sortByDesc(fn ($c) => $matrix[$c[0]][$c[1]])
            ->take(3)
            ->map(fn ($c) => self::WEEKDAY_LABELS[$c[0] + 1] . ' ' . self::hourRange($c[1]) . ' (' . $matrix[$c[0]][$c[1]] . ' lượt)')
            ->implode(', ');

        return count($flagged) . ' khung ngoài giờ vượt ngưỡng: ' . $parts
            . (count($flagged) > 3 ? '…' : '') . '. ' . $tail;
    }

    // ---- Tiện ích -----------------------------------------------------------

    public static function isOffHours(int $hour): bool
    {
        return $hour < self::WORK_HOUR_START || $hour >= self::WORK_HOUR_END;
    }

    /** "08:00–09:00" */
    public static function hourRange(int $hour): string
    {
        return sprintf('%02d:00–%02d:00', $hour, ($hour + 1) % 24);
    }

    /** @param  array<int, int|float|string|null>  $values */
    public static function median(array $values): ?float
    {
        $values = array_values(array_map('floatval', array_filter($values, fn ($v) => $v !== null)));
        $n = count($values);
        if ($n === 0) {
            return null;
        }

        sort($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Mã hiển thị AL-YYYY-NNNN: năm phát sinh (giờ VN) + id, đủ ngắn để đọc qua điện thoại. */
    public static function alertCode(SecurityAlert $alert): string
    {
        return sprintf('AL-%s-%04d', ReportPeriod::local($alert->created_at)->format('Y'), $alert->id);
    }

    /** Che mã nhân viên: giữ 4 ký tự đầu (tiền tố + đầu số), phần còn lại thành '*'. */
    public static function maskCode(?string $code): string
    {
        if (! $code) {
            return '—';
        }

        return mb_substr($code, 0, 4) . str_repeat('*', max(4, mb_strlen($code) - 4));
    }

    /** Thời gian xử lý (giờ): tới lúc đóng nếu đã đóng, không thì tới lúc ghi nhận. */
    public static function handlingHours(SecurityAlert $alert): ?float
    {
        $end = $alert->resolved_at ?? $alert->acknowledged_at;

        return $end ? $alert->created_at->diffInSeconds($end, true) / 3600 : null;
    }

    public static function isOpenOverdue(SecurityAlert $alert): bool
    {
        return $alert->status === SecurityAlert::STATUS_OPEN
            && $alert->created_at->lt(now()->subHours(self::OPEN_OVERDUE_HOURS));
    }

    public static function pct(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.') . '%';
    }

    public static function hours(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.');
    }
}
