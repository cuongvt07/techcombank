<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bổ sung các mốc thời gian mà màn Báo cáo cần nhưng trước đây không lưu.
 *
 * - security_alerts: handled_at bị ghi đè mỗi lần đổi trạng thái, nên tách
 *   acknowledged_at (để tính MTTA) và resolved_at (để tính MTTR).
 * - support_requests: hạn SLA theo độ ưu tiên + thời điểm đóng phiếu.
 * - enrollments: lần nhắc học gần nhất (nút "Gửi nhắc").
 * - quiz_attempt_grants: lượt làm bài cấp thêm cho từng nhân viên.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('handled_at');
            $table->timestamp('resolved_at')->nullable()->after('acknowledged_at');
        });

        // Dữ liệu cũ: handled_at là mốc của lần đổi trạng thái gần nhất
        DB::table('security_alerts')->where('status', 'acknowledged')
            ->update(['acknowledged_at' => DB::raw('handled_at')]);
        DB::table('security_alerts')->whereIn('status', ['resolved', 'false_positive'])
            ->update(['resolved_at' => DB::raw('handled_at')]);

        Schema::table('support_requests', function (Blueprint $table) {
            $table->timestamp('sla_due_at')->nullable()->after('resolved_at');
            $table->timestamp('closed_at')->nullable()->after('sla_due_at');
            $table->index(['status', 'sla_due_at']);
        });

        // SLA theo độ ưu tiên (giờ lịch): Cao 8 giờ, Thường 24 giờ, Thấp 72 giờ
        foreach (['high' => 8, 'normal' => 24, 'low' => 72] as $priority => $hours) {
            DB::table('support_requests')->where('priority', $priority)
                ->update(['sla_due_at' => DB::raw("DATE_ADD(created_at, INTERVAL {$hours} HOUR)")]);
        }

        DB::table('support_requests')->where('status', 'closed')
            ->update(['closed_at' => DB::raw('COALESCE(resolved_at, updated_at)')]);

        Schema::table('enrollments', function (Blueprint $table) {
            $table->timestamp('last_reminded_at')->nullable()->after('last_accessed_at');
        });

        Schema::create('quiz_attempt_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('extra_attempts')->default(1);
            $table->string('reason')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['quiz_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_grants');

        Schema::table('enrollments', fn (Blueprint $table) => $table->dropColumn('last_reminded_at'));

        Schema::table('support_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'sla_due_at']);
            $table->dropColumn(['sla_due_at', 'closed_at']);
        });

        Schema::table('security_alerts', fn (Blueprint $table) => $table->dropColumn(['acknowledged_at', 'resolved_at']));
    }
};
