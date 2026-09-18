<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Employee;
use App\Services\DocumentAccessService;
use Illuminate\Http\Request;
use App\Services\PdfWatermarkService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phát file tài liệu cho nhân viên (spec 3.3.2).
 *
 * File nằm ngoài thư mục public nên KHÔNG có đường truy cập trực tiếp — mọi lượt
 * xem đều phải đi qua đây, nơi quyền được kiểm tra lại và lượt truy cập được ghi log.
 * Nếu để file trong public/, ai có link là xem được, mọi lớp phân quyền phía trên
 * trở thành vô nghĩa.
 */
class DocumentFileController extends Controller
{
    public function __construct(private readonly DocumentAccessService $access)
    {
    }

    /** Xem trực tuyến — trả inline để trình duyệt mở luôn, không tải về. */
    public function stream(Request $request, Document $document): Response
    {
        return $this->serve($request, $document, inline: true);
    }

    /** Tải bản gốc — chỉ khi cả rule và cờ của tài liệu đều cho phép. */
    public function download(Request $request, Document $document): Response
    {
        return $this->serve($request, $document, inline: false);
    }

    private function serve(Request $request, Document $document, bool $inline): Response
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            abort(403, 'Tài khoản chưa gắn hồ sơ nhân sự.');
        }

        $resolved = $this->access->resolve($employee, $document);

        if (! $resolved['can_view']) {
            // Ghi cả lượt bị từ chối: chuỗi lượt từ chối liên tiếp là dấu hiệu
            // dò tìm tài liệu, cần phát hiện được (spec 3.3.2)
            $this->access->logAccess(
                $employee,
                $document,
                DocumentAccessLog::ACTION_DENIED,
                $resolved['reason'],
            );

            abort(403, 'Bạn không có quyền xem tài liệu này.');
        }

        // Cờ allow_download của tài liệu là trần cứng, rule không nới rộng được
        if (! $inline && ! ($resolved['can_download'] && $document->allow_download)) {
            $this->access->logAccess(
                $employee,
                $document,
                DocumentAccessLog::ACTION_DENIED,
                'Tài liệu không cho phép tải bản gốc',
            );

            abort(403, 'Tài liệu này chỉ được xem trực tuyến.');
        }

        $storedFile = $document->currentVersion?->storedFile;

        if (! $storedFile || ! $storedFile->exists()) {
            abort(404, 'File không còn tồn tại trên hệ thống.');
        }

        $this->access->logAccess(
            $employee,
            $document,
            $inline
                ? ($document->isVideo() ? DocumentAccessLog::ACTION_STREAM : DocumentAccessLog::ACTION_VIEW)
                : DocumentAccessLog::ACTION_DOWNLOAD,
        );

        return $this->fileResponse($storedFile, $document, $employee, $inline);
    }

    private function fileResponse($storedFile, Document $document, Employee $employee, bool $inline)
    {
        $disk = Storage::disk($storedFile->disk);

        $headers = [
            'Content-Type' => $storedFile->mime_type ?: 'application/octet-stream',
            // Chặn cache ở proxy trung gian: tài liệu nội bộ không được nằm lại
            // trên máy khác sau khi người dùng đóng phiên
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($document->enable_watermark) {
            // Mã truy vết gắn vào header, khớp với watermark_token trong log —
            // bản rò rỉ có header này truy ngược được về đúng phiên xem
            $headers['X-Access-Token'] = $this->access->watermarkToken($employee, $document);
        }

        // Tải PDF về mà tài liệu bật watermark: đóng dấu thẳng vào file thay vì
        // gửi bản gốc. Watermark khi xem chỉ là lớp phủ HTML, tải xuống là mất.
        if (! $inline && $document->enable_watermark && $this->isPdf($storedFile)) {
            $stamped = app(PdfWatermarkService::class)->stamp(
                $disk->path($storedFile->path),
                [
                    $this->access->watermarkText($employee),
                    'Ma truy vet: ' . $this->access->watermarkToken($employee, $document),
                ],
            );

            if ($stamped) {
                // deleteFileAfterSend: file tạm tự xoá sau khi gửi xong
                return response()->download($stamped, $storedFile->name, $headers)
                    ->deleteFileAfterSend(true);
            }

            // Không đóng dấu được (PDF khoá mật khẩu, cấu trúc lạ). Ghi log rồi
            // vẫn cho tải bản gốc: chặn ở đây sẽ khiến tài liệu hợp lệ không tải
            // được, trong khi log truy cập vẫn ghi được ai đã tải.
            Log::warning('Không đóng được watermark vào PDF, gửi bản gốc', [
                'document_id' => $document->id,
                'employee_id' => $employee->id,
            ]);
        }

        return $inline
            ? $disk->response($storedFile->path, $storedFile->name, $headers)
            : $disk->download($storedFile->path, $storedFile->name, $headers);
    }

    private function isPdf($storedFile): bool
    {
        return $storedFile->mime_type === 'application/pdf'
            || str_ends_with(mb_strtolower((string) $storedFile->name), '.pdf');
    }
}
