<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quản lý hợp đồng lao động (spec 3.1.3).
 * File scan hợp đồng lưu trong kho tài nguyên chung (stored_files, migration 050100),
 * quyền xem giới hạn theo vai trò HR / quản lý trực tiếp - kiểm soát ở tầng Policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Loại hợp đồng là danh mục cấu hình được (spec 3.1.5)
        Schema::create('contract_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('default_duration_months')->nullable()
                ->comment('Thời hạn mặc định, NULL = không xác định thời hạn');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_no', 100)->unique();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_type_id')->constrained()->restrictOnDelete();

            $table->date('signed_at')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable()->comment('NULL = hợp đồng không xác định thời hạn');

            $table->enum('status', ['draft', 'active', 'expiring', 'expired', 'terminated'])
                ->default('draft');
            // Số ngày trước hạn bắt đầu cảnh báo, cho phép ghi đè cấu hình chung
            $table->unsignedSmallInteger('alert_before_days')->nullable();
            $table->timestamp('alert_sent_at')->nullable();

            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            // Phục vụ job quét hợp đồng sắp hết hạn hằng ngày
            $table->index(['status', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('contract_types');
    }
};
