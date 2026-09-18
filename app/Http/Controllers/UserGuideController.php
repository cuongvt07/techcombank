<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\UserGuideService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tải tài liệu hướng dẫn sử dụng hệ thống.
 *
 * Sinh PDF tại thời điểm tải chứ không lưu file cố định: nội dung luôn khớp
 * cấu hình hiện tại, và đóng được watermark tên người tải.
 */
class UserGuideController extends Controller
{
    public function __invoke(Request $request, string $audience = UserGuideService::AUDIENCE_EMPLOYEE): BinaryFileResponse
    {
        abort_unless((bool) Setting::get('guide.enabled', true), 404, 'Tài liệu hướng dẫn đang tạm tắt.');

        $user = $request->user();
        abort_unless($user, 403);

        // Bản dành cho quản trị chỉ người có quyền vào site quản trị mới tải được:
        // nội dung nêu chi tiết cấu hình bảo mật và phân quyền
        if ($audience === UserGuideService::AUDIENCE_ADMIN) {
            abort_unless($user->isAdmin(), 403, 'Bạn không có quyền tải tài liệu này.');
        } else {
            $audience = UserGuideService::AUDIENCE_EMPLOYEE;
        }

        $service = app(UserGuideService::class);

        $path = $service->generate(
            $audience,
            $user->employee,
            (bool) Setting::get('guide.watermark', true),
        );

        return response()
            ->download($path, $service->fileName($audience), [
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend(true);
    }
}
