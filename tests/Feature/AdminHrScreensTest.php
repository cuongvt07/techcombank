<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\AccountManager;
use App\Livewire\Admin\ContractManager;
use App\Livewire\Admin\EmployeeManager;
use App\Models\Contract;
use App\Models\ContractType;
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

    public function test_tao_tai_khoan_sinh_luon_ho_so_nhan_su(): void
    {
        Livewire::test(AccountManager::class)
            ->call('create')
            ->set('full_name', 'Nguyễn Văn Test')
            ->set('email', 'test.nv@techcombank.local')
            ->set('employee_code', 'NV999001')
            ->set('password', 'matkhau123')
            ->set('role', RoleName::EMPLOYEE->value)
            ->call('requestSave')
            ->assertSet('showSaveConfirm', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'test.nv@techcombank.local']);
        // Tài khoản không gắn hồ sơ sẽ không nhận được khoá học nào, nên phải tạo cặp
        $employee = Employee::where('full_name', 'Nguyễn Văn Test')->firstOrFail();
        // Mã nhân viên do hệ thống tự sinh, không bỏ qua mã gửi kèm request
        $this->assertMatchesRegularExpression('/^NV\d{6,}$/', $employee->employee_code);
        $this->assertNotSame('NV999001', $employee->employee_code);
    }

    public function test_chua_xac_nhan_thi_chua_luu(): void
    {
        Livewire::test(AccountManager::class)
            ->call('create')
            ->set('full_name', 'Chưa Xác Nhận')
            ->set('email', 'chua.xn@techcombank.local')
            ->set('employee_code', 'NV999009')
            ->set('password', 'matkhau123')
            ->call('save');

        $this->assertDatabaseMissing('users', ['email' => 'chua.xn@techcombank.local']);
    }

    public function test_khong_cho_tao_trung_email(): void
    {
        User::factory()->create(['email' => 'trung@techcombank.local']);

        Livewire::test(AccountManager::class)
            ->call('create')
            ->set('full_name', 'Người Trùng')
            ->set('email', 'trung@techcombank.local')
            ->set('employee_code', 'NV999002')
            ->set('password', 'matkhau123')
            ->set('role', RoleName::EMPLOYEE->value)
            ->call('requestSave')
            ->assertHasErrors(['email' => 'unique'])
            ->assertSet('showSaveConfirm', false);
    }

    public function test_mat_khau_bat_buoc_khi_tao_moi_nhung_tuy_chon_khi_sua(): void
    {
        Livewire::test(AccountManager::class)
            ->call('create')
            ->set('full_name', 'Không Mật Khẩu')
            ->set('email', 'nopass@techcombank.local')
            ->set('employee_code', 'NV999003')
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

    // ---- 3.1.3 Hợp đồng ----------------------------------------------------

    public function test_loc_hop_dong_theo_loai(): void
    {
        $fixed = ContractType::create(['code' => 'LT1', 'name' => 'Loại Xác Định']);
        $trial = ContractType::create(['code' => 'LT2', 'name' => 'Loại Thử Việc']);

        Contract::create([
            'employee_id' => Employee::factory()->create(['full_name' => 'NV Hợp Đồng Xác Định'])->id,
            'contract_type_id' => $fixed->id,
            'effective_from' => now()->toDateString(),
            'status' => Contract::STATUS_ACTIVE,
        ]);
        Contract::create([
            'employee_id' => Employee::factory()->create(['full_name' => 'NV Hợp Đồng Thử Việc'])->id,
            'contract_type_id' => $trial->id,
            'effective_from' => now()->toDateString(),
            'status' => Contract::STATUS_ACTIVE,
        ]);

        Livewire::test(ContractManager::class)
            ->set('typeFilter', (string) $fixed->id)
            ->assertSee('NV Hợp Đồng Xác Định')
            ->assertDontSee('NV Hợp Đồng Thử Việc');
    }

    public function test_tao_hop_dong_moi(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['code' => 'T1', 'name' => 'Xác định thời hạn']);

        Livewire::test(ContractManager::class)
            ->call('create')
            ->set('employee_id', $employee->id)
            ->set('contract_type_id', $type->id)
            ->set('effective_from', now()->toDateString())
            ->set('effective_to', now()->addYear()->toDateString())
            ->set('status', Contract::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('contracts', [
            'employee_id' => $employee->id,
            'contract_no' => 'HD-' . now()->year . '-0001',
        ]);
    }

    public function test_ngay_het_han_phai_sau_ngay_hieu_luc(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['code' => 'T2', 'name' => 'Thử việc']);

        Livewire::test(ContractManager::class)
            ->call('create')
            ->set('employee_id', $employee->id)
            ->set('contract_type_id', $type->id)
            ->set('effective_from', now()->toDateString())
            ->set('effective_to', now()->subDay()->toDateString())
            ->call('save')
            ->assertHasErrors(['effective_to' => 'after']);
    }

    public function test_dong_bo_trang_thai_danh_dau_hop_dong_het_han(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['code' => 'T3', 'name' => 'Ngắn hạn']);

        $expired = Contract::create([
            'contract_no' => 'HD-EXP-001',
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'effective_from' => now()->subYear(),
            'effective_to' => now()->subDay(),
            'status' => Contract::STATUS_ACTIVE,
        ]);

        $expiring = Contract::create([
            'contract_no' => 'HD-EXPIRING-001',
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'effective_from' => now()->subMonths(6),
            'effective_to' => now()->addDays(10),
            'status' => Contract::STATUS_ACTIVE,
        ]);

        // Không còn nút đồng bộ: chỉ cần mở màn Hợp đồng là trạng thái tự cập nhật
        Livewire::test(ContractManager::class);

        $this->assertSame(Contract::STATUS_EXPIRED, $expired->refresh()->status);
        $this->assertSame(Contract::STATUS_EXPIRING, $expiring->refresh()->status);
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
        $this->get(route('admin.contracts'))->assertRedirect(route('learn.events'));
    }

    public function test_quan_tri_vien_vao_duoc_man_hinh_tai_khoan_va_hop_dong(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin->refresh());
        $this->get(route('admin.accounts'))->assertOk();
        $this->get(route('admin.contracts'))->assertOk();
    }
}
