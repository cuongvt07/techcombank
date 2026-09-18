<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Danh mục tổ chức dùng chung (spec 3.1.5): phòng ban, chức danh, cấp bậc.
 * Đây là các chiều dữ liệu mà điều kiện phân quyền động (spec 3.3.1) dựa vào,
 * nên tách bảng riêng thay vì lưu chuỗi tự do trong hồ sơ nhân viên.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Phòng ban - dạng cây, một phòng có thể trực thuộc khối/phòng cha
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->text('description')->nullable();
            // Đầu mối hỗ trợ của phòng ban (spec 4.5) - gán sau khi có bảng employees
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        // Chức danh / vị trí công việc
        Schema::create('job_titles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Cấp bậc - có level số để so sánh điều kiện kiểu "từ cấp N trở lên"
        Schema::create('job_grades', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('level')->default(0)->comment('Số càng lớn cấp càng cao, dùng cho điều kiện >= trong rule phân quyền');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_grades');
        Schema::dropIfExists('job_titles');
        Schema::dropIfExists('departments');
    }
};
