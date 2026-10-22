<?php

namespace App\Support\Reports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Xuất bảng báo cáo ra Excel (.xlsx) hoặc CSV.
 *
 * Mỗi báo cáo truyền tiêu đề cột + các dòng đã định dạng sẵn (ngày theo giờ VN,
 * phần trăm đã làm tròn) — file xuất khớp với những gì người dùng thấy trên màn.
 */
final class ReportExport implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
        private readonly string $sheetTitle = 'Báo cáo',
    ) {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        // Tên sheet Excel tối đa 31 ký tự, không chứa ký tự đặc biệt
        return mb_substr(preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $this->sheetTitle), 0, 31);
    }

    /**
     * @param  string  $format  xlsx | csv
     */
    public static function download(string $fileBase, array $headings, array $rows, string $format = 'xlsx', string $sheetTitle = 'Báo cáo'): BinaryFileResponse
    {
        $format = $format === 'csv' ? 'csv' : 'xlsx';
        $file = $fileBase . '-' . now(ReportPeriod::TIMEZONE)->format('Ymd-Hi') . '.' . $format;

        return Excel::download(
            new self($headings, $rows, $sheetTitle),
            $file,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX,
        );
    }
}
