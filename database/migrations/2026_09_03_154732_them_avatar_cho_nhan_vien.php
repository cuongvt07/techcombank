<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ảnh đại diện nhân viên.
 *
 * Trỏ tới file trong kho tài nguyên chung thay vì lưu đường dẫn riêng: ảnh cũng
 * là file, đi cùng một tầng lưu trữ với tài liệu và hợp đồng. Xóa file trong kho
 * thì avatar tự về null chứ không trỏ vào file không còn tồn tại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('avatar_file_id')->nullable()->after('email')
                ->constrained('stored_files')->nullOnDelete()
                ->comment('NULL = dùng avatar chữ cái tự sinh');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avatar_file_id');
        });
    }
};
