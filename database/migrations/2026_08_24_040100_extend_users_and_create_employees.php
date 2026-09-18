<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tách "tài khoản đăng nhập" (users) khỏi "hồ sơ nhân sự" (employees) - spec 3.1.1 vs 3.1.2.
 * Lý do: vòng đời nhân sự và vòng đời tài khoản không trùng nhau (một người nghỉ việc
 * vẫn cần giữ hồ sơ + lịch sử học tập, nhưng tài khoản phải bị khoá), và sau này ghép SSO
 * thì users là lớp bị thay thế, employees giữ nguyên.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Trạng thái tài khoản, tách khỏi trạng thái làm việc của nhân sự
            $table->enum('status', ['active', 'locked', 'disabled'])->default('active')->after('password');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            // Buộc đổi mật khẩu ở lần đăng nhập đầu (tài khoản do Admin cấp)
            $table->boolean('must_change_password')->default(false)->after('last_login_ip');
            // Chuẩn bị cho SSO sau này: định danh từ IdP ngoài (spec 7.5)
            $table->string('sso_provider', 50)->nullable()->after('must_change_password');
            $table->string('sso_identifier')->nullable()->after('sso_provider');
            $table->softDeletes();

            $table->index('status');
            $table->unique(['sso_provider', 'sso_identifier']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 50)->unique()->comment('Mã nhân viên, khoá nghiệp vụ khi import Excel');
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            // Thông tin cá nhân (spec 3.1.2)
            $table->string('full_name');
            $table->string('email')->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();

            // Vị trí trong tổ chức - các chiều dùng cho phân quyền động (spec 3.3.1)
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete()
                ->comment('Quản lý trực tiếp, dùng cho báo cáo theo phòng ban');

            // Vòng đời nhân sự (spec 3.1.1): thử việc -> chính thức -> nghỉ việc
            $table->enum('employment_status', ['probation', 'official', 'resigned', 'suspended'])
                ->default('probation');
            $table->date('joined_at')->nullable();
            $table->date('resigned_at')->nullable();
            // Cờ đánh dấu nhân viên mới, dùng để tự động gán lộ trình onboarding (spec 4.4)
            $table->boolean('is_new_hire')->default(true);

            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'employment_status']);
            $table->index('employment_status');
            $table->index('is_new_hire');
        });

        // Lịch sử thay đổi vị trí/phòng ban - audit trail (spec 3.1.2)
        Schema::create('employee_assignment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employment_status', 30)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable()->comment('NULL = bản ghi đang hiệu lực');
            $table->string('change_reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        // Lịch sử đăng nhập / thiết bị truy cập (spec 3.1.1 + 3.3.2)
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('device_label')->nullable()->comment('Nhãn thiết bị suy ra từ user agent, hiển thị cho người dùng');
            $table->enum('result', ['success', 'failed', 'blocked'])->default('success');
            $table->string('failure_reason')->nullable();
            $table->timestamp('logged_in_at');
            $table->timestamp('logged_out_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'logged_in_at']);
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
        Schema::dropIfExists('employee_assignment_histories');
        Schema::dropIfExists('employees');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_provider', 'sso_identifier']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status', 'last_login_at', 'last_login_ip',
                'must_change_password', 'sso_provider', 'sso_identifier', 'deleted_at',
            ]);
        });
    }
};
