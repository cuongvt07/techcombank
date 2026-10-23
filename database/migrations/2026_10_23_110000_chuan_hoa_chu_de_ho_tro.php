<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chuẩn hóa chủ đề phiếu hỗ trợ về một danh sách duy nhất (SupportRequest::TOPICS).
 *
 * Trước đây form nhân viên lấy chủ đề từ bảng đầu mối liên hệ còn dữ liệu demo dùng
 * tên khác, nên báo cáo hiện chủ đề không có trong form ("Hợp đồng & hồ sơ" vs
 * "Hợp đồng & chế độ"). Module Hợp đồng đã bỏ nên gộp về "Hồ sơ & chế độ".
 */
return new class extends Migration
{
    private const MAP = [
        'Hợp đồng & chế độ' => 'Hồ sơ & chế độ',
        'Hợp đồng & hồ sơ' => 'Hồ sơ & chế độ',
        'Sự cố kỹ thuật' => 'Kỹ thuật, trình duyệt',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('support_requests')->where('topic', $old)->update(['topic' => $new]);
            DB::table('support_contacts')->where('topic', $old)->update(['topic' => $new]);
        }
    }

    public function down(): void
    {
        // Không tách ngược được (hai tên cũ đã gộp làm một) — giữ nguyên dữ liệu
    }
};
