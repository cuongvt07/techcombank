<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bổ sung sự kiện vào bảng announcements.
 *
 * Bảng đã có sẵn từ trước (spec 3.1.5) nhưng chưa dùng ở đâu. Thay vì tạo bảng
 * mới song song, mở rộng bảng này: cùng là thứ admin đăng cho nhân viên đọc,
 * tách ra chỉ làm phình mô hình dữ liệu.
 *
 * Phạm vi gán rút gọn còn hai: toàn công ty (employee_id NULL) hoặc riêng một
 * nhân viên (employee_id có giá trị). Cột department_id cũ giữ nguyên để không
 * phá dữ liệu sẵn có, nhưng không dùng nữa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // NULL = toàn công ty, có giá trị = chỉ riêng nhân viên đó
            $table->foreignId('employee_id')->nullable()->after('department_id')
                ->constrained()->cascadeOnDelete()
                ->comment('NULL = toàn công ty, có giá trị = riêng nhân viên này');

            $table->dateTime('starts_at')->nullable()->after('employee_id')
                ->comment('Thời gian sự kiện diễn ra');
            $table->dateTime('ends_at')->nullable()->after('starts_at');
            $table->string('location')->nullable()->after('ends_at')
                ->comment('Phòng họp, chi nhánh, hoặc link họp trực tuyến');

            // Truy vấn chính ở site người dùng: lấy sự kiện của tôi + toàn công ty,
            // sắp theo thời gian diễn ra
            $table->index(['employee_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'starts_at']);
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['starts_at', 'ends_at', 'location']);
        });
    }
};
