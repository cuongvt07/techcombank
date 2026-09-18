<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểm tra trắc nghiệm (spec 3.2.3): ngân hàng câu hỏi + cấu hình đề thi + lịch sử làm bài.
 * Câu hỏi tách khỏi lượt thi để một đề rút ngẫu nhiên N câu từ ngân hàng,
 * và để giữ nguyên lịch sử kết quả kể cả khi câu hỏi bị sửa về sau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete()
                ->comment('NULL = bài kiểm tra dùng chung, không thuộc khoá cụ thể');

            // Cấu hình đề (spec 3.2.3)
            $table->unsignedSmallInteger('questions_per_attempt')->nullable()
                ->comment('Số câu rút ra mỗi lượt; NULL = dùng toàn bộ câu hỏi');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->comment('NULL = không giới hạn thời gian');
            $table->unsignedSmallInteger('pass_score')->default(70)->comment('Điểm đạt, tính theo %');
            $table->unsignedSmallInteger('max_attempts')->nullable()->comment('Số lần thi lại; NULL = không giới hạn');
            $table->boolean('shuffle_questions')->default(true);
            $table->boolean('shuffle_options')->default(true);
            $table->boolean('show_result_immediately')->default(true);
            $table->boolean('show_correct_answers')->default(false)
                ->comment('Cho xem đáp án đúng sau khi nộp - cân nhắc tắt để tránh lộ ngân hàng câu hỏi');

            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            // Các loại câu hỏi được hỗ trợ (spec 3.2.3)
            $table->enum('type', ['single_choice', 'multiple_choice', 'true_false'])->default('single_choice');
            $table->text('explanation')->nullable()->comment('Giải thích đáp án, hiện sau khi nộp nếu được bật');
            $table->decimal('score', 8, 2)->default(1)->comment('Trọng số điểm của câu hỏi');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['quiz_id', 'is_active']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('question_id');
        });

        // Mỗi lượt làm bài của nhân viên (spec 3.2.3: lưu lịch sử từng lần thi)
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Bài học chứa quiz, để cập nhật tiến độ khoá học tương ứng');

            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->enum('status', ['in_progress', 'submitted', 'expired', 'graded'])->default('in_progress');

            $table->decimal('score', 8, 2)->nullable()->comment('Tổng điểm đạt được');
            $table->decimal('max_score', 8, 2)->nullable()->comment('Tổng điểm tối đa của đề lần này');
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('is_passed')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('Mốc hết giờ, tính khi bắt đầu');
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->unique(['quiz_id', 'employee_id', 'attempt_no']);
            $table->index(['employee_id', 'status']);
        });

        // Câu trả lời của từng lượt. Lưu snapshot nội dung để lịch sử không đổi khi sửa câu hỏi.
        Schema::create('quiz_attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->text('question_snapshot')->nullable();
            $table->json('selected_option_ids')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('score', 8, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0)->comment('Thứ tự câu sau khi trộn, để hiển thị lại đúng như lúc thi');
            $table->timestamps();

            $table->unique(['quiz_attempt_id', 'question_id']);
        });

        // Bổ sung khoá ngoại lessons.quiz_id sau khi bảng quizzes tồn tại
        Schema::table('lessons', function (Blueprint $table) {
            $table->foreign('quiz_id')->references('id')->on('quizzes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['quiz_id']);
        });

        Schema::dropIfExists('quiz_attempt_answers');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('quizzes');
    }
};
