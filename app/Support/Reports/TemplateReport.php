<?php

namespace App\Support\Reports;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Một "báo cáo khuôn mẫu": bản in A4 / file Excel có định dạng cố định của Phòng
 * Đào tạo & Phát triển (đầu trang ngân hàng, tiêu đề, kỳ / đơn vị, dòng tóm tắt
 * KPI, bảng dữ liệu tiêu đề đỏ có thể 2 tầng, dòng Tổng cộng, chân ký).
 *
 * Khác ReportExport (xuất dữ liệu thô để xử lý tiếp): đây là văn bản để in, ký,
 * lưu hồ sơ. Mỗi báo cáo chỉ việc dựng một TemplateReport; trang in
 * (reports.print.template) và TemplateReportExport vẽ cùng một cấu trúc.
 *
 * Ô dữ liệu là mảng đã chuẩn hoá bằng các hàm cell()/num()/pct()/text():
 *   ['text' => chuỗi hiển thị, 'value' => số thô cho Excel hoặc null,
 *    'format' => mã định dạng số Excel, 'tone' => màu, 'bold' => bool, 'align' => left|center|right]
 */
final class TemplateReport
{
    /** Màu chữ theo ngưỡng (giống mẫu: xanh lá tốt, xanh dương đạt, đỏ dưới ngưỡng). */
    public const TONES = [
        'great' => '1E8E3E',
        'good' => '1A56DB',
        'warn' => 'B45309',
        'bad' => 'C81E1E',
        'muted' => '6B7280',
    ];

    public const BRAND_RED = 'C00016';

    /** Đầu trang mặc định theo mẫu — ghi đè được bằng Setting report.org_* nếu cần. */
    private const ORG_DEFAULTS = [
        'name' => 'NGÂN HÀNG THƯƠNG MẠI CỔ PHẦN KỸ THƯƠNG VIỆT NAM',
        'address' => 'Địa chỉ: 191 Bà Triệu, Hai Bà Trưng, Hà Nội  |  Điện thoại: 1800 588 822',
        'division' => 'Khối Quản trị Nguồn nhân lực – Phòng Đào tạo & Phát triển',
        'city' => 'Hà Nội',
        'approver' => 'Giám đốc Đào tạo & Phát triển',
    ];

    /** Bản in / Excel giới hạn số dòng để trình duyệt không treo; cần đủ thì dùng "Xuất dữ liệu". */
    public const MAX_ROWS = 5000;

    /**
     * @param  array<int, string>  $summary  các mục của dòng tóm tắt, nối bằng " | "
     * @param  array<int, array{label: string, group?: ?string, align?: string, width?: int}>  $columns
     *         Cột liền nhau có cùng 'group' được gộp dưới một tiêu đề tầng trên.
     * @param  array<int, array<int, array<string, mixed>>>  $rows
     * @param  array<int, array<string, mixed>>|null  $totals  dòng Tổng cộng (cùng số ô với $columns)
     * @param  array<int, string>  $notes  ghi chú dưới bảng (ngưỡng tô màu, cách tính)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $periodLabel,
        public readonly string $unitLabel,
        public readonly array $summary,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $totals = null,
        public readonly array $notes = [],
        public readonly ?string $preparedBy = null,
        public readonly bool $landscape = true,
        public readonly string $fileBase = 'bao-cao',
        public readonly string $sheetTitle = 'Báo cáo',
        public readonly string $emptyText = 'Không có dữ liệu trong phạm vi lọc.',
        public readonly ?string $backUrl = null,
    ) {
    }

    // ---- Ô dữ liệu ----------------------------------------------------------

    /** @param  array<string, mixed>  $opts */
    public static function cell(string $text, array $opts = []): array
    {
        return array_merge([
            'text' => $text,
            'value' => null,
            'format' => null,
            'tone' => null,
            'bold' => false,
            'align' => null,
        ], $opts, ['text' => $text]);
    }

    public static function text(?string $text, array $opts = []): array
    {
        return self::cell((string) ($text ?? ''), $opts);
    }

    /** Số kiểu VN ("1.234,5"); null hiện "—". */
    public static function num(int|float|null $value, int $decimals = 0, ?string $tone = null, array $opts = []): array
    {
        if ($value === null) {
            return self::cell('—', ['align' => 'center'] + $opts);
        }

        return self::cell(number_format((float) $value, $decimals, ',', '.'), array_merge([
            'value' => $decimals === 0 ? (int) round($value) : round((float) $value, $decimals),
            'format' => $decimals === 0 ? '#,##0' : '#,##0.' . str_repeat('0', $decimals),
            'tone' => $tone,
            'align' => 'center',
        ], $opts));
    }

    /** Phần trăm (truyền 0–100); Excel lưu dạng phân số với định dạng %. */
    public static function pct(?float $value, int $decimals = 0, ?string $tone = null, array $opts = []): array
    {
        if ($value === null) {
            return self::cell('—', ['align' => 'center'] + $opts);
        }

        return self::cell(number_format($value, $decimals, ',', '.') . '%', array_merge([
            'value' => round($value / 100, $decimals + 2),
            'format' => $decimals === 0 ? '0%' : '0.' . str_repeat('0', $decimals) . '%',
            'tone' => $tone,
            'align' => 'center',
        ], $opts));
    }

    /**
     * Màu theo ngưỡng: ≥ $great → xanh lá, ≥ $ok → xanh dương, còn lại đỏ.
     * Đảo chiều ($higherIsBetter = false) cho chỉ số "càng thấp càng tốt".
     */
    public static function tone(?float $value, float $great, float $ok, bool $higherIsBetter = true): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($higherIsBetter) {
            return $value >= $great ? 'great' : ($value >= $ok ? 'good' : 'bad');
        }

        return $value <= $great ? 'great' : ($value <= $ok ? 'good' : 'bad');
    }

    // ---- Đầu / chân trang ---------------------------------------------------

    /** @return array{name: string, address: string, division: string, city: string, approver: string} */
    public static function org(): array
    {
        $org = [];
        foreach (self::ORG_DEFAULTS as $key => $default) {
            try {
                $org[$key] = (string) (Setting::get('report.org_' . $key) ?: $default);
            } catch (\Throwable) {
                $org[$key] = $default;
            }
        }

        return $org;
    }

    /** "Hà Nội, ngày 13 tháng 11 năm 2026" theo giờ VN. */
    public static function placeAndDate(?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now(ReportPeriod::TIMEZONE);

        return self::org()['city'] . ', ngày ' . $at->format('d') . ' tháng ' . $at->format('m') . ' năm ' . $at->format('Y');
    }

    /** Ảnh logo raster cho Excel (PhpSpreadsheet không vẽ SVG): dùng nếu có public/brand/logo.png. */
    public static function rasterLogoPath(): ?string
    {
        $path = public_path('brand/logo.png');

        return is_file($path) ? $path : null;
    }

    // ---- Cấu trúc bảng ------------------------------------------------------

    /** Có tiêu đề 2 tầng không (ít nhất một cột thuộc nhóm). */
    public function hasGroups(): bool
    {
        foreach ($this->columns as $column) {
            if (! empty($column['group'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tầng trên của tiêu đề: các khối liền nhau.
     *
     * @return array<int, array{label: string, span: int, grouped: bool, start: int, column?: array}>
     *         grouped = false: cột đơn, gộp dọc 2 tầng
     */
    public function headerBlocks(): array
    {
        $blocks = [];
        foreach (array_values($this->columns) as $i => $column) {
            $group = $column['group'] ?? null;
            $last = $blocks ? count($blocks) - 1 : null;

            if ($group && $last !== null && $blocks[$last]['grouped'] && $blocks[$last]['label'] === $group) {
                $blocks[$last]['span']++;

                continue;
            }

            $blocks[] = $group
                ? ['label' => $group, 'span' => 1, 'grouped' => true, 'start' => $i]
                : ['label' => $column['label'], 'span' => 1, 'grouped' => false, 'start' => $i, 'column' => $column];
        }

        return $blocks;
    }

    public function summaryLine(): string
    {
        return implode('  |  ', $this->summary);
    }

    /** Cắt bớt dòng quá giới hạn, kèm ghi chú. */
    public static function capRows(array $rows, array &$notes): array
    {
        if (count($rows) <= self::MAX_ROWS) {
            return $rows;
        }

        $notes[] = 'Bản mẫu chỉ hiển thị ' . number_format(self::MAX_ROWS, 0, ',', '.') . ' / '
            . number_format(count($rows), 0, ',', '.') . ' dòng đầu — dùng "Xuất dữ liệu" để lấy đủ.';

        return array_slice($rows, 0, self::MAX_ROWS);
    }
}
