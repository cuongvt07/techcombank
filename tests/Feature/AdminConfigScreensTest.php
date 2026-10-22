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

    public function test_mac_dinh_mo_vai_tro_nhan_vien(): void
    {
        Livewire::test(PermissionMatrix::class)
            ->assertSet('role', RoleName::EMPLOYEE->value);
    }

    public function test_bat_quyen_va_luu_thi_vai_tro_nhan_duoc_quyen(): void
    {
        $role = Role::findByName(RoleName::EMPLOYEE->value);
        $this->assertFalse($role->hasPermissionTo('courses.view'));

        Livewire::test(PermissionMatrix::class)
            ->call('toggle', 'courses', 'view')
            ->assertSet('dirty', true)
            ->call('save')
            ->assertSet('dirty', false);

        $this->assertTrue($role->fresh()->hasPermissionTo('courses.view'));
    }

    public function test_luu_khong_lam_mat_quyen_vao_site_hoc_tap(): void
    {
        // learning.access không nằm trong bảng màn hình — lưu bảng không được xóa nó
        Livewire::test(PermissionMatrix::class)
            ->call('toggle', 'announcements', 'view')
            ->call('save');

        $this->assertTrue(Role::findByName(RoleName::EMPLOYEE->value)->fresh()->hasPermissionTo('learning.access'));
    }

    public function test_tick_them_sua_xoa_tu_tick_xem(): void
    {
        Livewire::test(PermissionMatrix::class)
            ->call('toggle', 'quizzes', 'delete')
            ->assertSet('granted', fn ($g) => $g['quizzes.view'] === true)
            ->assertSet('granted', fn ($g) => $g['quizzes.delete'] === true)
            ->call('save');

        $role = Role::findByName(RoleName::EMPLOYEE->value)->fresh();
        $this->assertTrue($role->hasPermissionTo('quizzes.view'));
        $this->assertTrue($role->hasPermissionTo('quizzes.delete'));
        $this->assertFalse($role->hasPermissionTo('quizzes.create'));
    }

    public function test_chon_ca_dong_bat_moi_thao_tac_cua_man(): void
    {
        $component = Livewire::test(PermissionMatrix::class)
            ->call('toggleRow', 'contracts')
            ->assertSet('granted', fn ($g) => $g['contracts.view'] && $g['contracts.create'] && $g['contracts.update'])
            // Bấm lần nữa thì bỏ hết
            ->call('toggleRow', 'contracts')
            ->assertSet('granted', fn ($g) => ! $g['contracts.view'] && ! $g['contracts.create'] && ! $g['contracts.update']);

        $component->call('toggleRow', 'khong-ton-tai')->assertStatus(404);
    }

    public function test_bo_xem_thi_bo_het_thao_tac_cua_man(): void
    {
        $role = Role::findByName(RoleName::EMPLOYEE->value);
        $role->givePermissionTo(['quizzes.view', 'quizzes.create', 'quizzes.update']);

        Livewire::test(PermissionMatrix::class)
            ->call('toggle', 'quizzes', 'view')
            ->call('save');

        $role = $role->fresh();
        foreach (['view', 'create', 'update'] as $action) {
            $this->assertFalse($role->hasPermissionTo("quizzes.{$action}"));
        }
    }

    public function test_khong_tick_duoc_thao_tac_man_khong_co(): void
    {
        // Màn Tổng quan chỉ có Xem — gửi thẳng request tick "Xóa" phải bị từ chối
        Livewire::test(PermissionMatrix::class)
            ->call('toggle', 'dashboard', 'delete')
            ->assertStatus(404);
    }

    public function test_chi_cau_hinh_vai_tro_nhan_vien(): void
    {
        // Vai trò Nhân sự giữ bộ quyền cố định, không chỉnh ở màn này
        Livewire::test(PermissionMatrix::class)
            ->assertViewHas('roles', fn ($roles) => $roles->pluck('name')->all() === [RoleName::EMPLOYEE->value])
            ->set('role', RoleName::HR->value)
            ->assertStatus(404);

        $this->assertTrue(Role::findByName(RoleName::HR->value)->hasPermissionTo('employees.view'));
    }

    public function test_quan_tri_vien_co_toan_bo_quyen(): void
    {
        // Gộp còn hai vai trò nghĩa là Quản trị viên ôm cả dữ liệu nhân sự.
        // Test này chốt lại điều đó để không ai vô tình thu hẹp rồi làm hỏng
        // màn hình hợp đồng / lương.
        $role = Role::findByName(RoleName::ADMIN->value);

        foreach (['contracts.create', 'employees.update', 'courses.delete', 'roles.update'] as $permission) {
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
            ->call('toggle', 'accounts', 'update')
            ->assertSet('dirty', true)
            ->call('resetChanges')
            ->assertSet('dirty', false);

        $this->assertFalse($role->fresh()->hasPermissionTo('accounts.update'));
    }

    public function test_chi_co_quyen_xem_thi_khong_thao_tac_duoc(): void
    {
        // Ẩn nút chưa đủ: request Livewire gọi thẳng hàm vẫn phải bị chặn
        $hr = $this->staffWith(['settings.view']);

        Livewire::actingAs($hr)->test(SettingsManager::class)
            ->call('setTab', 'departments')
            ->call('create')
            ->assertForbidden();
    }

    public function test_co_quyen_them_thi_them_duoc_nhung_khong_sua_phan_quyen(): void
    {
        $hr = $this->staffWith(['settings.view', 'settings.create', 'roles.view']);

        Livewire::actingAs($hr)->test(SettingsManager::class)
            ->call('setTab', 'departments')
            ->call('create')
            ->set('name', 'Phòng do HR thêm')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('departments', ['name' => 'Phòng do HR thêm']);

        // Xem được màn Phân quyền nhưng không có roles.update thì không lưu được
        Livewire::actingAs($hr)->test(PermissionMatrix::class)
            ->assertOk()
            ->call('toggle', 'courses', 'view')
            ->assertForbidden();
    }

    public function test_vao_route_can_quyen_xem_cua_man(): void
    {
        $hr = $this->staffWith(['dashboard.view']);

        $this->actingAs($hr)->get(route('admin.settings'))->assertForbidden();

        $hr->givePermissionTo('settings.view');
        $this->actingAs($hr->fresh())->get(route('admin.settings'))->assertOk();
    }

    /** Tài khoản HR với đúng các quyền cho trước (bỏ quyền mặc định của vai trò). */
    private function staffWith(array $permissions): User
    {
        Role::findByName(RoleName::HR->value)->syncPermissions([]);

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole(RoleName::HR->value);
        $user->givePermissionTo($permissions);
        Employee::factory()->create(['user_id' => $user->id]);

        return $user->fresh();
    }

    // ---- 3.1.5 Cấu hình chung ----------------------------------------------

    public function test_them_phong_ban_moi(): void
    {
        Livewire::test(SettingsManager::class)
            ->call('setTab', 'departments')
            ->call('create')
            ->set('name', 'Phòng Kiểm toán nội bộ')
            ->call('save')
            ->assertHasNoErrors();

        // Mã do hệ thống tự sinh, người dùng không nhập
        $this->assertDatabaseHas('departments', ['name' => 'Phòng Kiểm toán nội bộ', 'code' => 'PB-001']);
    }

    public function test_ma_danh_muc_tu_sinh_noi_tiep_va_bo_qua_ma_gui_len(): void
    {
        Department::factory()->create(['code' => 'PB-007']);

        // Gửi kèm mã qua request cũng không được dùng — mã luôn do hệ thống sinh
        Livewire::test(SettingsManager::class)
            ->call('setTab', 'departments')
            ->call('create')
            ->set('code', 'PB-007')
            ->set('name', 'Phòng Mã Nối Tiếp')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('departments', ['name' => 'Phòng Mã Nối Tiếp', 'code' => 'PB-008']);
    }

    public function test_cap_bac_bat_buoc_co_level(): void
    {
        Livewire::test(SettingsManager::class)
            ->call('setTab', 'job_grades')
            ->call('create')
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
