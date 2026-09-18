<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hợp đồng trỏ file scan vào kho tài nguyên thay vì giữ bản sao riêng.
 *
 * Sau bước này mọi file trong hệ thống đều nằm ở một chỗ: đổi nơi lưu,
 * kiểm kê dung lượng, hay truy vết file đều làm được từ một nguồn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('stored_file_id')->nullable()->after('contract_type_id')
                ->constrained('stored_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['stored_file_id']);
            $table->dropColumn('stored_file_id');
        });
    }
};
