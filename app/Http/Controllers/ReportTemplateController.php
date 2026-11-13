<?php

namespace App\Http\Controllers;

use App\Services\Reports\TemplateReportService;
use App\Support\Reports\TemplateReportExport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Xuất báo cáo (mẫu)": trang in A4 theo khuôn của Phòng Đào tạo & Phát triển
 * (In / Lưu PDF bằng hộp thoại in của trình duyệt) hoặc file Excel cùng khuôn
 * (?dinh-dang=xlsx). Nhận nguyên query string của màn báo cáo nên giữ đúng bộ lọc.
 *
 * Quyền: đúng quyền Xem của màn báo cáo tương ứng (TemplateReportService::REPORTS).
 */
class ReportTemplateController extends Controller
{
    public function __invoke(Request $request, string $report, TemplateReportService $service): View|BinaryFileResponse
    {
        $permission = TemplateReportService::permission($report);
        abort_unless($permission, 404);
        abort_unless($request->user()?->can($permission), 403, 'Bạn không có quyền xem báo cáo này.');

        $template = $service->build($report, $request->query(), $request->user());

        if ($request->query('dinh-dang') === 'xlsx') {
            return TemplateReportExport::download($template);
        }

        return view('reports.print.template', [
            'report' => $template,
            'excelUrl' => $request->fullUrlWithQuery(['dinh-dang' => 'xlsx']),
        ]);
    }
}
