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

    // ---- Điều kiện nhiều phòng ban (bảng nối course_assignment_rule_department) ----

    public function test_rule_nhieu_phong_ban_giao_dung_nguoi(): void
    {
        $a = Department::factory()->create();
        $b = Department::factory()->create();
        $c = Department::factory()->create();
        $inA = Employee::factory()->count(2)->create(['department_id' => $a->id]);
        $inB = Employee::factory()->count(3)->create(['department_id' => $b->id]);
        $inC = Employee::factory()->create(['department_id' => $c->id]);

        $course = Course::factory()->create();
        $rule = CourseAssignmentRule::create(['course_id' => $course->id]);
        $rule->departments()->sync([$a->id, $b->id]);

        $this->assertSame(5, $this->service->applyRule($rule));
        $this->assertEqualsCanonicalizing(
            $inA->merge($inB)->pluck('id')->all(),
            Enrollment::where('course_id', $course->id)->pluck('employee_id')->all()
        );
        $this->assertFalse($rule->matches($inC));

        // Nhân viên mới vào phòng B cũng được giao qua syncForEmployee
        $newcomer = Employee::factory()->create(['department_id' => $b->id]);
        $this->assertSame(1, $this->service->syncForEmployee($newcomer));
    }

    public function test_rule_khong_chon_phong_ban_giao_moi_nhan_vien(): void
    {
        Employee::factory()->count(2)->create(['department_id' => Department::factory()->create()->id]);
        Employee::factory()->count(3)->create(['department_id' => Department::factory()->create()->id]);
        Employee::factory()->resigned()->create();

        $course = Course::factory()->create();
        $rule = CourseAssignmentRule::create(['course_id' => $course->id]);

        $this->assertNull($rule->allowedDepartmentIds());
        $this->assertSame(Employee::active()->count(), $this->service->applyRule($rule));
    }

    public function test_phong_con_khop_khi_bat_gom_phong_con(): void
    {
        $parent = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $parent->id]);
        $grandChild = Department::factory()->create(['parent_id' => $child->id]);
        $employee = Employee::factory()->create(['department_id' => $grandChild->id]);

        $withSub = CourseAssignmentRule::create([
            'course_id' => Course::factory()->create()->id,
            'include_sub_departments' => true,
        ]);
        $withSub->departments()->sync([$parent->id]);

        $withoutSub = CourseAssignmentRule::create([
            'course_id' => Course::factory()->create()->id,
            'include_sub_departments' => false,
        ]);
        $withoutSub->departments()->sync([$parent->id]);

        $this->assertTrue($withSub->fresh()->matches($employee));
        $this->assertFalse($withoutSub->fresh()->matches($employee));
        $this->assertSame(1, $this->service->syncForEmployee($employee));
    }

    public function test_rule_chi_nham_phong_ban_da_xoa_khong_thanh_giao_moi_nguoi(): void
    {
        $closed = Department::factory()->create();
        $rule = CourseAssignmentRule::create(['course_id' => Course::factory()->create()->id]);
        $rule->departments()->sync([$closed->id]);
        $closed->delete();

        $employee = Employee::factory()->create();

        $this->assertSame([$closed->id], $rule->fresh()->allowedDepartmentIds());
        $this->assertFalse($rule->fresh()->matches($employee));
    }

    public function test_cot_department_id_cu_van_khop_va_gop_voi_bang_noi(): void
    {
        // Dữ liệu trước khi chuyển sang bảng nối vẫn còn cột cũ
        $legacy = Department::factory()->create();
        $added = Department::factory()->create();
        $rule = CourseAssignmentRule::create([
            'course_id' => Course::factory()->create()->id,
            'department_id' => $legacy->id,
        ]);
        $rule->departments()->sync([$added->id]);
        $rule = $rule->fresh();

        $this->assertTrue($rule->matches(Employee::factory()->create(['department_id' => $legacy->id])));
        $this->assertTrue($rule->matches(Employee::factory()->create(['department_id' => $added->id])));
        $this->assertFalse($rule->matches(Employee::factory()->create()));
        $this->assertEqualsCanonicalizing([$legacy->id, $added->id], $rule->targetDepartments()->pluck('id')->all());
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
