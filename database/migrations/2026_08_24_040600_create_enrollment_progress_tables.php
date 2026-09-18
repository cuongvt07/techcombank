<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gán khoá học và theo dõi tiến độ (spec 3.2.4 + 4.2).
 *
 * Hai cơ chế gán song song:
 *  - course_assignment_rules: gán động theo điều kiện phòng ban/vị trí/cấp bậc,
 *    tự áp dụng lại khi nhân sự đổi vị trí (spec 3.3.1).
 *  - enrollments: bản ghi thực tế của từng nhân viên, sinh từ rule hoặc gán tay.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Điều kiện gán khoá học tự động (spec 3.2.4: bắt buộc/tự chọn theo vị trí, phòng ban)
        Schema::create('course_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();

            // Điều kiện, NULL = không ràng buộc chiều đó
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('employment_status', 30)->nullable();
            $table->boolean('include_sub_departments')->default(true)
                ->comment('Áp dụng cho cả phòng ban con trong cây tổ chức');

            $table->boolean('is_mandatory')->default(true)->comment('Bắt buộc hay tự chọn');
            $table->unsignedSmallInteger('due_days')->nullable()->comment('Hạn hoàn thành, số ngày kể từ khi gán');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['course_id', 'is_active']);
            $table->index('department_id');
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_assignment_rule_id')->nullable()->constrained()->nullOnDelete()
                ->comment('NULL = gán thủ công bởi admin');

            $table->boolean('is_mandatory')->default(true);
            $table->enum('status', ['not_started', 'in_progress', 'completed', 'overdue', 'cancelled'])
                ->default('not_started');

            // % tiến độ tính trên số bài học bắt buộc đã hoàn thành (spec 3.2.4)
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->unsignedInteger('completed_lessons')->default(0);
            $table->unsignedInteger('total_lessons')->default(0);
            $table->decimal('final_score', 5, 2)->nullable();

            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('last_accessed_at')->nullable();

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'employee_id']);
            $table->index(['employee_id', 'status']);
            // Phục vụ job quét khoá học quá hạn
            $table->index(['status', 'due_date']);
        });

        // Tiến độ từng bài học - nguồn tính progress_percent của enrollment
        Schema::create('lesson_progresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');
            // Vị trí xem video gần nhất, để "học tiếp từ chỗ đang dở" (spec 3.2.2)
            $table->unsignedInteger('last_position_second')->nullable();
            $table->decimal('watch_percent', 5, 2)->default(0);
            $table->unsignedInteger('time_spent_seconds')->default(0);

            $table->timestamp('first_accessed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'lesson_id']);
            $table->index(['employee_id', 'status']);
        });

        // Chứng nhận hoàn thành khoá học (spec 4.2, mục 8 câu hỏi 3)
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('certificate_no', 100)->unique();
            $table->foreignId('enrollment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamp('issued_at');
            $table->date('valid_until')->nullable()->comment('Chứng nhận có thời hạn, cần học lại khi hết hạn');
            $table->string('verification_code', 64)->unique()->comment('Mã tra cứu tính xác thực của chứng nhận');
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('lesson_progresses');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('course_assignment_rules');
    }
};
