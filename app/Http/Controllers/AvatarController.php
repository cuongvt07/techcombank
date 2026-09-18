<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phát ảnh đại diện nhân viên.
 *
 * File nằm ngoài public/ nên đây là đường duy nhất truy cập được. Chỉ người đã
 * đăng nhập mới xem được — ảnh nhân viên là dữ liệu nội bộ, không để lộ ra
 * ngoài qua đường dẫn đoán được.
 */
class AvatarController extends Controller
{
    public function show(Request $request, Employee $employee): StreamedResponse
    {
        abort_unless($request->user(), 403);

        $file = $employee->avatarFile;

        abort_unless($file && $file->exists(), 404);

        return Storage::disk($file->disk)->response($file->path, $file->name, [
            // Ảnh đại diện đổi không thường xuyên; cho trình duyệt giữ 1 ngày để
            // không phải tải lại ở mọi trang. private = không cho proxy chung
            // lưu hộ, tránh người khác nhận nhầm ảnh từ cache.
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
