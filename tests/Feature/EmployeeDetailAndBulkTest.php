<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\EmployeeManager;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Services\EmployeeTimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Kiểm chứng trang chi tiết nhân viên và thao tác hàng loạt. */
class EmployeeDetailAndBulkTest extends TestCase
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

    // ---- Trang chi tiết -----------------------------------------------------

    public function test_mo_duoc_trang_chi_tiet_nhan_vien(): void
    {
        $employee = Employee::factory()->create(['full_name' => 'Nguyễn Chi Tiết']);

        $this->get(route('admin.employees.show', $employee))
            ->assertOk()
            ->assertSee('Nguyễn Chi Tiết')
            ->assertSee('Dòng thời gian');
    }

    public function test_chi_so_tong_hop_dung(): void
    {
        $employee = Employee::factory()->create();
        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();

        Enrollment::create([
            'course_id' => $courseA->id, 'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_COMPLETED, 'progress_percent' => 100,
            'assigned_at' => now(), 'total_lessons' => 1,
        ]);
        Enrollment::create([
            'course_id' => $courseB->id, 'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_OVERDUE, 'progress_percent' => 40,
            'assigned_at' => now(), 'total_lessons' => 2,
        ]);

        $stats = Livewire::test(EmployeeDetail::class, ['employee' => $employee])->viewData('stats');

        $this->assertSame(2, $stats['assigned']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['overdue']);
        $this->assertEquals(70, $stats['avg_progress']);
    }

    public function test_giao_khoa_hoc_tu_trang_chi_tiet(): void
    {
        $employee = Employee::factory()->create();
        $course = Course::factory()->create();

        Livewire::test(EmployeeDetail::class, ['employee' => $employee])
            ->call('openAssign')
            ->set('assignCourseId', $course->id)
            ->set('assignDueDays', 30)
            ->call('assignCourse')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('enrollments', [
            'employee_id' => $employee->id,
            'course_id' => $course->id,
            'is_mandatory' => true,
        ]);
    }

    public function test_khoa_da_giao_khong_hien_trong_danh_sach_chon(): void
    {
        $employee = Employee::factory()->create();
        $assigned = Course::factory()->create(['title' => 'Khóa Đã Giao Rồi']);
        $available = Course::factory()->create(['title' => 'Khóa Chưa Giao']);

        Enrollment::create([
            'course_id' => $assigned->id, 'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_NOT_STARTED, 'assigned_at' => now(), 'total_lessons' => 0,
        ]);

        $courses = Livewire::test(EmployeeDetail::class, ['employee' => $employee])
            ->viewData('assignableCourses');

        $titles = collect($courses)->pluck('title');
        $this->assertContains('Khóa Chưa Giao', $titles);
        $this->assertNotContains('Khóa Đã Giao Rồi', $titles);
    }

    public function test_go_khoa_hoc_giu_lai_lich_su(): void
    {
        $employee = Employee::factory()->create();
        $course = Course::factory()->create();

        $enrollment = Enrollment::create([
            'course_id' => $course->id, 'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_IN_PROGRESS, 'progress_percent' => 60,
            'assigned_at' => now(), 'total_lessons' => 5,
        ]);

        Livewire::test(EmployeeDetail::class, ['employee' => $employee])
            ->call('unassign', $enrollment->id);

        $enrollment->refresh();
        $this->assertSame(Enrollment::STATUS_CANCELLED, $enrollment->status);
        // Tiến độ đã học không bị xoá
        $this->assertEquals(60.0, (float) $enrollment->progress_percent);
    }

    public function test_khong_go_duoc_khoa_cua_nhan_vien_khac(): void
    {
        $employee = Employee::factory()->create();
        $other = Employee::factory()->create();
        $course = Course::factory()->create();

        $otherEnrollment = Enrollment::create([
            'course_id' => $course->id, 'employee_id' => $other->id,
            'status' => Enrollment::STATUS_IN_PROGRESS, 'assigned_at' => now(), 'total_lessons' => 1,
        ]);

        // findOrFail ràng buộc employee_id nên id của người khác không tìm thấy
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        try {
            Livewire::test(EmployeeDetail::class, ['employee' => $employee])
                ->call('unassign', $otherEnrollment->id);
        } finally {
            $this->assertSame(Enrollment::STATUS_IN_PROGRESS, $otherEnrollment->refresh()->status);
        }
    }

    // ---- Dòng thời gian -----------------------------------------------------

    public function test_dong_thoi_gian_gom_su_kien_tu_nhieu_nguon(): void
    {
        $employee = Employee::factory()->create();
        $course = Course::factory()->create(['title' => 'Khóa Trong Timeline']);

        Enrollment::create([
            'course_id' => $course->id, 'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_COMPLETED,
            'assigned_at' => now()->subDays(10),
            'completed_at' => now()->subDay(),
            'total_lessons' => 1,
        ]);

        $timeline = app(EmployeeTimelineService::class)->build($employee);

        $types = $timeline->pluck('type');
        $this->assertContains('enrollment', $types);
        $this->assertContains('course_completed', $types);
    }

    public function test_dong_thoi_gian_xep_moi_nhat_truoc(): void
    {
        $employee = Employee::factory()->create();
        $course = Course::factory()->create();

        Enrollment::create([
            'course_id' => $course->id, 'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_COMPLETED,
            'assigned_at' => now()->subDays(30),
            'completed_at' => now()->subDay(),
            'total_lessons' => 1,
        ]);

        $timeline = app(EmployeeTimelineService::class)->build($employee);

        $dates = $timeline->pluck('at')->values();
        for ($i = 1; $i < $dates->count(); $i++) {
            $this->assertTrue(
                $dates[$i - 1]->greaterThanOrEqualTo($dates[$i]),
                'Sự kiện phải xếp từ mới đến cũ'
            );
        }
    }

    // ---- Thao tác hàng loạt -------------------------------------------------

    public function test_chon_tat_ca_dong_tren_trang(): void
    {
        Employee::factory()->count(3)->create();

        $component = Livewire::test(EmployeeManager::class)->set('selectPage', true);

        // 3 nhân viên vừa tạo + 1 của admin trong setUp
        $this->assertCount(4, $component->get('selected'));
    }

    public function test_bo_chon_tat_ca(): void
    {
        Employee::factory()->count(3)->create();

        $component = Livewire::test(EmployeeManager::class)
            ->set('selectPage', true)
            ->call('clearSelection');

        $this->assertSame([], $component->get('selected'));
        $this->assertFalse($component->get('selectPage'));
    }

    public function test_giao_khoa_hoc_hang_loat(): void
    {
        $employees = Employee::factory()->count(3)->create();
        $course = Course::factory()->create();
        Lesson::factory()->create(['course_id' => $course->id]);

        Livewire::test(EmployeeManager::class)
            ->set('selected', $employees->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('openBulkAssign')
            ->set('bulkCourseId', $course->id)
            ->set('bulkDueDays', 30)
            ->call('bulkAssignCourse')
            ->assertHasNoErrors();

        $this->assertSame(3, Enrollment::where('course_id', $course->id)->count());

        // Hạn hoàn thành áp cho mọi bản ghi
        $enrollment = Enrollment::where('course_id', $course->id)->first();
        $this->assertSame(now()->addDays(30)->toDateString(), $enrollment->due_date->toDateString());
    }

    public function test_bo_qua_nhan_vien_nghi_viec_khi_giao_hang_loat(): void
    {
        $active = Employee::factory()->count(2)->create();
        $resigned = Employee::factory()->resigned()->create();
        $course = Course::factory()->create();

        $all = $active->pluck('id')->push($resigned->id)->map(fn ($id) => (string) $id)->all();

        Livewire::test(EmployeeManager::class)
            ->set('selected', $all)
            ->call('openBulkAssign')
            ->set('bulkCourseId', $course->id)
            ->call('bulkAssignCourse');

        // Chỉ 2 nhân viên đang làm việc được giao
        $this->assertSame(2, Enrollment::where('course_id', $course->id)->count());
        $this->assertSame(0, Enrollment::where('course_id', $course->id)
            ->where('employee_id', $resigned->id)->count());
    }

    public function test_bat_buoc_chon_khoa_hoc_khi_giao_hang_loat(): void
    {
        $employees = Employee::factory()->count(2)->create();

        Livewire::test(EmployeeManager::class)
            ->set('selected', $employees->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('openBulkAssign')
            ->call('bulkAssignCourse')
            ->assertHasErrors('bulkCourseId');
    }

    public function test_chuyen_chinh_thuc_hang_loat(): void
    {
        $probation = Employee::factory()->count(2)->create([
            'employment_status' => Employee::STATUS_PROBATION,
        ]);
        $official = Employee::factory()->create([
            'employment_status' => Employee::STATUS_OFFICIAL,
        ]);

        $all = $probation->pluck('id')->push($official->id)->map(fn ($id) => (string) $id)->all();

        Livewire::test(EmployeeManager::class)
            ->set('selected', $all)
            ->call('bulkMakeOfficial');

        foreach ($probation as $employee) {
            $this->assertSame(Employee::STATUS_OFFICIAL, $employee->refresh()->employment_status);
        }

        // Người đã chính thức không bị ghi thêm lịch sử thừa
        $this->assertSame(0, $official->assignmentHistories()->count());
    }

    public function test_sau_khi_thao_tac_hang_loat_thi_bo_chon(): void
    {
        $employees = Employee::factory()->count(2)->create();
        $course = Course::factory()->create();

        $component = Livewire::test(EmployeeManager::class)
            ->set('selected', $employees->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('openBulkAssign')
            ->set('bulkCourseId', $course->id)
            ->call('bulkAssignCourse');

        $this->assertSame([], $component->get('selected'));
    }
}
