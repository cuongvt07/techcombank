<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thư viện tài liệu nội bộ: file (spec 3.2.1) và video (spec 3.2.2).
 * Dùng một bảng documents chung với cột `kind` thay vì tách documents/videos riêng,
 * vì cả hai chia sẻ toàn bộ vòng đời (metadata, versioning, phân quyền, watermark, log xem)
 * và đều là "tài nguyên học liệu" gắn vào bài học ở spec 3.2.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Danh mục loại tài liệu (spec 3.1.5): quy trình, biểu mẫu, chính sách...
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('document_categories')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->enum('kind', ['file', 'video'])->default('file')
                ->comment('file = doc/xls/pdf/ppt (spec 3.2.1), video = bài giảng video (spec 3.2.2)');
            $table->foreignId('document_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_department_id')->nullable()->constrained('departments')->nullOnDelete()
                ->comment('Phòng ban ban hành/chịu trách nhiệm nội dung');

            // Cấp độ bảo mật quyết định chính sách hiển thị (spec 3.3.2)
            $table->enum('confidentiality', ['public', 'internal', 'confidential', 'restricted'])
                ->default('internal');
            $table->boolean('allow_download')->default(false)
                ->comment('Tài liệu nhạy cảm chỉ xem online, không cho tải bản gốc');
            $table->boolean('enable_watermark')->default(true)
                ->comment('Watermark động tên/email/thời gian khi xem');

            $table->enum('status', ['draft', 'pending_review', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            // Trỏ tới phiên bản đang hiệu lực trong document_versions
            $table->unsignedBigInteger('current_version_id')->nullable();

            // Riêng cho video (spec 3.2.2)
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'kind']);
            $table->index('confidentiality');
            $table->index('owner_department_id');
        });

        // Versioning + rollback (spec 3.2.1). File thật nằm trong kho tài nguyên,
        // cột stored_file_id được thêm ở migration 050000.
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('change_note')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable()->comment('SHA-256 để phát hiện trùng lặp/thay đổi');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'version_no']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('document_versions')->nullOnDelete();
        });

        // Chương/mốc thời gian của video (spec 3.2.2)
        Schema::create('video_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('start_second');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['document_id', 'start_second']);
        });

        // Phụ đề video (spec 3.2.2)
        Schema::create('video_subtitles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10)->default('vi');
            $table->string('label')->nullable();
            $table->string('path');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['document_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_subtitles');
        Schema::dropIfExists('video_chapters');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_categories');
    }
};
