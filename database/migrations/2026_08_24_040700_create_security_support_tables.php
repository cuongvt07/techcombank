<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bảo mật tài liệu (spec 3.3) + hỗ trợ người dùng (spec 4.5) + cấu hình chung (spec 3.1.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Quyền truy cập tài liệu theo điều kiện (spec 3.3.1).
        // Quyền được tính động từ các rule này, không lưu cứng cho từng nhân viên,
        // nhờ đó đổi phòng ban là quyền tự đổi theo mà không cần chạy lại job gán quyền.
        Schema::create('document_access_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()->constrained()->cascadeOnDelete()
                ->comment('NULL = rule áp cho cả danh mục');
            $table->foreignId('document_category_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('min_grade_level')->nullable()
                ->comment('Điều kiện từ cấp bậc N trở lên');
            $table->string('employment_status', 30)->nullable();
            $table->boolean('include_sub_departments')->default(true);

            // Ma trận quyền theo tài nguyên (spec 3.1.4)
            $table->boolean('can_view')->default(true);
            $table->boolean('can_download')->default(false);
            $table->boolean('can_print')->default(false);

            // Rule chặn thắng rule cho phép, dùng cho ngoại lệ hẹp
            $table->enum('effect', ['allow', 'deny'])->default('allow');
            $table->unsignedSmallInteger('priority')->default(0)->comment('Số lớn hơn được xét trước');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['document_id', 'is_active']);
            $table->index(['document_category_id', 'is_active']);
        });

        // Log xem tài liệu chi tiết (spec 3.3.2: ai xem, lúc nào, thiết bị/IP nào)
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('action', ['view', 'download', 'print', 'stream', 'denied'])->default('view');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('watermark_token', 64)->nullable()
                ->comment('Mã nhúng trong watermark, truy ngược bản rò rỉ về đúng phiên xem');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('deny_reason')->nullable();
            $table->timestamp('accessed_at');
            $table->timestamps();

            $table->index(['document_id', 'accessed_at']);
            $table->index(['employee_id', 'accessed_at']);
            $table->index('action');
        });

        // Phiên thiết bị đang hoạt động - giới hạn số thiết bị đăng nhập đồng thời (spec 3.3.2)
        Schema::create('device_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->index();
            $table->string('device_fingerprint', 64)->nullable();
            $table->string('device_label')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });

        // Cảnh báo truy cập bất thường (spec 3.3.2)
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 60)->comment('multi_device, mass_download, unusual_ip, brute_force');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->string('title');
            $table->text('detail')->nullable();
            $table->json('context')->nullable();
            $table->enum('status', ['open', 'acknowledged', 'resolved', 'false_positive'])->default('open');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index('type');
        });

        // Đầu mối hỗ trợ theo phòng ban/chủ đề (spec 4.5)
        Schema::create('support_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('topic')->comment('Hợp đồng, Nội dung đào tạo, Kỹ thuật...');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete()
                ->comment('NULL = đầu mối áp dụng cho toàn công ty');
            $table->string('contact_name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Yêu cầu hỗ trợ có theo dõi trạng thái (spec 4.5)
        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no', 50)->unique();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('topic');
            $table->string('subject');
            $table->text('content');
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->enum('status', ['new', 'in_progress', 'resolved', 'closed'])->default('new');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index('employee_id');
        });

        // Cấu hình chung dạng key-value (spec 3.1.5): logo, ngôn ngữ, ngưỡng cảnh báo...
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->string('type', 20)->default('string')->comment('string|integer|boolean|json');
            $table->string('group', 50)->default('general');
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('group');
        });

        // Thông báo trong hệ thống (spec 3.1.5: tài liệu mới, bài kiểm tra mới, hợp đồng sắp hết hạn)
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->enum('type', ['general', 'course', 'document', 'contract', 'system'])->default('general');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete()
                ->comment('NULL = gửi toàn công ty');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('support_contacts');
        Schema::dropIfExists('security_alerts');
        Schema::dropIfExists('device_sessions');
        Schema::dropIfExists('document_access_logs');
        Schema::dropIfExists('document_access_rules');
    }
};
