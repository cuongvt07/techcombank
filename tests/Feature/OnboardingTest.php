<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\User\Onboarding;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Setting;
use App\Models\SupportContact;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Màn chào mừng nhân viên mới (spec 4.4). */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->department = Department::factory()->create();

        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->user->assignRole(RoleName::EMPLOYEE->value);
        $this->employee = Employee::factory()->create([
            'user_id' => $this->user->id,
            'department_id' => $this->department->id,
            'is_new_hire' => true,
            'joined_at' => now()->subDays(5),
        ]);
    }

    private function enrollOnboardingCourse(bool $isOnboarding = true): Course
    {
        $course = Course::factory()->create([
            'status' => Course::STATUS_PUBLISHED,
            'is_onboarding' => $isOnboarding,
        ]);

        Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $this->employee->id,
            'status' => Enrollment::STATUS_IN_PROGRESS,
            'assigned_at' => now(),
            'total_lessons' => 1,
        ]);

        return $course;
    }

    // ---- Nội dung màn hình ---------------------------------------------------

    public function test_hien_thong_tin_cong_ty_tu_cau_hinh(): void
    {
        Setting::set('company.name', 'Techcombank', 'string', 'company');
        Setting::set('company.intro', 'Giới thiệu công ty mẫu.', 'string', 'company');

        Livewire::actingAs($this->user)
            ->test(Onboarding::class)
            ->assertSee('Techcombank')
            ->assertSee('Giới thiệu công ty mẫu.');
    }

    public function test_chi_hien_khoa_onboarding(): void
    {
        $onboarding = $this->enrollOnboardingCourse();
        $thuong = $this->enrollOnboardingCourse(isOnboarding: false);

        $courses = Livewire::actingAs($this->user)
            ->test(Onboarding::class)
            ->viewData('onboardingCourses')
            ->pluck('course.id');

        $this->assertContains($onboarding->id, $courses);
        $this->assertNotContains($thuong->id, $courses);
    }

    public function test_khong_hien_khoa_onboarding_cua_nguoi_khac(): void
    {
        $other = Employee::factory()->create();
        $course = Course::factory()->create([
            'status' => Course::STATUS_PUBLISHED,
            'is_onboarding' => true,
        ]);

        Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $other->id,
            'status' => Enrollment::STATUS_IN_PROGRESS,
            'assigned_at' => now(),
            'total_lessons' => 1,
        ]);

        $courses = Livewire::actingAs($this->user)
            ->test(Onboarding::class)
            ->viewData('onboardingCourses');

        $this->assertCount(0, $courses);
    }

    public function test_hien_dau_moi_cua_phong_minh_va_dau_moi_chung(): void
    {
        $otherDepartment = Department::factory()->create();

        SupportContact::create([
            'topic' => 'Đầu mối chung',
            'contact_name' => 'Người phụ trách',
            'department_id' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        SupportContact::create([
            'topic' => 'Đầu mối phòng tôi',
            'contact_name' => 'Người phụ trách',
            'department_id' => $this->department->id,
            'is_active' => true,
            'sort_order' => 2,
        ]);
        SupportContact::create([
            'topic' => 'Đầu mối phòng khác',
            'contact_name' => 'Người phụ trách',
            'department_id' => $otherDepartment->id,
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $topics = Livewire::actingAs($this->user)
            ->test(Onboarding::class)
            ->viewData('contacts')
            ->pluck('topic');

        $this->assertContains('Đầu mối chung', $topics);
        $this->assertContains('Đầu mối phòng tôi', $topics);
        $this->assertNotContains('Đầu mối phòng khác', $topics);
    }

    public function test_dau_moi_phong_minh_xep_truoc_dau_moi_chung(): void
    {
        // Đầu mối của chính phòng mình sát với việc nhân viên đang cần hơn
        SupportContact::create([
            'topic' => 'Chung',
            'contact_name' => 'Người phụ trách', 'department_id' => null,
            'is_active' => true, 'sort_order' => 1,
        ]);
        SupportContact::create([
            'topic' => 'Phòng tôi',
            'contact_name' => 'Người phụ trách', 'department_id' => $this->department->id,
            'is_active' => true, 'sort_order' => 2,
        ]);

        $topics = Livewire::actingAs($this->user)
            ->test(Onboarding::class)
            ->viewData('contacts')
            ->pluck('topic')
            ->all();

        $this->assertSame('Phòng tôi', $topics[0]);
    }

    public function test_khong_hien_dau_moi_da_tat(): void
    {
        SupportContact::create([
            'topic' => 'Đã tắt',
            'contact_name' => 'Người phụ trách', 'department_id' => null,
            'is_active' => false, 'sort_order' => 1,
        ]);

        $topics = Livewire::actingAs($this->user)
            ->test(Onboarding::class)
            ->viewData('contacts')
            ->pluck('topic');

        $this->assertNotContains('Đã tắt', $topics);
    }

    public function test_dem_dung_so_ngay_da_lam_viec(): void
    {
        $days = Livewire::actingAs($this->user)
            ->test(Onboarding::class)
            ->viewData('daysSinceJoined');

        $this->assertSame(5, $days);
    }

    public function test_chua_co_khoa_onboarding_van_hien_binh_thuong(): void
    {
        Livewire::actingAs($this->user)
            ->test(Onboarding::class)
            ->assertOk()
            ->assertSee('Chưa có khóa onboarding nào được gán');
    }

    // ---- Điều hướng ---------------------------------------------------------

    public function test_nhan_vien_moi_thay_muc_chao_mung_tren_menu(): void
    {
        $this->actingAs($this->user)
            ->get(route('learn.events'))
            ->assertSee('Chào mừng');
    }

    public function test_nhan_vien_cu_khong_thay_muc_chao_mung(): void
    {
        $this->employee->update(['is_new_hire' => false]);

        $this->actingAs($this->user->refresh())
            ->get(route('learn.events'))
            ->assertDontSee('Chào mừng');
    }

    public function test_nhan_vien_cu_van_vao_duoc_trang_neu_go_dia_chi(): void
    {
        // Ẩn khỏi menu nhưng không chặn: người cũ vẫn có thể muốn xem lại
        // thông tin công ty và đầu mối liên hệ
        $this->employee->update(['is_new_hire' => false]);

        $this->actingAs($this->user->refresh())
            ->get(route('learn.onboarding'))
            ->assertOk();
    }

    public function test_khach_chua_dang_nhap_bi_chuyen_ve_dang_nhap(): void
    {
        $this->get(route('learn.onboarding'))->assertRedirect(route('login'));
    }
}
