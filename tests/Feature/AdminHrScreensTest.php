<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\AccountManager;
use App\Livewire\Admin\EmployeeManager;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\JobTitle;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Kiểm chứng các màn hình quản lý nhân sự ở spec 3.1. */
class AdminHrScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $this->admin->id]);
        $this->actingAs($this->admin->refresh());
    }

    // ---- 3.1.1 Quản lý tài khoản -------------------------------------------

    public function test_tao_tai_khoan_tu_ho_so_nhan_su_co_san(): void
    {
        // Luồng: tạo hồ sơ trước, tạo tài khoản bằng cách chọn hồ sơ đó
        $employee = Employee::factory()->create(['user_id' => null, 'full_name' => 'Nguyễn Văn Test']);

        Livewire::test(AccountManager::class)
            ->call('create')
            ->assertViewHas('employeeOptions', fn ($options) => collect($options)->contains('value', $employee->id))
            ->set('employee_id', $employee->id)
            // Thông tin hồ sơ hiện ra ngay khi chọn
            ->assertSee('Nguyễn Văn Test')
            ->set('email', 'test.nv@techcombank.local')
            ->set('password', 'matkhau123')
            ->set('role', RoleName::EMPLOYEE->value)
            ->call('requestSave')
            ->assertSet('showSaveConfirm', true)
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'test.nv@techcombank.local')->firstOrFail();
        // Gắn đúng hồ sơ đã chọn, không sinh hồ sơ mới
        $this->assertSame($user->id, $employee->fresh()->user_id);
        $this->assertSame('Nguyễn Văn Test', $user->name);
        $this->assertSame(1, Employee::where('full_name', 'Nguyễn Văn Test')->count());
    }

    public function test_bat_buoc_chon_ho_so_khi_tao_tai_khoan(): void
    {
        Livewire::test(AccountManager::class)
            ->call('create')
            ->set('email', 'khong.ho.so@techcombank.local')
            ->set('password', 'matkhau123')
            ->call('requestSave')
            ->assertHasErrors(['employee_id' => 'required']);
    }

    public function test_khong_chon_duoc_ho_so_da_co_tai_khoan(): void
    {
        $taken = Employee::factory()->create(['user_id' => User::factory()->create()->id]);

        Livewire::test(AccountManager::class)
            ->call('create')
            ->assertViewHas('employeeOptions', fn ($options) => ! collect($options)->contains('value', $taken->id))
            // Gửi thẳng id qua request cũng bị chặn
            ->set('employee_id', $taken->id)
            ->set('email', 'trung.ho.so@techcombank.local')
            ->set('password', 'matkhau123')
            ->call('requestSave')
            ->assertHasErrors(['employee_id' => 'exists']);
    }

    public function test_chua_xac_nhan_thi_chua_luu(): void
    {
        $employee = Employee::factory()->create(['user_id' => null]);

        Livewire::test(AccountManager::class)
            ->call('create')
            ->set('employee_id', $employee->id)
            ->set('email', 'chua.xn@techcombank.local')
            ->set('password', 'matkhau123')
            ->call('save');

        $this->assertDatabaseMissing('users', ['email' => 'chua.xn@techcombank.local']);
    }

    public function test_khong_cho_tao_trung_email(): void
    {
        User::factory()->create(['email' => 'trung@techcombank.local']);
        $employee = Employee::factory()->create(['user_id' => null]);

        Livewire::test(AccountManager::class)
            ->call('create')
            ->set('employee_id', $employee->id)
            ->set('email', 'trung@techcombank.local')
            ->set('password', 'matkhau123')
            ->set('role', RoleName::EMPLOYEE->value)
            ->call('requestSave')
            ->assertHasErrors(['email' => 'unique'])
            ->assertSet('showSaveConfirm', false);
    }

    public function test_mat_khau_bat_buoc_khi_tao_moi_nhung_tuy_chon_khi_sua(): void
    {
        $employee = Employee::factory()->create(['user_id' => null]);

        Livewire::test(AccountManager::class)
            ->call('create')
            ->set('employee_id', $employee->id)
            ->set('email', 'nopass@techcombank.local')
            ->set('role', RoleName::EMPLOYEE->value)
            ->call('requestSave')
            ->assertHasErrors(['password' => 'required']);

        // Sửa tài khoản có sẵn mà để trống mật khẩu thì vẫn lưu được
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id, 'employee_code' => 'NV999004']);

        Livewire::test(AccountManager::class)
            ->call('edit', $user->id)
            ->set('password', '')
            ->call('requestSave')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_khoa_va_mo_khoa_tai_khoan(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Livewire::test(AccountManager::class)
            ->call('prepareStatusChange', $user->id)
            ->assertSet('showActionConfirm', true)
            ->call('confirmPendingAction');
        $this->assertSame(User::STATUS_LOCKED, $user->refresh()->status);

        Livewire::test(AccountManager::class)
            ->call('prepareStatusChange', $user->id)
            ->call('confirmPendingAction');
        $this->assertSame(User::STATUS_ACTIVE, $user->refresh()->status);
    }

    public function test_bam_huy_thi_khong_khoa(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Livewire::test(AccountManager::class)
            ->call('prepareStatusChange', $user->id)
            ->set('showActionConfirm', false);

        $this->assertSame(User::STATUS_ACTIVE, $user->refresh()->status);
    }

    public function test_xoa_tai_khoan(): void
    {
        $user = User::factory()->create();

        Livewire::test(AccountManager::class)
            ->call('prepareDelete', $user->id)
            ->assertSet('showActionConfirm', true)
            ->call('confirmPendingAction')
            ->assertSet('showActionConfirm', false);

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_popup_xac_nhan_hien_khi_chua_mo_form_sua(): void
    {
        // Lỗi cũ: popup khóa/xóa bám theo biến showModal của form Sửa, nên chỉ
        // hiện khi đã bấm Sửa trước. Popup phải bám đúng biến của chính nó.
        $user = User::factory()->create();

        Livewire::test(AccountManager::class)
            ->call('prepareDelete', $user->id)
            ->assertSet('showModal', false)
            ->assertSeeHtml('x-show="$wire.showActionConfirm"');
    }

    public function test_khong_the_tu_khoa_tai_khoan_cua_chinh_minh(): void
    {
        Livewire::test(AccountManager::class)
            ->call('prepareStatusChange', $this->admin->id)
            ->assertSet('showActionConfirm', false);

        $this->assertSame(User::STATUS_ACTIVE, $this->admin->refresh()->status);
    }

    public function test_loc_tai_khoan_theo_phong_ban_bao_gom_phong_con(): void
    {
        $parent = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $parent->id]);

        Employee::factory()->create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $child->id,
            'full_name' => 'Tài Khoản Phòng Con',
        ]);
        Employee::factory()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Tài Khoản Phòng Khác',
        ]);

        Livewire::test(AccountManager::class)
            ->set('departmentFilter', (string) $parent->id)
            ->assertSee('Tài Khoản Phòng Con')
            ->assertDontSee('Tài Khoản Phòng Khác');
    }

    public function test_tim_kiem_tai_khoan_theo_ma_nhan_vien(): void
    {
        // Bảng hiển thị tên từ hồ sơ nhân sự, nên đặt tên ở Employee chứ không phải User
        $user = User::factory()->create();
        Employee::factory()->create([
            'user_id' => $user->id,
            'employee_code' => 'NVSEARCH1',
            'full_name' => 'Người Cần Tìm',
        ]);

        $other = User::factory()->create();
        Employee::factory()->create([
            'user_id' => $other->id,
            'employee_code' => 'NVOTHER9',
            'full_name' => 'Người Khác',
        ]);

        Livewire::test(AccountManager::class)
            ->set('search', 'NVSEARCH1')
            ->assertSee('Người Cần Tìm')
            ->assertDontSee('Người Khác');
    }

    // ---- 3.1.2 Hồ sơ nhân sự -----------------------------------------------

    public function test_sua_ho_so_doi_phong_ban_thi_ghi_lich_su(): void
    {
        $oldDept = Department::factory()->create();
        $newDept = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $oldDept->id]);

        Livewire::test(EmployeeManager::class)
            ->call('edit', $employee->id)
            ->set('department_id', $newDept->id)
            ->set('change_reason', 'Điều chuyển thử nghiệm')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($newDept->id, $employee->refresh()->department_id);
        $this->assertDatabaseHas('employee_assignment_histories', [
            'employee_id' => $employee->id,
            'department_id' => $newDept->id,
            'change_reason' => 'Điều chuyển thử nghiệm',
        ]);
    }

    public function test_khong_cho_chon_chinh_minh_lam_quan_ly_truc_tiep(): void
    {
        $employee = Employee::factory()->create();

        Livewire::test(EmployeeManager::class)
            ->call('edit', $employee->id)
            ->set('manager_id', $employee->id)
            ->call('save')
            ->assertHasErrors('manager_id');
    }

    public function test_ghi_nhan_nghi_viec_thi_khoa_tai_khoan(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        Livewire::test(EmployeeManager::class)->call('resign', $employee->id);

        $this->assertSame(Employee::STATUS_RESIGNED, $employee->refresh()->employment_status);
        $this->assertSame(User::STATUS_DISABLED, $user->refresh()->status);
    }

    public function test_loc_nhan_su_theo_phong_ban_bao_gom_phong_con(): void
    {
        $parent = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $parent->id]);

        Employee::factory()->create(['department_id' => $child->id, 'full_name' => 'Nhân Viên Phòng Con']);
        Employee::factory()->create(['full_name' => 'Nhân Viên Phòng Khác']);

        Livewire::test(EmployeeManager::class)
            ->set('departmentFilter', (string) $parent->id)
            ->assertSee('Nhân Viên Phòng Con')
            ->assertDontSee('Nhân Viên Phòng Khác');
    }

    public function test_loc_nhan_su_theo_chuc_danh_cap_bac_va_nam_vao_lam(): void
    {
        $title = JobTitle::factory()->create();
        $grade = JobGrade::factory()->create();

        Employee::factory()->create([
            'full_name' => 'Người Khớp Bộ Lọc',
            'job_title_id' => $title->id,
            'job_grade_id' => $grade->id,
            'joined_at' => '2023-05-10',
        ]);
        Employee::factory()->create(['full_name' => 'Người Khác Chức Danh', 'joined_at' => '2023-02-01']);
        Employee::factory()->create([
            'full_name' => 'Người Khác Năm',
            'job_title_id' => $title->id,
            'job_grade_id' => $grade->id,
            'joined_at' => '2021-01-01',
        ]);

        Livewire::test(EmployeeManager::class)
            ->set('jobTitleFilter', (string) $title->id)
            ->set('jobGradeFilter', (string) $grade->id)
            ->set('joinedYearFilter', '2023')
            ->assertSee('Người Khớp Bộ Lọc')
            ->assertDontSee('Người Khác Chức Danh')
            ->assertDontSee('Người Khác Năm');
    }

    public function test_bang_nhan_su_khong_con_cot_tien_do(): void
    {
        Livewire::test(EmployeeManager::class)->assertDontSee('Tiến độ học tập');
    }

    // ---- Phân quyền màn hình ------------------------------------------------

    public function test_nhan_vien_khong_vao_duoc_man_hinh_tai_khoan(): void
    {
        $employee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $employee->assignRole(RoleName::EMPLOYEE->value);
        Employee::factory()->create(['user_id' => $employee->id]);

        // Nhân viên bị EnsureUserIsAdmin chặn ngay ở cửa site quản trị và
        // chuyển về site học tập — chuyển hướng chứ không phải 403, để người
        // dùng không rơi vào ngõ cụt.
        $this->actingAs($employee->refresh());
        $this->get(route('admin.accounts'))->assertRedirect(route('learn.events'));
    }

    public function test_quan_tri_vien_vao_duoc_man_hinh_tai_khoan(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin->refresh());
        $this->get(route('admin.accounts'))->assertOk();
    }
}
