<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kho tài nguyên dạng cây thư mục (như Google Drive).
 *
 * Đây là TẦNG LƯU TRỮ dùng chung: mọi file trong hệ thống (tài liệu đào tạo,
 * video bài giảng, hợp đồng scan, ảnh) đều nằm ở đây. Các bảng nghiệp vụ như
 * documents/contracts chỉ tham chiếu tới stored_files, giữ nguyên metadata,
 * versioning và phân quyền riêng của mình.
 *
 * Nhờ tách tầng: đổi nơi lưu (local → S3) chỉ sửa một chỗ, và một file dùng
 * cho nhiều mục đích không bị nhân bản.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('file_folders')->cascadeOnDelete();

            /*
             * Đường dẫn vật chất hoá (vd "/1/5/12/") để truy vấn cả nhánh con
             * bằng một câu LIKE, thay vì đệ quy nhiều lượt. Cây thư mục đọc
             * nhiều hơn ghi rất nhiều nên đánh đổi này có lợi.
             */
            $table->string('path', 500)->default('/');
            $table->unsignedSmallInteger('depth')->default(0);

            // Phòng ban sở hữu: dùng cho phân quyền theo điều kiện (spec 3.3.1)
            $table->foreignId('owner_department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false)
                ->comment('Thư mục hệ thống tạo sẵn, không cho xoá');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('path');
            // Không cho hai thư mục cùng tên trong cùng thư mục cha
            $table->unique(['parent_id', 'name', 'deleted_at']);
        });

        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained('file_folders')->nullOnDelete();

            $table->string('name');
            $table->string('original_name');
            $table->string('disk', 50)->default('local');
            $table->string('path', 500)->comment('Đường dẫn tương đối trong disk');

            $table->string('mime_type', 150)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // SHA-256 để phát hiện file trùng nội dung và kiểm tra toàn vẹn
            $table->string('checksum', 64)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('folder_id');
            $table->index('checksum');
            $table->index('mime_type');
        });

        // Tài liệu đào tạo trỏ tới file trong kho thay vì tự lưu bản sao
        Schema::table('document_versions', function (Blueprint $table) {
            $table->foreignId('stored_file_id')->nullable()->after('document_id')
                ->constrained('stored_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropForeign(['stored_file_id']);
            $table->dropColumn('stored_file_id');
        });

        Schema::dropIfExists('stored_files');
        Schema::dropIfExists('file_folders');
    }
};
