<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Kỳ báo cáo + kỳ so sánh, tính theo giờ Việt Nam (GMT+7).
 *
 * CSDL lưu giờ UTC và app chạy UTC, nhưng người xem báo cáo nghĩ theo ngày
 * Việt Nam: lượt hoàn thành lúc 06:00 sáng 21/10 (giờ VN) là 23:00 ngày 20/10
 * UTC. Mọi gom nhóm theo ngày phải đi qua localDate() để không lệch một ngày.
 *
 * Mốc from/to trả về đã đổi sang UTC để so sánh thẳng với cột timestamp.
 */
final class ReportPeriod
{
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    /** Chênh lệch giờ VN so với UTC, dùng trong SQL (không cần bảng múi giờ của MySQL). */
    public const OFFSET_HOURS = 7;

    public const PRESETS = [
        'today' => 'Hôm nay',
        '7d' => '7 ngày',
        '30d' => '30 ngày',
        'quarter' => 'Quý này',
        'custom' => 'Tùy chọn',
    ];

    private function __construct(
        /** Ngày đầu kỳ, giờ VN, 00:00 */
        public readonly CarbonImmutable $startLocal,
        /** Ngày cuối kỳ, giờ VN, 23:59:59 */
        public readonly CarbonImmutable $endLocal,
        public readonly string $preset,
    ) {
    }

    /**
     * @param  string|null  $from  Y-m-d (giờ VN), chỉ dùng khi preset = custom
     * @param  string|null  $to    Y-m-d (giờ VN)
     */
    public static function make(string $preset = '30d', ?string $from = null, ?string $to = null, int $maxDays = 366): self
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        [$start, $end] = match ($preset) {
            'today' => [$today, $today],
            '7d' => [$today->subDays(6), $today],
            'quarter' => [$today->firstOfQuarter(), $today],
            'custom' => self::customRange($from, $to, $today),
            default => [$today->subDays(29), $today],
        };

        if (! array_key_exists($preset, self::PRESETS)) {
            $preset = '30d';
        }

        // Chặn kỳ quá dài (một số báo cáo, như bảo mật, chỉ cho tối đa 92 ngày)
        if ($start->diffInDays($end) + 1 > $maxDays) {
            $start = $end->subDays($maxDays - 1);
        }

        return new self($start->startOfDay(), $end->endOfDay(), $preset);
    }

    /** Kỳ cuối cùng N ngày tính tới hôm nay. */
    public static function lastDays(int $days): self
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        return new self($today->subDays($days - 1), $today->endOfDay(), 'custom');
    }

    /** Kỳ liền trước, cùng độ dài — để so sánh. */
    public function previous(): self
    {
        $days = $this->days();

        return new self(
            $this->startLocal->subDays($days)->startOfDay(),
            $this->startLocal->subDay()->endOfDay(),
            'custom',
        );
    }

    /** Cùng kỳ năm trước. */
    public function sameLastYear(): self
    {
        return new self($this->startLocal->subYear(), $this->endLocal->subYear(), 'custom');
    }

    public function days(): int
    {
        return (int) $this->startLocal->diffInDays($this->endLocal->startOfDay()) + 1;
    }

    /** Mốc đầu kỳ theo UTC — so sánh với cột timestamp. */
    public function from(): CarbonImmutable
    {
        return $this->startLocal->utc();
    }

    /** Mốc cuối kỳ theo UTC. */
    public function to(): CarbonImmutable
    {
        return $this->endLocal->utc();
    }

    /** Cặp [from, to] cho whereBetween trên cột timestamp. */
    public function range(): array
    {
        return [$this->from(), $this->to()];
    }

    /** Cặp ngày (Y-m-d) cho cột DATE như enrollments.due_date — đã là ngày VN. */
    public function dateRange(): array
    {
        return [$this->startLocal->toDateString(), $this->endLocal->toDateString()];
    }

    /**
     * Biểu thức SQL đổi một cột timestamp UTC ra ngày theo giờ VN.
     * Dùng cho GROUP BY / SELECT khi gom theo ngày.
     */
    public static function localDate(string $column): string
    {
        return 'DATE(DATE_ADD(' . $column . ', INTERVAL ' . self::OFFSET_HOURS . ' HOUR))';
    }

    /** Giờ trong ngày (0–23) theo giờ VN. */
    public static function localHour(string $column): string
    {
        return 'HOUR(DATE_ADD(' . $column . ', INTERVAL ' . self::OFFSET_HOURS . ' HOUR))';
    }

    /** Thứ trong tuần theo giờ VN: 1 = Thứ hai … 7 = Chủ nhật. */
    public static function localWeekday(string $column): string
    {
        return 'WEEKDAY(DATE_ADD(' . $column . ', INTERVAL ' . self::OFFSET_HOURS . ' HOUR)) + 1';
    }

    /**
     * Danh sách ngày trong kỳ (Y-m-d), để lấp ngày trống khi vẽ biểu đồ.
     *
     * @return Collection<int, string>
     */
    public function dates(): Collection
    {
        return collect(CarbonPeriod::create($this->startLocal, $this->endLocal->startOfDay()))
            ->map(fn ($d) => $d->toDateString());
    }

    /**
     * Các tuần (Thứ hai → Chủ nhật) phủ kỳ, cắt theo biên kỳ.
     *
     * @return Collection<int, array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public function weeks(): Collection
    {
        $weeks = collect();
        $cursor = $this->startLocal->startOfWeek();

        while ($cursor->lte($this->endLocal)) {
            $start = $cursor->max($this->startLocal);
            $end = $cursor->endOfWeek()->min($this->endLocal);
            $weeks->push([
                'start' => $start,
                'end' => $end,
                'label' => $start->format('d/m') . '–' . $end->format('d/m'),
            ]);
            $cursor = $cursor->addWeek();
        }

        return $weeks;
    }

    /** Nhãn hiển thị, vd "22/09/2026 – 21/10/2026". */
    public function label(): string
    {
        return $this->startLocal->format('d/m/Y') . ' – ' . $this->endLocal->format('d/m/Y');
    }

    /** Mốc "cập nhật lúc" theo giờ VN, vd "18:05 · 21/10/2026". */
    public static function nowLabel(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->format('H:i · d/m/Y');
    }

    /** Đổi một mốc UTC ra giờ VN để hiển thị. */
    public static function local($datetime): ?CarbonImmutable
    {
        return $datetime ? CarbonImmutable::parse($datetime)->setTimezone(self::TIMEZONE) : null;
    }

    private static function customRange(?string $from, ?string $to, CarbonImmutable $today): array
    {
        try {
            $start = $from ? CarbonImmutable::createFromFormat('Y-m-d', $from, self::TIMEZONE)->startOfDay() : $today->subDays(29);
            $end = $to ? CarbonImmutable::createFromFormat('Y-m-d', $to, self::TIMEZONE)->startOfDay() : $today;
        } catch (\Throwable) {
            return [$today->subDays(29), $today];
        }

        return $start->gt($end) ? [$end, $start] : [$start, $end];
    }
}
