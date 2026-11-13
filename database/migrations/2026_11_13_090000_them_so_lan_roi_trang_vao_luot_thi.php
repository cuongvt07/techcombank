<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ghi nhận số lần nhân viên rời khỏi tab trình duyệt khi đang làm bài kiểm tra.
 * Chỉ để tham khảo khi rà soát kết quả (không tự động hủy bài).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('quiz_attempts', 'tab_leave_count')) {
            return;
        }

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('tab_leave_count')->default(0)->after('ip_address')
                ->comment('Số lần rời tab trình duyệt khi đang làm bài');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('quiz_attempts', 'tab_leave_count')) {
            Schema::table('quiz_attempts', function (Blueprint $table) {
                $table->dropColumn('tab_leave_count');
            });
        }
    }
};
