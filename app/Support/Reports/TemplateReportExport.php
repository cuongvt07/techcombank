<?php

namespace App\Support\Reports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * File Excel "theo mẫu" của một TemplateReport: cùng bố cục với trang in
 * (đầu trang ngân hàng, tiêu đề, tóm tắt, bảng tiêu đề đỏ gộp ô 2 tầng, dòng
 * Tổng cộng, chân ký), đặt sẵn khổ A4 / vừa một trang ngang / lặp tiêu đề bảng
 * mỗi trang để mở ra là in được.
 *
 * Toàn bộ sheet dựng trong AfterSheet bằng PhpSpreadsheet; FromArray trả rỗng
 * chỉ để maatwebsite/excel tạo sheet.
 */
final class TemplateReportExport implements FromArray, WithEvents, WithTitle
{
    private const LINE = 'D1D5DB';

    /** Dòng bắt đầu tiêu đề bảng (để test / định dạng tham chiếu). */
    public const HEADER_ROW = 10;

    public function __construct(private readonly TemplateReport $report)
    {
    }

    public static function download(TemplateReport $report): BinaryFileResponse
    {
        $file = $report->fileBase . '-mau-' . now(ReportPeriod::TIMEZONE)->format('Ymd-Hi') . '.xlsx';

        return Excel::download(new self($report), $file, \Maatwebsite\Excel\Excel::XLSX);
    }

    public function array(): array
    {
        return [];
    }

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $this->report->sheetTitle), 0, 31);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate()),
        ];
    }

    public function build(Worksheet $sheet): void
    {
        $r = $this->report;
        $n = max(3, count($r->columns));
        $last = $this->col($n);
        $org = TemplateReport::org();

        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(11);
        $sheet->setShowGridlines(false);

        // ---- Đầu trang: tên ngân hàng / địa chỉ / khối — logo bên phải --------
        $textEnd = $this->col($n - 2);
        foreach ([1 => $org['name'], 2 => $org['address'], 3 => $org['division']] as $row => $text) {
            $sheet->mergeCells("A{$row}:{$textEnd}{$row}");
            $this->setText($sheet, "A{$row}", $text);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB(TemplateReport::BRAND_RED);
        $sheet->getStyle('A2:A3')->getFont()->setSize(10)->getColor()->setRGB('4B5563');

        $logoRange = $this->col($n - 1) . '1:' . $last . '2';
        $sheet->mergeCells($logoRange);
        if ($logo = TemplateReport::rasterLogoPath()) {
            $drawing = new Drawing();
            $drawing->setPath($logo);
            // Logo đứng (hình thoi + chữ) chiếm 2 hàng đầu trang ~40px
            $drawing->setHeight(36);
            $drawing->setCoordinates($this->col($n - 1) . '1');
            $drawing->setWorksheet($sheet);
        } else {
            $this->setText($sheet, $this->col($n - 1) . '1', 'TECHCOMBANK');
            $sheet->getStyle($logoRange)->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(TemplateReport::BRAND_RED);
            $sheet->getStyle($logoRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
        }

        // Đường kẻ đỏ đôi dưới đầu trang
        $sheet->getStyle("A3:{$last}3")->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_DOUBLE)->getColor()->setRGB(TemplateReport::BRAND_RED);

        // ---- Tiêu đề + kỳ / đơn vị ----------------------------------------------
        $sheet->mergeCells("A5:{$last}5");
        $this->setText($sheet, 'A5', mb_strtoupper($r->title));
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(15);
        $sheet->getRowDimension(5)->setRowHeight(24);

        $sheet->mergeCells("A6:{$last}6");
        $this->setText($sheet, 'A6', 'Kỳ báo cáo: ' . $r->periodLabel . '  |  Đơn vị: ' . $r->unitLabel);
        $sheet->getStyle('A6')->getFont()->setSize(10);
        $sheet->getStyle('A5:A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ---- Dòng tóm tắt trong hộp viền xanh ----------------------------------
        if ($r->summary !== []) {
            $sheet->mergeCells("A8:{$last}8");
            $this->setText($sheet, 'A8', $r->summaryLine());
            $box = $sheet->getStyle("A8:{$last}8");
            $box->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('1B5E20');
            $box->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF7EE');
            $box->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('7CB342');
            $box->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $sheet->getRowDimension(8)->setRowHeight(max(22, 15 * (int) ceil(mb_strlen($r->summaryLine()) / max(40, $n * 12))));
        }

        // ---- Tiêu đề bảng (1 hoặc 2 tầng) ----------------------------------------
        $h = self::HEADER_ROW;
        $two = $r->hasGroups();
        $headerEnd = $two ? $h + 1 : $h;

        foreach ($r->headerBlocks() as $block) {
            $from = $block['start'] + 1;
            $to = $from + $block['span'] - 1;

            if ($block['grouped']) {
                $sheet->mergeCells($this->col($from) . $h . ':' . $this->col($to) . $h);
                $this->setText($sheet, $this->col($from) . $h, $block['label']);
                foreach (range($from, $to) as $c) {
                    $this->setText($sheet, $this->col($c) . ($h + 1), $r->columns[$c - 1]['label']);
                }
            } else {
                if ($two) {
                    $sheet->mergeCells($this->col($from) . $h . ':' . $this->col($from) . ($h + 1));
                }
                $this->setText($sheet, $this->col($from) . $h, $block['label']);
            }
        }

        $head = $sheet->getStyle("A{$h}:{$last}{$headerEnd}");
        $head->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(TemplateReport::BRAND_RED);
        $head->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $head->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
        foreach (range($h, $headerEnd) as $row) {
            $sheet->getRowDimension($row)->setRowHeight(22);
        }

        // ---- Dữ liệu --------------------------------------------------------------
        $row = $headerEnd + 1;
        $dataStart = $row;

        if ($r->rows === []) {
            $sheet->mergeCells("A{$row}:{$last}{$row}");
            $this->setText($sheet, "A{$row}", $r->emptyText);
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setRGB('6B7280');
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        foreach ($r->rows as $cells) {
            $this->writeRow($sheet, $row, $cells);
            $row++;
        }

        if ($row > $dataStart) {
            $body = $sheet->getStyle("A{$dataStart}:{$last}" . ($row - 1));
            $body->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::LINE);
            $body->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $body->getFont()->setSize(10);
        }

        // ---- Tổng cộng ------------------------------------------------------------
        if ($r->totals !== null) {
            $this->writeRow($sheet, $row, $r->totals);
            $total = $sheet->getStyle("A{$row}:{$last}{$row}");
            $total->getFont()->setBold(true)->setSize(10)->getColor()->setRGB(TemplateReport::BRAND_RED);
            $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF2F2');
            $total->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::LINE);
            $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(TemplateReport::BRAND_RED);
            $total->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $row++;
        }

        // ---- Ghi chú ----------------------------------------------------------------
        foreach ($r->notes as $note) {
            $row++;
            $sheet->mergeCells("A{$row}:{$last}{$row}");
            $this->setText($sheet, "A{$row}", $note);
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('4B5563');
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(max(15, 13 * (int) ceil(mb_strlen($note) / max(60, $n * 14))));
        }

        // ---- Chân ký 3 cột ---------------------------------------------------------
        $sign = $row + 2;
        [$left, $middle, $right] = self::signatureBlocks($n);
        $place = fn (array $cols, int $at, string $text, bool $bold = false, bool $italic = false) => $this->signText($sheet, $cols, $at, $text, $bold, $italic);

        $place($left, $sign, 'Người lập báo cáo', true);
        $place($left, $sign + 1, '(Ký, ghi rõ họ tên)', false, true);
        $place($middle, $sign, TemplateReport::placeAndDate(), true);
        $place($right, $sign, $org['approver'], true);
        $place($right, $sign + 1, '(Ký, ghi rõ họ tên)', false, true);
        if ($r->preparedBy) {
            $place($left, $sign + 5, $r->preparedBy, false, true);
        }
        $lastRow = $sign + 5;

        // ---- Độ rộng cột + thiết lập in --------------------------------------------
        foreach (array_values($r->columns) as $i => $column) {
            $sheet->getColumnDimension($this->col($i + 1))->setWidth($column['width'] ?? 14);
        }

        $setup = $sheet->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setOrientation($r->landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);
        $setup->setHorizontalCentered(true);
        $setup->setRowsToRepeatAtTopByStartAndEnd($h, $headerEnd);
        $setup->setPrintArea("A1:{$last}{$lastRow}");

        $margins = $sheet->getPageMargins();
        $margins->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);

        $title = str_replace('&', '&&', $r->title);
        $sheet->getHeaderFooter()->setOddFooter('&L&8' . $title . '&R&8Trang &P / &N');

        $sheet->freezePane('A' . $dataStart);
        $sheet->setSelectedCell('A1');
    }

    /**
     * Chia cột cho 3 khối chân ký: trái (người lập), giữa (nơi, ngày), phải (người duyệt).
     *
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}, 2: array{0: int, 1: int}}
     */
    public static function signatureBlocks(int $n): array
    {
        $side = max(1, intdiv($n, 3));

        return [[1, $side], [$side + 1, max($side + 1, $n - $side)], [min($n, $n - $side + 1), $n]];
    }

    /** @param  array<int, array<string, mixed>>  $cells */
    private function writeRow(Worksheet $sheet, int $row, array $cells): void
    {
        $c = 1;
        foreach ($cells as $cell) {
            $span = max(1, (int) ($cell['span'] ?? 1));
            $coord = $this->col($c) . $row;

            if ($span > 1) {
                $sheet->mergeCells($coord . ':' . $this->col($c + $span - 1) . $row);
            }

            if ($cell['value'] !== null) {
                $sheet->setCellValueExplicit($coord, $cell['value'], DataType::TYPE_NUMERIC);
                if ($cell['format']) {
                    $sheet->getStyle($coord)->getNumberFormat()->setFormatCode($cell['format']);
                }
            } else {
                $this->setText($sheet, $coord, $cell['text']);
            }

            $style = $sheet->getStyle($coord);
            $style->getAlignment()->setHorizontal(match ($cell['align'] ?? null) {
                'center' => Alignment::HORIZONTAL_CENTER,
                'right' => Alignment::HORIZONTAL_RIGHT,
                default => Alignment::HORIZONTAL_LEFT,
            });
            if (! empty($cell['bold'])) {
                $style->getFont()->setBold(true);
            }
            if (! empty($cell['tone']) && isset(TemplateReport::TONES[$cell['tone']])) {
                $style->getFont()->setBold(true)->getColor()->setRGB(TemplateReport::TONES[$cell['tone']]);
            }

            $c += $span;
        }
    }

    /** @param  array{0: int, 1: int}  $cols */
    private function signText(Worksheet $sheet, array $cols, int $row, string $text, bool $bold, bool $italic): void
    {
        $from = $this->col($cols[0]) . $row;
        if ($cols[1] > $cols[0]) {
            $sheet->mergeCells($from . ':' . $this->col($cols[1]) . $row);
        }
        $this->setText($sheet, $from, $text);
        $style = $sheet->getStyle($from);
        $style->getFont()->setBold($bold)->setItalic($italic)->setSize(10);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /** Luôn ghi chuỗi dạng text: chặn nội dung bắt đầu bằng "=" bị Excel hiểu là công thức. */
    private function setText(Worksheet $sheet, string $coord, string $text): void
    {
        $sheet->setCellValueExplicit($coord, $text, DataType::TYPE_STRING);
    }

    private function col(int $index): string
    {
        return Coordinate::stringFromColumnIndex(max(1, $index));
    }
}
