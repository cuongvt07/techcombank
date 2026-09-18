<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kiểm chứng phân tách hai site và render giao diện (spec mục 1). */
class SiteAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_khach_chua_dang_nhap_bi_chuyen_ve_trang_dang_nhap(): void
    {
        $this->get('/quan-tri')->assertRedirect(route('login'));
        $this->get('/hoc-tap/khoa-hoc')->assertRedirect(route('login'));
    }

    public function test_trang_dang_nhap_hien_thi_duoc(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Đăng nhập hệ thống');
    }

    public function test_admin_vao_duoc_dashboard_quan_tri(): void
    {
        $admin = $this->makeUser(RoleName::ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Tổng quan đào tạo');
    }

    public function test_nhan_vien_thuong_bi_chan_khoi_site_quan_tri(): void
    {
        $employee = $this->makeUser(RoleName::EMPLOYEE);

        $this->actingAs($employee)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('learn.events'));
    }

    public function test_nhan_vien_xem_duoc_danh_sach_khoa_hoc_cua_minh(): void
    {
        $user = $this->makeUser(RoleName::EMPLOYEE);
        $course = Course::factory()->create(['title' => 'Khóa học An toàn thông tin']);

        Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $user->employee->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('learn.courses'))
            ->assertOk()
            ->assertSee('Khóa học An toàn thông tin');
    }

    public function test_nhan_vien_khong_thay_khoa_hoc_cua_nguoi_khac(): void
    {
        $user = $this->makeUser(RoleName::EMPLOYEE);
        $other = Employee::factory()->create();
        $course = Course::factory()->create(['title' => 'Khóa học của người khác']);

        Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $other->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('learn.courses'))
            ->assertOk()
            ->assertDontSee('Khóa học của người khác');
    }

    public function test_trang_chu_dieu_huong_theo_vai_tro(): void
    {
        $admin = $this->makeUser(RoleName::ADMIN);
        $this->actingAs($admin)->get('/')->assertRedirect(route('admin.dashboard'));

        $employee = $this->makeUser(RoleName::EMPLOYEE);
        $this->actingAs($employee)->get('/')->assertRedirect(route('learn.events'));
    }

    public function test_dang_xuat_va_chuyen_ve_trang_dang_nhap(): void
    {
        $user = $this->makeUser(RoleName::EMPLOYEE);

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    private function makeUser(RoleName $role): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($role->value);
        Employee::factory()->create(['user_id' => $user->id]);

        return $user->refresh();
    }
}
