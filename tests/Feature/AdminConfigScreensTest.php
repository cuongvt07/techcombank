<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\PermissionMatrix;
use App\Livewire\Admin\SettingsManager;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Kiểm chứng phân quyền (spec 3.1.4) và cấu hình chung (spec 3.1.5). */
class AdminConfigScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin->refresh());
    }

    // ---- 3.1.4 Phân quyền ---------------------------------------------------

    public function test_ma_tran_khong_hien_thi_quan_tri_vien(): void
    {
        // Quản trị viên có toàn quyền qua Gate::before nên không được xuất hiện
        // trong lưới — hiện ô tick sẽ khiến người dùng tưởng sửa được.
        //
        // Kiểm tra trên danh sách vai trò trả về chứ không dò chuỗi trong HTML:
        // chữ "admin" nằm khắp nơi trong tên class CSS (admin-btn, admin-panel)
        // nên assertDontSee sẽ báo sai.
        $roles = Livewire::test(PermissionMatrix::class)->viewData('roles');

        $this->assertNotContains(RoleName::ADMIN->value, $roles->pluck('name')->all());
        $this->assertContains(RoleName::EMPLOYEE->value, $roles->pluck('name')->all());
    }

    public function test_bat_quyen_va_luu_thi_vai_tro_nhan_duoc_quyen(): void
    {
        $role = Role::findByName(RoleName::EMPLOYEE->value);
        $this->assertFalse($role->hasPermissionTo('courses.view'));

        Livewire::test(PermissionMatrix::class)
            ->call('toggle', RoleName::EMPLOYEE->value, 'courses.view')
            ->assertSet('dirty', true)
            ->call('save')
            ->assertSet('dirty', false);

        $this->assertTrue($role->fresh()->hasPermissionTo('courses.view'));
    }

    public function test_tat_quyen_thi_vai_tro_mat_quyen_do(): void
    {
        $role = Role::findByName(RoleName::EMPLOYEE->value);
        $this->assertTrue($role->hasPermissionTo('learning.access'));

        Livewire::test(PermissionMatrix::class)
            ->call('toggle', RoleName::EMPLOYEE->value, 'learning.access')
            ->call('save');

        $this->assertFalse($role->fresh()->hasPermissionTo('learning.access'));
    }

    public function test_bat_ca_nhom_quyen_cung_luc(): void
    {
        Livewire::test(PermissionMatrix::class)
            ->call('toggleGroup', RoleName::EMPLOYEE->value, 'quizzes')
            ->call('save');

        $role = Role::findByName(RoleName::EMPLOYEE->value)->fresh();

        $this->assertTrue($role->hasPermissionTo('quizzes.view'));
        $this->assertTrue($role->hasPermissionTo('quizzes.manage'));
    }

    public function test_quan_tri_vien_co_toan_bo_quyen(): void
    {
        // Gộp còn hai vai trò nghĩa là Quản trị viên ôm cả dữ liệu nhân sự.
        // Test này chốt lại điều đó để không ai vô tình thu hẹp rồi làm hỏng
        // màn hình hợp đồng / lương.
        $role = Role::findByName(RoleName::ADMIN->value);

        foreach (['contracts.manage', 'employees.manage', 'courses.manage', 'roles.manage'] as $permission) {
            $this->assertTrue(
                $role->hasPermissionTo($permission),
                "Quản trị viên phải có quyền {$permission}"
            );
        }
    }

    public function test_hoan_tac_khong_ghi_vao_csdl(): void
    {
        $role = Role::findByName(RoleName::EMPLOYEE->value);

        Livewire::test(PermissionMatrix::class)
            ->call('toggle', RoleName::EMPLOYEE->value, 'accounts.manage')
            ->assertSet('dirty', true)
            ->call('resetChanges')
            ->assertSet('dirty', false);

        $this->assertFalse($role->fresh()->hasPermissionTo('accounts.manage'));
    }

    // ---- 3.1.5 Cấu hình chung ----------------------------------------------

    public function test_them_phong_ban_moi(): void
    {
        Livewire::test(SettingsManager::class)
            ->call('setTab', 'departments')
            ->call('create')
            ->set('code', 'DEPT-NEW')
            ->set('name', 'Phòng Kiểm toán nội bộ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('departments', ['code' => 'DEPT-NEW']);
    }

    public function test_khong_cho_ma_danh_muc_trung(): void
    {
        Department::factory()->create(['code' => 'DUP-CODE']);

        Livewire::test(SettingsManager::class)
            ->call('setTab', 'departments')
            ->call('create')
            ->set('code', 'DUP-CODE')
            ->set('name', 'Phòng Trùng mã')
            ->call('save')
            ->assertHasErrors(['code' => 'unique']);
    }

    public function test_cap_bac_bat_buoc_co_level(): void
    {
        Livewire::test(SettingsManager::class)
            ->call('setTab', 'job_grades')
            ->call('create')
            ->set('code', 'G-TEST')
            ->set('name', 'Cấp thử nghiệm')
            ->set('level', null)
            ->call('save')
            ->assertHasErrors(['level' => 'required']);
    }

    public function test_khong_cho_chon_chinh_minh_lam_phong_ban_cha(): void
    {
        $dept = Department::factory()->create();

        Livewire::test(SettingsManager::class)
            ->call('setTab', 'departments')
            ->call('edit', $dept->id)
            ->set('parent_id', $dept->id)
            ->call('save')
            ->assertHasErrors('parent_id');
    }

    public function test_ngung_su_dung_danh_muc(): void
    {
        $grade = JobGrade::factory()->create(['is_active' => true]);

        Livewire::test(SettingsManager::class)
            ->call('setTab', 'job_grades')
            ->call('toggleActive', $grade->id);

        $this->assertFalse($grade->refresh()->is_active);
    }

    public function test_luu_tham_so_he_thong(): void
    {
        Livewire::test(SettingsManager::class)
            ->set('settings.display_name', 'LMS Thử Nghiệm')
            ->set('settings.contract_alert_days', 45)
            ->set('settings.max_devices', 3)
            ->set('settings.watermark', true)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('LMS Thử Nghiệm', Setting::get('app.display_name'));
        $this->assertSame(45, Setting::get('contract.alert_before_days'));
        $this->assertSame(3, Setting::get('security.max_concurrent_devices'));
        $this->assertTrue(Setting::get('security.watermark_enabled'));
    }

    public function test_tham_so_he_thong_duoc_kiem_tra_gioi_han(): void
    {
        Livewire::test(SettingsManager::class)
            ->set('settings.contract_alert_days', 999)
            ->call('saveSettings')
            ->assertHasErrors('settings.contract_alert_days');
    }
}
