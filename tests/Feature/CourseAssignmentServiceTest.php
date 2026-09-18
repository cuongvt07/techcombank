<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAssignmentRule;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\JobTitle;
use App\Services\CourseAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kiểm chứng gán khoá học theo điều kiện ở spec 3.2.4 và onboarding ở spec 4.4. */
class CourseAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private CourseAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CourseAssignmentService::class);
    }

    public function test_gan_khoa_hoc_cho_nhan_vien_khop_phong_ban(): void
    {
        $department = Department::factory()->create();
        $course = Course::factory()->create();
        CourseAssignmentRule::create([
            'course_id' => $course->id,
            'department_id' => $department->id,
            'is_mandatory' => true,
        ]);

        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $created = $this->service->syncForEmployee($employee);

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('enrollments', [
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'is_mandatory' => true,
        ]);
    }

    public function test_khong_gan_khi_khong_khop_dieu_kien(): void
    {
        $target = Department::factory()->create();
        $other = Department::factory()->create();
        $course = Course::factory()->create();
        CourseAssignmentRule::create(['course_id' => $course->id, 'department_id' => $target->id]);

        $employee = Employee::factory()->create(['department_id' => $other->id]);

        $this->assertSame(0, $this->service->syncForEmployee($employee));
    }

    public function test_khong_gan_khoa_hoc_chua_xuat_ban(): void
    {
        $department = Department::factory()->create();
        $course = Course::factory()->draft()->create();
        CourseAssignmentRule::create(['course_id' => $course->id, 'department_id' => $department->id]);

        $employee = Employee::factory()->create(['department_id' => $department->id]);

        $this->assertSame(0, $this->service->syncForEmployee($employee));
    }

    public function test_khong_tao_enrollment_trung_lap_khi_sync_nhieu_lan(): void
    {
        $department = Department::factory()->create();
        $course = Course::factory()->create();
        CourseAssignmentRule::create(['course_id' => $course->id, 'department_id' => $department->id]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);

        $this->service->syncForEmployee($employee);
        $second = $this->service->syncForEmployee($employee);

        $this->assertSame(0, $second);
        $this->assertSame(1, Enrollment::where('employee_id', $employee->id)->count());
    }

    public function test_dieu_kien_ket_hop_phong_ban_va_chuc_danh(): void
    {
        $department = Department::factory()->create();
        $jobTitle = JobTitle::factory()->create();
        $course = Course::factory()->create();
        CourseAssignmentRule::create([
            'course_id' => $course->id,
            'department_id' => $department->id,
            'job_title_id' => $jobTitle->id,
        ]);

        // Đúng phòng ban nhưng sai chức danh => không gán
        $wrongTitle = Employee::factory()->create(['department_id' => $department->id]);
        $this->assertSame(0, $this->service->syncForEmployee($wrongTitle));

        // Khớp cả hai => gán
        $matched = Employee::factory()->create([
            'department_id' => $department->id,
            'job_title_id' => $jobTitle->id,
        ]);
        $this->assertSame(1, $this->service->syncForEmployee($matched));
    }

    public function test_han_hoan_thanh_tinh_tu_due_days_cua_rule(): void
    {
        $department = Department::factory()->create();
        $course = Course::factory()->create();
        CourseAssignmentRule::create([
            'course_id' => $course->id,
            'department_id' => $department->id,
            'due_days' => 15,
        ]);

        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $this->service->syncForEmployee($employee);

        $enrollment = Enrollment::where('employee_id', $employee->id)->first();
        $this->assertSame(now()->addDays(15)->toDateString(), $enrollment->due_date->toDateString());
    }

    public function test_nhan_vien_moi_duoc_gan_khoa_onboarding(): void
    {
        $onboarding = Course::factory()->onboarding()->create();
        Course::factory()->create(); // khoá thường, không được gán tự động

        $employee = Employee::factory()->newHire()->create();
        $this->service->syncForEmployee($employee);

        $this->assertDatabaseHas('enrollments', [
            'course_id' => $onboarding->id,
            'employee_id' => $employee->id,
        ]);
        $this->assertSame(1, Enrollment::where('employee_id', $employee->id)->count());
    }

    public function test_nhan_vien_cu_khong_duoc_gan_khoa_onboarding(): void
    {
        Course::factory()->onboarding()->create();
        $employee = Employee::factory()->create(['is_new_hire' => false]);

        $this->assertSame(0, $this->service->syncForEmployee($employee));
    }

    public function test_nhan_vien_nghi_viec_khong_duoc_gan_them_khoa(): void
    {
        $department = Department::factory()->create();
        $course = Course::factory()->create();
        CourseAssignmentRule::create(['course_id' => $course->id, 'department_id' => $department->id]);

        $employee = Employee::factory()->resigned()->create(['department_id' => $department->id]);

        $this->assertSame(0, $this->service->syncForEmployee($employee));
    }

    public function test_ap_rule_cho_toan_bo_nhan_vien_khop(): void
    {
        $department = Department::factory()->create();
        Employee::factory()->count(3)->create(['department_id' => $department->id]);
        Employee::factory()->count(2)->create(); // khác phòng ban

        $course = Course::factory()->create();
        $rule = CourseAssignmentRule::create([
            'course_id' => $course->id,
            'department_id' => $department->id,
        ]);

        $this->assertSame(3, $this->service->applyRule($rule));
    }

    public function test_go_gan_chi_danh_dau_huy_va_giu_lai_lich_su(): void
    {
        $course = Course::factory()->create();
        $employee = Employee::factory()->create();
        $enrollment = $this->service->assignManually($employee, $course);

        $this->service->unassign($enrollment);

        // Bản ghi vẫn còn, chỉ đổi trạng thái - lịch sử học tập không bị xoá
        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'status' => Enrollment::STATUS_CANCELLED,
        ]);
    }

    public function test_gan_lai_khoa_da_huy_thi_kich_hoat_lai_ban_ghi_cu(): void
    {
        $course = Course::factory()->create();
        $employee = Employee::factory()->create();
        $enrollment = $this->service->assignManually($employee, $course);
        $this->service->unassign($enrollment);

        $reassigned = $this->service->assignManually($employee, $course);

        $this->assertSame($enrollment->id, $reassigned->id);
        $this->assertSame(Enrollment::STATUS_NOT_STARTED, $reassigned->refresh()->status);
        $this->assertSame(1, Enrollment::where('employee_id', $employee->id)->count());
    }
}
