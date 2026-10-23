<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thông báo gửi tới TÀI KHOẢN (phân công phiếu hỗ trợ, phiếu được xử lý, cấp
 * thêm lượt làm bài...). Người nhận có thể là quản trị viên không có hồ sơ nhân
 * viên nên dùng bảng notifications chuẩn của Laravel (read_at = đã đọc).
 *
 * announcements.quiz_id: lời nhắc làm lại gắn bài kiểm tra — để chặn nhắc lặp
 * cùng bài trong thời gian chờ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->foreignId('quiz_id')->nullable()->after('course_id')
                ->constrained()->nullOnDelete();
            $table->index(['employee_id', 'quiz_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'quiz_id', 'published_at']);
            $table->dropConstrainedForeignId('quiz_id');
        });

        Schema::dropIfExists('notifications');
    }
};
