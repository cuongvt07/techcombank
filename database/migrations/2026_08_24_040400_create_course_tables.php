<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Bộ tài liệu ban hành" (spec 3.2.4) = khoá học, gồm nhiều bài học (spec 3.2.5)
 * xếp theo trình tự. Mỗi bài học trỏ tới một dạng nội dung: văn bản, tài liệu/video
 * trong thư viện, hoặc bài kiểm tra trắc nghiệm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('cover_image')->nullable();

            $table->foreignId('owner_department_id')->nullable()->constrained('departments')->nullOnDelete();

            // Học tuần tự = phải xong bài trước mới mở bài sau (spec 3.2.5)
            $table->boolean('sequential')->default(true);
            // Onboarding: khoá tự động gán cho nhân viên mới (spec 4.4)
            $table->boolean('is_onboarding')->default(false);

            $table->enum('status', ['draft', 'pending_review', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            // Điểm đạt toàn khoá và thời hạn hoàn thành kể từ ngày được gán
            $table->unsignedSmallInteger('pass_score')->nullable()->comment('Điểm đạt tổng, %; NULL = không chấm điểm tổng');
            $table->unsignedSmallInteger('duration_days')->nullable()->comment('Số ngày phải hoàn thành kể từ khi được gán');
            $table->boolean('issue_certificate')->default(false)->comment('Cấp chứng nhận khi hoàn thành - spec mục 8 câu hỏi 3');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_onboarding']);
        });

        // Chương/phần trong khoá học - gom nhóm bài học cho khoá dài
        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['course_id', 'sort_order']);
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_section_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('summary')->nullable();

            // Dạng nội dung bài học (spec 3.2.5)
            $table->enum('content_type', ['text', 'document', 'video', 'quiz'])->default('text');
            // text -> content_html; document/video -> document_id; quiz -> quiz_id
            $table->longText('content_html')->nullable();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('quiz_id')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true)->comment('Bài bắt buộc mới tính vào % tiến độ hoàn thành');
            $table->unsignedInteger('estimated_minutes')->nullable();

            // Điều kiện hoàn thành bài: tỉ lệ xem video tối thiểu (spec 3.2.2)
            $table->unsignedSmallInteger('min_watch_percent')->nullable()
                ->comment('Chỉ dùng cho content_type=video, % xem tối thiểu để tính hoàn thành');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'sort_order']);
            $table->index('content_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('courses');
    }
};
