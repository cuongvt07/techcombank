<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Một quy tắc phân quyền tài liệu áp dụng cho nhiều phòng ban cùng lúc.
 *
 * Trước đây mỗi quy tắc chỉ giữ một department_id, muốn mở cho ba phòng ban
 * phải tạo ba quy tắc giống hệt nhau. Bảng nối thay thế cột đó; cột cũ giữ
 * lại (để rollback được) nhưng dữ liệu được chuyển sang bảng nối và để trống.
 * Không chọn phòng ban nào = toàn công ty, như cũ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_rule_department', function (Blueprint $table) {
            $table->foreignId('document_access_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();

            $table->primary(['document_access_rule_id', 'department_id'], 'dard_primary');
            $table->index('department_id');
        });

        // Chuyển phòng ban đang có của từng quy tắc sang bảng nối
        DB::table('document_access_rules')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->each(function ($rule) {
                DB::table('document_access_rule_department')->insertOrIgnore([
                    'document_access_rule_id' => $rule->id,
                    'department_id' => $rule->department_id,
                ]);
            });

        DB::table('document_access_rules')->update(['department_id' => null]);
    }

    public function down(): void
    {
        // Quy tắc nhiều phòng ban chỉ giữ lại được một: lấy phòng ban đầu tiên
        DB::table('document_access_rule_department')
            ->orderBy('department_id')
            ->get()
            ->groupBy('document_access_rule_id')
            ->each(function ($rows, $ruleId) {
                DB::table('document_access_rules')
                    ->where('id', $ruleId)
                    ->update(['department_id' => $rows->first()->department_id]);
            });

        Schema::dropIfExists('document_access_rule_department');
    }
};
