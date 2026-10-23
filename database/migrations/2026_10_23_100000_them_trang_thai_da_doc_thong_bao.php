<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chuông thông báo ở site nhân viên.
 *
 * - announcement_reads: nhân viên nào đã xem thông báo nào (đếm "chưa đọc").
 *   Lưu theo employee chứ không theo user vì thông báo vốn gán cho employee.
 * - announcements.course_id: lời nhắc học / nhắc làm lại gắn với khóa nào, để
 *   bấm từ chuông đi thẳng tới khóa đó thay vì đoán từ tiêu đề.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['announcement_id', 'employee_id']);
            // Truy vấn "chưa đọc" đi từ phía nhân viên
            $table->index(['employee_id', 'announcement_id']);
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->after('employee_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_id');
        });

        Schema::dropIfExists('announcement_reads');
    }
};
