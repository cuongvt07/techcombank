<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Một điều kiện giao khóa áp cho nhiều phòng ban cùng lúc.
 *
 * Trước đây mỗi điều kiện chỉ giữ một department_id, muốn giao một khóa cho
 * năm phòng phải tạo năm điều kiện giống hệt nhau. Bảng nối thay thế cột đó;
 * cột cũ giữ lại (để rollback được) nhưng dữ liệu được chuyển sang bảng nối
 * và để trống. Không có dòng nào trong bảng nối = mọi phòng ban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_assignment_rule_department', function (Blueprint $table) {
            // Đặt tên khoá ngoại ngắn: tên tự sinh vượt giới hạn 64 ký tự của MySQL
            $table->foreignId('course_assignment_rule_id')
                ->constrained(indexName: 'card_rule_foreign')->cascadeOnDelete();
            $table->foreignId('department_id')
                ->constrained(indexName: 'card_department_foreign')->cascadeOnDelete();

            $table->primary(['course_assignment_rule_id', 'department_id'], 'card_primary');
            $table->index('department_id');
        });

        // Chuyển phòng ban đang có của từng điều kiện sang bảng nối
        DB::table('course_assignment_rules')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->each(function ($rule) {
                DB::table('course_assignment_rule_department')->insertOrIgnore([
                    'course_assignment_rule_id' => $rule->id,
                    'department_id' => $rule->department_id,
                ]);
            });

        DB::table('course_assignment_rules')->update(['department_id' => null]);
    }

    public function down(): void
    {
        // Điều kiện nhiều phòng ban chỉ giữ lại được một: lấy phòng ban đầu tiên
        DB::table('course_assignment_rule_department')
            ->orderBy('department_id')
            ->get()
            ->groupBy('course_assignment_rule_id')
            ->each(function ($rows, $ruleId) {
                DB::table('course_assignment_rules')
                    ->where('id', $ruleId)
                    ->update(['department_id' => $rows->first()->department_id]);
            });

        Schema::dropIfExists('course_assignment_rule_department');
    }
};
