<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAssignmentRule;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignmentHistory;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kiểm chứng audit trail và cập nhật quyền khi đổi vị trí (spec 3.1.2 + 3.3.1). */
class EmployeeServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmployeeService::class);
    }

    public function test_dieu_chuyen_ghi_lich_su_va_dong_ban_ghi_cu(): void
    {
        $old = Department::factory()->create();
        $new = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $old->id]);

        $this->service->recordInitialAssignment($employee);
        $this->service->reassign($employee, ['department_id' => $new->id], 'Điều chuyển nội bộ');

        $histories = EmployeeAssignmentHistory::where('employee_id', $employee->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $histories);
        // Bản ghi cũ phải được đóng lại, chỉ còn đúng một bản đang hiệu lực
        $this->assertNotNull($histories[0]->effective_to, 'Bản ghi đầu phải bị đóng');
        $this->assertNull($histories[1]->effective_to, 'Bản ghi mới phải đang hiệu lực');
        $this->assertSame($old->id, $histories[0]->department_id);
        $this->assertSame($new->id, $histories[1]->department_id);
        $this->assertSame($new->id, $employee->refresh()->department_id);

        // Bất biến quan trọng: tại mọi thời điểm chỉ có đúng một bản ghi đang hiệu lực
        $this->assertSame(1, EmployeeAssignmentHistory::where('employee_id', $employee->id)
            ->whereNull('effective_to')
            ->count());
    }

    public function test_khong_ghi_lich_su_khi_khong_co_thay_doi(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);

        $created = $this->service->reassign($employee, ['department_id' => $department->id]);

        $this->assertSame(0, $created);
        $this->assertSame(0, $employee->assignmentHistories()->count());
    }

    public function test_doi_phong_ban_thi_duoc_gan_khoa_hoc_cua_phong_moi(): void
    {
        $oldDept = Department::factory()->create();
        $newDept = Department::factory()->create();
        $course = Course::factory()->create();

        CourseAssignmentRule::create([
            'course_id' => $course->id,
            'department_id' => $newDept->id,
        ]);

        $employee = Employee::factory()->create(['department_id' => $oldDept->id]);
        $this->assertSame(0, Enrollment::where('employee_id', $employee->id)->count());

        $created = $this->service->reassign($employee, ['department_id' => $newDept->id]);

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('enrollments', [
            'employee_id' => $employee->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_nghi_viec_thi_khoa_tai_khoan_nhung_giu_lich_su_hoc(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        $course = Course::factory()->create();

        $enrollment = Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_IN_PROGRESS,
            'progress_percent' => 60,
            'assigned_at' => now(),
            'total_lessons' => 5,
        ]);

        $this->service->resign($employee, 'Chuyển công tác');

        $this->assertSame(Employee::STATUS_RESIGNED, $employee->refresh()->employment_status);
        $this->assertNotNull($employee->resigned_at);
        // Tài khoản bị vô hiệu hóa tự động
        $this->assertSame(User::STATUS_DISABLED, $user->refresh()->status);
        $this->assertFalse($user->canLogin());
        // Nhưng lịch sử học tập vẫn còn nguyên
        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'progress_percent' => 60.00,
        ]);
    }

    public function test_nhan_vien_nghi_viec_khong_duoc_gan_them_khoa_moi(): void
    {
        $department = Department::factory()->create();
        $course = Course::factory()->create();
        CourseAssignmentRule::create(['course_id' => $course->id, 'department_id' => $department->id]);

        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $this->service->resign($employee);

        // Điều chuyển sau khi nghỉ việc không được kéo theo khoá học nào
        $created = $this->service->reassign($employee->refresh(), ['job_grade_id' => null], 'Thử');

        $this->assertSame(0, $created);
    }

    public function test_chuyen_chinh_thuc_ghi_dung_trang_thai(): void
    {
        $employee = Employee::factory()->newHire()->create();

        $this->service->makeOfficial($employee);

        $this->assertSame(Employee::STATUS_OFFICIAL, $employee->refresh()->employment_status);
        $this->assertDatabaseHas('employee_assignment_histories', [
            'employee_id' => $employee->id,
            'employment_status' => Employee::STATUS_OFFICIAL,
            'change_reason' => 'Chuyển chính thức',
        ]);
    }
}
