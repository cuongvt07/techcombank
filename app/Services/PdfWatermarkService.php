<?php

namespace App\Services;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use Throwable;

/**
 * Đóng watermark vào file PDF khi nhân viên tải về (spec 3.3.2).
 *
 * Khác với watermark khi xem — vốn chỉ là lớp phủ HTML, mất ngay khi tải file
 * gốc — dấu ở đây ghi thẳng vào từng trang PDF. Bản rò rỉ ra ngoài vẫn mang tên
 * và mã truy vết của người đã tải.
 *
 * Giới hạn phải nói rõ với đội bảo mật:
 *  - Chỉ làm được với PDF. Word/Excel là định dạng nén, không chèn được text
 *    mà không dựng lại cả file.
 *  - PDF có mật khẩu hoặc dùng tính năng nén lạ thì FPDI không mở được; lúc đó
 *    trả về null để tầng gọi tự quyết định chặn hay cho tải bản gốc.
 *  - Watermark ghi vào nội dung, không phải chữ ký số: người có công cụ chỉnh
 *    PDF vẫn xóa được. Đây là biện pháp truy vết, không phải khóa cứng.
 */
class PdfWatermarkService
{
    /** Cỡ chữ watermark. */
    private const FONT_SIZE = 9;

    /** Độ mờ mô phỏng bằng màu xám nhạt — FPDF không có alpha channel. */
    private const GRAY = 190;

    /**
     * Đóng watermark vào một file PDF.
     *
     * @param  string  $sourcePath  Đường dẫn tuyệt đối tới PDF gốc
     * @param  array<int, string>  $lines  Các dòng chữ đóng lên trang
     * @return string|null Đường dẫn file tạm đã đóng dấu, hoặc null nếu không xử lý được
     */
    public function stamp(string $sourcePath, array $lines): ?string
    {
        $lines = array_values(array_filter(array_map('trim', $lines)));

        if ($lines === [] || ! is_file($sourcePath)) {
            return null;
        }

        try {
            return $this->doStamp($sourcePath, $lines);
        } catch (CrossReferenceException|PdfParserException $e) {
            // PDF khóa mật khẩu hoặc cấu trúc FPDI không đọc được
            report($e);

            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function doStamp(string $sourcePath, array $lines): ?string
    {
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($sourcePath);

        if ($pageCount < 1) {
            return null;
        }

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);

            $this->drawWatermark($pdf, $size['width'], $size['height'], $lines);
        }

        $target = tempnam(sys_get_temp_dir(), 'wm_') . '.pdf';
        $pdf->Output('F', $target);

        return is_file($target) ? $target : null;
    }

    /**
     * Vẽ watermark lên trang hiện tại.
     *
     * Lặp chéo khắp trang thay vì đóng một dấu ở giữa: cắt ảnh một góc trang
     * vẫn còn dấu ở góc khác, nên không xoá được bằng cách crop.
     */
    private function drawWatermark(Fpdi $pdf, float $width, float $height, array $lines): void
    {
        // Tắt tự động sang trang: Cell() vẽ gần đáy trang sẽ khiến FPDF chèn
        // thêm trang mới, PDF 2 trang phình thành 18 trang.
        $pdf->SetAutoPageBreak(false);

        $pdf->SetFont('Helvetica', '', self::FONT_SIZE);
        $pdf->SetTextColor(self::GRAY, self::GRAY, self::GRAY);

        $block = $this->toLatin(implode('   |   ', $lines));

        // Khoảng cách giữa các dấu: đủ dày để không cắt bỏ được, đủ thưa để
        // vẫn đọc được nội dung bên dưới
        $stepX = 95;
        $stepY = 55;

        // Dừng trước lề dưới để chữ không bị tràn khỏi trang
        for ($y = 18; $y < $height - 8; $y += $stepY) {
            for ($x = -20; $x < $width; $x += $stepX) {
                $pdf->SetXY($x, $y);
                $pdf->Cell(60, 4, $block, 0, 0, 'L');
            }
        }

        // Dấu chính giữa, đậm hơn, để người xem biết bản này có truy vết
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetXY(0, $height / 2);
        $pdf->Cell($width, 6, $this->toLatin($lines[0]), 0, 0, 'C');
    }

    /**
     * Chuyển tiếng Việt sang dạng không dấu.
     *
     * FPDF dùng font core chỉ hỗ trợ Latin-1, chữ có dấu sẽ ra ký tự lỗi. Bỏ dấu
     * vẫn đọc được tên người và giữ nguyên mã truy vết — thứ quan trọng nhất khi
     * điều tra rò rỉ.
     */
    private function toLatin(string $text): string
    {
        $map = [
            'à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
            'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
            'ì','í','ị','ỉ','ĩ',
            'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
            'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ',
            'ỳ','ý','ỵ','ỷ','ỹ','đ',
            'À','Á','Ạ','Ả','Ã','Â','Ầ','Ấ','Ậ','Ẩ','Ẫ','Ă','Ằ','Ắ','Ặ','Ẳ','Ẵ',
            'È','É','Ẹ','Ẻ','Ẽ','Ê','Ề','Ế','Ệ','Ể','Ễ',
            'Ì','Í','Ị','Ỉ','Ĩ',
            'Ò','Ó','Ọ','Ỏ','Õ','Ô','Ồ','Ố','Ộ','Ổ','Ỗ','Ơ','Ờ','Ớ','Ợ','Ở','Ỡ',
            'Ù','Ú','Ụ','Ủ','Ũ','Ư','Ừ','Ứ','Ự','Ử','Ữ',
            'Ỳ','Ý','Ỵ','Ỷ','Ỹ','Đ',
        ];

        $plain = [
            'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
            'e','e','e','e','e','e','e','e','e','e','e',
            'i','i','i','i','i',
            'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
            'u','u','u','u','u','u','u','u','u','u','u',
            'y','y','y','y','y','d',
            'A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A',
            'E','E','E','E','E','E','E','E','E','E','E',
            'I','I','I','I','I',
            'O','O','O','O','O','O','O','O','O','O','O','O','O','O','O','O','O',
            'U','U','U','U','U','U','U','U','U','U','U',
            'Y','Y','Y','Y','Y','D',
        ];

        return str_replace($map, $plain, $text);
    }
}
