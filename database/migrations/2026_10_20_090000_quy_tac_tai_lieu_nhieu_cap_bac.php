<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Một quy tắc phân quyền tài liệu áp dụng cho nhiều cấp bậc cùng lúc.
 *
 * Trước đây mỗi quy tắc chỉ giữ một job_grade_id, muốn cho ba cấp bậc xem
 * phải tạo ba quy tắc giống hệt nhau. Bảng nối thay thế cột đó; cột cũ giữ
 * lại (để rollback được) nhưng dữ liệu được chuyển sang bảng nối và để trống.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_rule_job_grade', function (Blueprint $table) {
            $table->foreignId('document_access_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_grade_id')->constrained()->cascadeOnDelete();

            $table->primary(['document_access_rule_id', 'job_grade_id'], 'darjg_primary');
            $table->index('job_grade_id');
        });

        // Chuyển cấp bậc đang có của từng quy tắc sang bảng nối
        DB::table('document_access_rules')
            ->whereNotNull('job_grade_id')
            ->orderBy('id')
            ->each(function ($rule) {
                DB::table('document_access_rule_job_grade')->insertOrIgnore([
                    'document_access_rule_id' => $rule->id,
                    'job_grade_id' => $rule->job_grade_id,
                ]);
            });

        DB::table('document_access_rules')->update(['job_grade_id' => null]);
    }

    public function down(): void
    {
        // Quy tắc nhiều cấp bậc chỉ giữ lại được một: lấy cấp bậc đầu tiên
        DB::table('document_access_rule_job_grade')
            ->orderBy('job_grade_id')
            ->get()
            ->groupBy('document_access_rule_id')
            ->each(function ($rows, $ruleId) {
                DB::table('document_access_rules')
                    ->where('id', $ruleId)
                    ->update(['job_grade_id' => $rows->first()->job_grade_id]);
            });

        Schema::dropIfExists('document_access_rule_job_grade');
    }
};
