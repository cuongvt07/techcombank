<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\CourseBuilder;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\EmployeeManager;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\DashboardMetricsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Kiểm chứng số liệu dashboard và cột dữ liệu tổng hợp trong bảng. */
class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private DashboardMetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin->refresh());

        $this->metrics = app(DashboardMetricsService::class);
    }

    // ---- Xu hướng hoàn thành ------------------------------------------------

    public function test_bieu_do_xu_huong_dien_du_moi_ngay(): void
    {
        $trend = $this->metrics->completionTrend(14);

        // Phải đủ 14 điểm kể cả ngày không có dữ liệu, nếu không biểu đồ sẽ
        // bóp méo khoảng thời gian
        $this->assertCount(14, $trend);
        $this->assertArrayHasKey('label', $trend->first());
        $this->assertArrayHasKey('value', $trend->first());
    }

    public function test_xu_huong_dem_dung_luot_hoan_thanh(): void
    {
        $employee = Employee::factory()->create();

        foreach ([1, 1, 3] as $daysAgo) {
            $course = Course::factory()->create();
            Enrollment::create([
                'course_id' => $course->id,
                'employee_id' => $employee->id,
                'status' => Enrollment::STATUS_COMPLETED,
                'assigned_at' => now()->subDays(10),
                'completed_at' => now()->subDays($daysAgo),
                'total_lessons' => 1,
            ]);
        }

        $trend = $this->metrics->completionTrend(7);
        $byLabel = $trend->keyBy('label');

        $this->assertSame(2, $byLabel[now()->subDay()->format('d/m')]['value']);
        $this->assertSame(1, $byLabel[now()->subDays(3)->format('d/m')]['value']);
    }

    public function test_luot_hoan_thanh_ngoai_khoang_khong_duoc_tinh(): void
    {
        $employee = Employee::factory()->create();
        $course = Course::factory()->create();

        Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_COMPLETED,
            'assigned_at' => now()->subDays(60),
            'completed_at' => now()->subDays(45),
            'total_lessons' => 1,
        ]);

        $this->assertSame(0, $this->metrics->completionTrend(7)->sum('value'));
    }

    // ---- Phân bố trạng thái -------------------------------------------------

    public function test_phan_bo_trang_thai_dem_dung(): void
    {
        $employee = Employee::factory()->create();

        foreach ([
            Enrollment::STATUS_COMPLETED,
            Enrollment::STATUS_COMPLETED,
            Enrollment::STATUS_IN_PROGRESS,
            Enrollment::STATUS_OVERDUE,
        ] as $status) {
            $course = Course::factory()->create();
            Enrollment::create([
                'course_id' => $course->id,
                'employee_id' => $employee->id,
                'status' => $status,
                'assigned_at' => now(),
                'total_lessons' => 1,
            ]);
        }

        $breakdown = $this->metrics->statusBreakdown()->keyBy('status');

        $this->assertSame(2, $breakdown[Enrollment::STATUS_COMPLETED]['value']);
        $this->assertSame(1, $breakdown[Enrollment::STATUS_IN_PROGRESS]['value']);
        $this->assertSame(1, $breakdown[Enrollment::STATUS_OVERDUE]['value']);
    }

    public function test_khoa_da_huy_khong_tinh_vao_phan_bo(): void
    {
        $employee = Employee::factory()->create();
        $course = Course::factory()->create();

        Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_CANCELLED,
            'assigned_at' => now(),
            'total_lessons' => 1,
        ]);

        $this->assertSame(0, $this->metrics->statusBreakdown()->sum('value'));
    }

    // ---- So sánh kỳ ---------------------------------------------------------

    public function test_chi_so_so_sanh_voi_ky_truoc(): void
    {
        $employee = Employee::factory()->create();

        // Kỳ này 2 lượt, kỳ trước 1 lượt => tăng 100%
        foreach ([5, 5] as $daysAgo) {
            $course = Course::factory()->create();
            Enrollment::create([
                'course_id' => $course->id, 'employee_id' => $employee->id,
                'status' => Enrollment::STATUS_COMPLETED,
                'assigned_at' => now()->subDays(40),
                'completed_at' => now()->subDays($daysAgo),
                'total_lessons' => 1,
            ]);
        }

        $course = Course::factory()->create();
        Enrollment::create([
            'course_id' => $course->id, 'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_COMPLETED,
            'assigned_at' => now()->subDays(60),
            'completed_at' => now()->subDays(40),
            'total_lessons' => 1,
        ]);

        $summary = $this->metrics->summary(30);

        $this->assertSame(2, $summary['completed']['value']);
        $this->assertEquals(100, $summary['completed']['delta']);
        $this->assertSame('up', $summary['completed']['trend']);
    }

    public function test_ky_truoc_bang_khong_thi_khong_tinh_phan_tram(): void
    {
        $employee = Employee::factory()->create();
        $course = Course::factory()->create();

        Enrollment::create([
            'course_id' => $course->id, 'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_COMPLETED,
            'assigned_at' => now()->subDays(10),
            'completed_at' => now()->subDays(2),
            'total_lessons' => 1,
        ]);

        $summary = $this->metrics->summary(30);

        // Chia cho 0 là vô nghĩa, chỉ báo xu hướng tăng
        $this->assertNull($summary['completed']['delta']);
        $this->assertSame('up', $summary['completed']['trend']);
    }

    // ---- Khóa học cần can thiệp ---------------------------------------------

    public function test_khoa_it_nguoi_hoc_khong_lot_danh_sach_can_can_thiep(): void
    {
        $course = Course::factory()->create();

        // Chỉ 2 người => mẫu quá nhỏ, không đủ kết luận
        foreach (range(1, 2) as $i) {
            Enrollment::create([
                'course_id' => $course->id,
                'employee_id' => Employee::factory()->create()->id,
                'status' => Enrollment::STATUS_IN_PROGRESS,
                'progress_percent' => 10,
                'assigned_at' => now(),
                'total_lessons' => 5,
            ]);
        }

        $this->assertCount(0, $this->metrics->strugglingCourses());
    }

    public function test_khoa_du_mau_va_tien_do_thap_lot_danh_sach(): void
    {
        $course = Course::factory()->create(['title' => 'Khóa Bị Bỏ Dở']);

        foreach (range(1, 4) as $i) {
            Enrollment::create([
                'course_id' => $course->id,
                'employee_id' => Employee::factory()->create()->id,
                'status' => Enrollment::STATUS_IN_PROGRESS,
                'progress_percent' => 15,
                'assigned_at' => now(),
                'total_lessons' => 5,
            ]);
        }

        $struggling = $this->metrics->strugglingCourses();

        $this->assertCount(1, $struggling);
        $this->assertSame('Khóa Bị Bỏ Dở', $struggling->first()->title);
        $this->assertEquals(15, $struggling->first()->avg_percent);
    }

    // ---- Tỉ lệ đạt bài kiểm tra ---------------------------------------------

    public function test_ti_le_dat_bai_kiem_tra(): void
    {
        $quiz = Quiz::factory()->create();
        $employee = Employee::factory()->create();

        foreach ([true, true, false] as $i => $passed) {
            QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'employee_id' => $employee->id,
                'attempt_no' => $i + 1,
                'status' => QuizAttempt::STATUS_GRADED,
                'percentage' => $passed ? 90 : 40,
                'is_passed' => $passed,
                'started_at' => now()->subHour(),
                'submitted_at' => now()->subMinutes(30),
            ]);
        }

        $stats = $this->metrics->quizPassRate(30);

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['passed']);
        $this->assertSame(67, $stats['rate']);
    }

    public function test_khong_co_luot_thi_thi_ti_le_bang_khong(): void
    {
        $stats = $this->metrics->quizPassRate(30);

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['rate']);
    }

    // ---- Dashboard render ---------------------------------------------------

    public function test_dashboard_hien_thi_duoc(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSee('Lượt hoàn thành theo ngày')
            ->assertSee('Trạng thái học tập')
            ->assertSee('Tiến độ theo phòng ban');
    }

    public function test_doi_ky_phan_tich(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSet('period', 30)
            ->call('setPeriod', 7)
            ->assertSet('period', 7)
            ->call('setPeriod', 90)
            ->assertSet('period', 90);
    }

    public function test_khong_nhan_ky_phan_tich_la(): void
    {
        Livewire::test(Dashboard::class)
            ->call('setPeriod', 999)
            ->assertSet('period', 30);
    }

    // ---- Cột dữ liệu tổng hợp trong bảng ------------------------------------

    public function test_bang_nhan_su_hien_tien_do_hoc_tap(): void
    {
        $employee = Employee::factory()->create(['full_name' => 'Người Có Tiến Độ']);

        foreach ([100, 40] as $percent) {
            $course = Course::factory()->create();
            Enrollment::create([
                'course_id' => $course->id,
                'employee_id' => $employee->id,
                'status' => $percent === 100 ? Enrollment::STATUS_COMPLETED : Enrollment::STATUS_IN_PROGRESS,
                'progress_percent' => $percent,
                'assigned_at' => now(),
                'total_lessons' => 2,
            ]);
        }

        $rows = Livewire::test(EmployeeManager::class)->viewData('employees');
        $row = collect($rows->items())->firstWhere('full_name', 'Người Có Tiến Độ');

        $this->assertSame(2, $row->assigned_count);
        $this->assertSame(1, $row->completed_count);
        $this->assertEquals(70, round($row->avg_progress));
    }

    public function test_khoa_da_huy_khong_tinh_vao_cot_tong_hop(): void
    {
        $employee = Employee::factory()->create(['full_name' => 'Người Bị Gỡ Khóa']);
        $course = Course::factory()->create();

        Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_CANCELLED,
            'progress_percent' => 50,
            'assigned_at' => now(),
            'total_lessons' => 1,
        ]);

        $rows = Livewire::test(EmployeeManager::class)->viewData('employees');
        $row = collect($rows->items())->firstWhere('full_name', 'Người Bị Gỡ Khóa');

        $this->assertSame(0, $row->assigned_count);
    }

    public function test_bang_khoa_hoc_hien_tien_do_chung(): void
    {
        $course = Course::factory()->create(['title' => 'Khóa Có Số Liệu']);

        foreach ([100, 50] as $percent) {
            Enrollment::create([
                'course_id' => $course->id,
                'employee_id' => Employee::factory()->create()->id,
                'status' => $percent === 100 ? Enrollment::STATUS_COMPLETED : Enrollment::STATUS_IN_PROGRESS,
                'progress_percent' => $percent,
                'assigned_at' => now(),
                'total_lessons' => 2,
            ]);
        }

        $rows = Livewire::test(CourseBuilder::class)->viewData('courses');
        $row = collect($rows->items())->firstWhere('title', 'Khóa Có Số Liệu');

        $this->assertSame(2, $row->enrollments_count);
        $this->assertSame(1, $row->completed_count);
        $this->assertEquals(75, round($row->avg_progress));
    }
}
