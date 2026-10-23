<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\Dashboard;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\JobTitle;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\SecurityAlert;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\DashboardFilter;
use App\Services\DashboardMetricsService;
use App\Support\Reports\ReportPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Số liệu + thao tác trang Tổng quan đào tạo (SCR_001).
 * Giờ cố định 21/10/2026 12:00 (VN) để ranh giới ngày không làm test chập chờn.
 */
class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private DashboardMetricsService $metrics;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-21 05:00:00', 'UTC'));
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole(RoleName::ADMIN->value);
        // Tài khoản admin nghỉ việc để không lẫn vào số nhân sự của test
        Employee::factory()->resigned()->create(['user_id' => $this->admin->id]);
        $this->actingAs($this->admin->refresh());

        $this->metrics = app(DashboardMetricsService::class);
    }

    /** Kỳ 30 ngày: 22/09/2026 – 21/10/2026 (VN). Kỳ trước: 23/08 – 21/09. */
    private function filter(array $overrides = []): DashboardFilter
    {
        return new DashboardFilter(...array_merge(['period' => ReportPeriod::make('30d')], $overrides));
    }

    private function enroll(?Employee $employee = null, array $attrs = []): Enrollment
    {
        return Enrollment::create(array_merge([
            'course_id' => Course::factory()->create()->id,
            'employee_id' => ($employee ?? Employee::factory()->create())->id,
            'is_mandatory' => true,
            'status' => Enrollment::STATUS_IN_PROGRESS,
            'progress_percent' => 40,
            'assigned_at' => now()->subDays(60),
            'total_lessons' => 5,
        ], $attrs));
    }

    // ---- KPI ----------------------------------------------------------------

    public function test_hoan_thanh_dung_han_tinh_tren_luot_den_han_trong_ky(): void
    {
        // Đúng hạn (xong 10/10 VN, hạn 10/10)
        $this->enroll(null, ['due_date' => '2026-10-10', 'status' => 'completed', 'completed_at' => '2026-10-10 16:00:00']);
        // 16:30 UTC = 23:30 VN cùng ngày hạn → vẫn đúng hạn (so theo ngày VN, không theo UTC)
        $this->enroll(null, ['due_date' => '2026-10-10', 'status' => 'completed', 'completed_at' => '2026-10-10 16:30:00']);
        // Trễ thật: 18:00 UTC 10/10 = 01:00 VN 11/10
        $this->enroll(null, ['due_date' => '2026-10-10', 'status' => 'completed', 'completed_at' => '2026-10-10 18:00:00']);
        // Chưa xong, đã đến hạn
        $this->enroll(null, ['due_date' => '2026-10-15']);
        // Hạn ngoài kỳ / trong tương lai: không tính
        $this->enroll(null, ['due_date' => '2026-09-01', 'status' => 'completed', 'completed_at' => '2026-08-30 00:00:00']);
        $this->enroll(null, ['due_date' => '2026-10-25']);

        $kpi = $this->metrics->onTime($this->filter());

        $this->assertSame(4, $kpi['due']);
        $this->assertSame(2, $kpi['on_time']);
        $this->assertEqualsWithDelta(50.0, $kpi['value'], 0.01);
        $this->assertCount(30, $kpi['series']);
        $this->assertEqualsWithDelta(50.0, end($kpi['series']), 0.01);

        // Kỳ trước chỉ có lượt hạn 01/09 (đúng hạn)
        $prev = $this->metrics->onTime($this->filter()->withPeriod($this->filter()->comparePeriod()));
        $this->assertSame(1, $prev['due']);
        $this->assertEqualsWithDelta(100.0, $prev['value'], 0.01);
    }

    public function test_hoc_vien_hoat_dong_gom_mo_khoa_tien_do_bai_va_hoan_thanh(): void
    {
        $a = Employee::factory()->create();
        $b = Employee::factory()->create();
        $c = Employee::factory()->create();
        Employee::factory()->create(); // không hoạt động

        $this->enroll($a, ['last_accessed_at' => now()->subDays(2)]);
        $this->enroll($a, ['last_accessed_at' => now()->subDays(3)]); // cùng người, không đếm 2 lần
        $enrollmentB = $this->enroll($b, ['last_accessed_at' => now()->subDays(50)]);
        DB::table('lesson_progresses')->insert([
            'enrollment_id' => $enrollmentB->id, 'lesson_id' => Lesson::factory()->create(['course_id' => $enrollmentB->course_id])->id,
            'employee_id' => $b->id, 'status' => 'in_progress', 'time_spent_seconds' => 7200,
            'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5),
        ]);
        $this->enroll($c, ['status' => 'completed', 'completed_at' => now()->subDay()]);

        $kpi = $this->metrics->activeLearners($this->filter());

        $this->assertSame(3, $kpi['value']);
        $this->assertSame(4, $kpi['headcount']); // admin nghỉ việc bị loại theo mặc định
        $this->assertSame(1, $kpi['series'][28]); // hôm qua: c
        $this->assertSame(1, $kpi['series'][24]); // 5 ngày trước: b (tiến độ bài học)
        $this->assertSame(4, array_sum($kpi['series'])); // a có 2 ngày hoạt động
    }

    public function test_tong_gio_hoc_theo_ngay_cap_nhat_ban_ghi(): void
    {
        $e = $this->enroll();
        $lesson = Lesson::factory()->create(['course_id' => $e->course_id]);
        $lesson2 = Lesson::factory()->create(['course_id' => $e->course_id]);
        DB::table('lesson_progresses')->insert([
            ['enrollment_id' => $e->id, 'lesson_id' => $lesson->id, 'employee_id' => $e->employee_id, 'status' => 'completed',
                'time_spent_seconds' => 5400, 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)],
            ['enrollment_id' => $e->id, 'lesson_id' => $lesson2->id, 'employee_id' => $e->employee_id, 'status' => 'completed',
                'time_spent_seconds' => 3600, 'created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)],
        ]);

        $this->assertEqualsWithDelta(1.5, $this->metrics->learningHours($this->filter())['value'], 0.001);

        $card = Livewire::test(Dashboard::class)->viewData('cards')['hours'];
        $this->assertSame('1,5', $card['value']);
        $this->assertSame('▲ 50,0%', $card['delta'][0]);
        $this->assertSame('good', $card['delta'][1]);
        $this->assertSame('trung bình 1,5 giờ/học viên', $card['note']);
    }

    public function test_qua_han_tinh_bang_han_khong_tin_cot_status(): void
    {
        $emp = Employee::factory()->create();
        $this->enroll($emp, ['due_date' => '2026-10-01', 'status' => 'in_progress']); // quá hạn dù status in_progress
        $this->enroll($emp, ['due_date' => '2026-10-02', 'status' => 'not_started']);
        $this->enroll(null, ['due_date' => '2026-09-10', 'status' => 'overdue']);     // quá hạn từ kỳ trước
        $this->enroll(null, ['due_date' => '2026-10-30', 'status' => 'overdue']);     // status cũ nhưng chưa tới hạn
        $this->enroll(null, ['due_date' => '2026-10-01', 'status' => 'completed', 'completed_at' => '2026-10-05 00:00:00']);
        $this->enroll(null, ['due_date' => '2026-10-01', 'status' => 'cancelled']);

        $kpi = $this->metrics->overdue($this->filter());
        $this->assertSame(3, $kpi['value']);
        $this->assertSame(2, $kpi['employees']);
        $this->assertSame(3, $kpi['courses']);

        // Cuối kỳ trước (21/09): lượt hạn 10/09 quá hạn; lượt xong 05/10 khi đó chưa tới hạn
        $prev = $this->metrics->overdue($this->filter()->withPeriod($this->filter()->comparePeriod()), false);
        $this->assertSame(1, $prev['value']);

        $card = Livewire::test(Dashboard::class)->viewData('cards')['overdue'];
        $this->assertSame('▲ 200,0%', $card['delta'][0]);
        $this->assertSame('bad', $card['delta'][1]); // quá hạn tăng là xấu
        $this->assertTrue($card['alert']);
        $this->assertSame('2 nhân viên · 3 khóa', $card['note']);
    }

    public function test_dat_kiem_tra_lan_dau(): void
    {
        $quiz = Quiz::factory()->create();
        $make = fn (int $no, bool $passed, float $pct, $at, string $status = QuizAttempt::STATUS_GRADED) => QuizAttempt::create([
            'quiz_id' => $quiz->id, 'employee_id' => Employee::factory()->create()->id, 'attempt_no' => $no,
            'status' => $status, 'percentage' => $pct, 'is_passed' => $passed,
            'started_at' => $at, 'submitted_at' => $at,
        ]);
        $make(1, true, 90, now()->subDays(2));
        $make(1, false, 50, now()->subDays(3));
        $make(2, true, 100, now()->subDays(1));          // lần 2: không tính
        $make(1, true, 80, now()->subDays(45));          // ngoài kỳ
        $make(1, false, 0, now()->subDay(), QuizAttempt::STATUS_IN_PROGRESS); // chưa nộp

        $kpi = $this->metrics->firstPass($this->filter());

        $this->assertSame(2, $kpi['total']);
        $this->assertSame(1, $kpi['passed']);
        $this->assertEqualsWithDelta(50.0, $kpi['value'], 0.01);
        $this->assertEqualsWithDelta(70.0, $kpi['avg_score'], 0.01);
    }

    // ---- Bộ lọc -------------------------------------------------------------

    public function test_loc_don_vi_gom_phong_con_chuc_danh_loai_khoa_va_trang_thai_nhan_su(): void
    {
        $block = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $block->id]);
        $other = Department::factory()->create();
        $title = JobTitle::factory()->create();

        $inChild = Employee::factory()->create(['department_id' => $child->id, 'job_title_id' => $title->id]);
        $inBlock = Employee::factory()->create(['department_id' => $block->id]);
        $outside = Employee::factory()->create(['department_id' => $other->id, 'job_title_id' => $title->id]);
        $resigned = Employee::factory()->resigned()->create(['department_id' => $child->id]);

        foreach ([$inChild, $inBlock, $outside, $resigned] as $emp) {
            $this->enroll($emp, ['due_date' => '2026-10-01']);
        }
        $this->enroll($inChild, ['due_date' => '2026-10-01', 'is_mandatory' => false]);

        $count = fn (array $o) => $this->metrics->overdue($this->filter($o), false)['value'];

        $this->assertSame(4, $count([])); // nghỉ việc bị loại mặc định
        $this->assertSame(5, $count(['employmentStatuses' => []]));
        $this->assertSame(3, $count(['departmentId' => $block->id]));
        $this->assertSame(2, $count(['departmentId' => $child->id]));
        $this->assertSame(3, $count(['jobTitleId' => $title->id]));
        $this->assertSame(3, $count(['courseType' => DashboardFilter::COURSE_MANDATORY]));
        $this->assertSame(1, $count(['courseType' => DashboardFilter::COURSE_ELECTIVE]));

        // Component: đổi bộ lọc trên URL thì mọi khối dùng chung phạm vi
        Livewire::test(Dashboard::class)
            ->set('departmentId', $block->id)
            ->assertViewHas('statusCounts', fn ($c) => $c['overdue'] === 3)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3)
            ->call('resetFilters')
            ->assertSet('departmentId', null)
            ->assertSet('employmentStatuses', DashboardFilter::DEFAULT_STATUSES)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 4);
    }

    public function test_doi_ky_bao_cao_va_so_sanh(): void
    {
        $this->enroll(null, ['status' => 'completed', 'completed_at' => now()->subDays(10)]);

        Livewire::test(Dashboard::class)
            ->assertViewHas('trend', fn ($t) => count($t['labels']) === 30 && array_sum($t['completions']) === 1)
            ->call('setPreset', '7d')
            ->assertViewHas('trend', fn ($t) => count($t['labels']) === 7 && array_sum($t['completions']) === 0)
            ->set('compare', DashboardFilter::COMPARE_LAST_YEAR)
            ->assertViewHas('filter', fn ($f) => $f->comparePeriod()->dateRange() === ['2025-10-15', '2025-10-21']);
    }

    // ---- Donut, đơn vị, khóa, bảng ------------------------------------------

    public function test_lat_donut_loc_bang_nhan_vien_can_nhac(): void
    {
        $this->enroll(null, ['due_date' => '2026-10-01']);                         // quá hạn
        $this->enroll(null, ['due_date' => '2026-11-01']);                         // đang học
        $this->enroll(null, ['due_date' => '2026-11-01', 'status' => 'not_started', 'progress_percent' => 0]);
        $this->enroll(null, ['status' => 'completed', 'completed_at' => now()->subDays(3)]);
        $this->enroll(null, ['status' => 'completed', 'completed_at' => now()->subDays(90)]); // xong trước kỳ: ngoài phạm vi

        $this->assertSame(
            ['completed' => 1, 'in_progress' => 1, 'not_started' => 1, 'overdue' => 1],
            $this->metrics->statusBreakdown($this->filter()),
        );

        Livewire::test(Dashboard::class)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3) // mặc định: chưa hoàn thành
            ->call('filterStatus', 'overdue')
            ->assertSet('statusFilter', 'overdue')
            ->assertSee('Đang lọc: Quá hạn')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
            ->call('filterStatus', 'overdue') // bấm lại = bỏ lọc
            ->assertSet('statusFilter', null);
    }

    public function test_tien_do_don_vi_cham_nhat_len_dau_mau_nho_xuong_cuoi(): void
    {
        $slow = Department::factory()->create(['name' => 'Đơn vị chậm']);
        $fast = Department::factory()->create(['name' => 'Đơn vị nhanh']);
        $tiny = Department::factory()->create(['name' => 'Đơn vị nhỏ']);

        foreach (range(1, 5) as $i) {
            $this->enroll(Employee::factory()->create(['department_id' => $slow->id]),
                $i === 1 ? ['status' => 'completed', 'completed_at' => now()->subDay()] : []);
            $this->enroll(Employee::factory()->create(['department_id' => $fast->id]),
                $i <= 4 ? ['status' => 'completed', 'completed_at' => now()->subDay()] : []);
        }
        $this->enroll(Employee::factory()->create(['department_id' => $tiny->id]));

        $units = $this->metrics->departmentProgress($this->filter());

        $this->assertSame(['Đơn vị chậm', 'Đơn vị nhanh', 'Đơn vị nhỏ'], $units['rows']->pluck('name')->all());
        $this->assertEqualsWithDelta(20.0, $units['rows'][0]['rate'], 0.01);
        $this->assertTrue($units['rows'][2]['small']);
        $this->assertEqualsWithDelta(5 / 11 * 100, $units['overall'], 0.01);
    }

    public function test_khoa_can_can_thiep_chi_xet_khoa_du_mau_co_qua_han(): void
    {
        $big = Course::factory()->create(['title' => 'Khóa đông']);
        $small = Course::factory()->create(['title' => 'Khóa ít người']);

        foreach (range(1, 5) as $i) {
            $this->enroll(null, ['course_id' => $big->id, 'due_date' => $i <= 2 ? '2026-10-01' : '2026-11-30']);
        }
        foreach (range(1, 4) as $i) {
            $this->enroll(null, ['course_id' => $small->id, 'due_date' => '2026-10-01']);
        }

        $courses = $this->metrics->coursesNeedingAction($this->filter());

        $this->assertCount(1, $courses);
        $this->assertSame('Khóa đông', $courses->first()->title);
        $this->assertEquals(2, $courses->first()->overdue);
        $this->assertEqualsWithDelta(40.0, (float) $courses->first()->avg_progress, 0.01);
    }

    public function test_viec_can_xu_ly_dem_canh_bao_chua_dong_va_phieu_qua_sla(): void
    {
        foreach ([SecurityAlert::STATUS_OPEN, SecurityAlert::STATUS_ACKNOWLEDGED, SecurityAlert::STATUS_RESOLVED] as $status) {
            SecurityAlert::create(['type' => SecurityAlert::TYPE_BRUTE_FORCE, 'severity' => 'high', 'title' => 'Thử', 'status' => $status]);
        }
        SupportRequest::create([
            'ticket_no' => 'HT-1', 'employee_id' => Employee::factory()->create()->id, 'topic' => 'Khác',
            'subject' => 'A', 'content' => 'B', 'priority' => 'high', 'status' => SupportRequest::STATUS_NEW,
            'sla_due_at' => now()->subHour(),
        ]);
        SupportRequest::create([
            'ticket_no' => 'HT-2', 'employee_id' => Employee::factory()->create()->id, 'topic' => 'Khác',
            'subject' => 'A', 'content' => 'B', 'priority' => 'low', 'status' => SupportRequest::STATUS_NEW,
        ]);

        $this->assertSame(['open_alerts' => 2, 'over_sla' => 1], $this->metrics->actionItems());

        Livewire::test(Dashboard::class)
            ->assertSee('cảnh báo bảo mật chưa đóng')
            ->assertSee('yêu cầu hỗ trợ đang mở quá SLA')
            ->assertSeeHtml('href="' . route('admin.security') . '"')
            ->assertSeeHtml('href="' . route('admin.support') . '"');
    }

    public function test_an_dong_viec_can_xu_ly_khi_bang_khong(): void
    {
        Livewire::test(Dashboard::class)
            ->assertDontSee('cảnh báo bảo mật chưa đóng')
            ->assertDontSee('quá SLA');
    }

    // ---- Gửi nhắc & phân quyền ----------------------------------------------

    public function test_gui_nhac_tao_thong_bao_va_vao_cooldown(): void
    {
        $e = $this->enroll(null, ['due_date' => '2026-10-01']);

        Livewire::test(Dashboard::class)
            ->assertSee('Gửi nhắc')
            ->call('remind', $e->id)
            ->assertSet('notice', 'Đã gửi nhắc 1 lượt.')
            ->assertSee('Đã nhắc 12:00')
            // Thời gian chờ cố định 2 giờ
            ->assertSee('nhắc lại sau 14:00');

        $this->assertNotNull($e->fresh()->last_reminded_at);
        $this->assertSame(1, Announcement::where('employee_id', $e->employee_id)->count());
    }

    public function test_gui_nhac_hang_loat_bo_qua_luot_trong_cooldown(): void
    {
        $a = $this->enroll(null, ['due_date' => '2026-10-01']);
        $b = $this->enroll(null, ['due_date' => '2026-10-01', 'last_reminded_at' => now()->subHour()]);
        $c = $this->enroll(null, ['due_date' => '2026-10-01']);

        Livewire::test(Dashboard::class)
            ->set('selected', [(string) $a->id, (string) $b->id])
            ->call('remindSelected')
            ->assertSet('selected', [])
            ->assertSet('notice', fn ($n) => str_starts_with($n, 'Đã gửi nhắc 1 lượt · bỏ qua 1 lượt'));

        $this->assertSame(1, Announcement::count());
        $this->assertNull($c->fresh()->last_reminded_at);

        // Qua 2 giờ thì nhắc lại được
        $this->travel(2)->hours();
        $this->travel(1)->minutes();
        Livewire::test(Dashboard::class)
            ->set('selected', [(string) $b->id])
            ->call('remindSelected')
            ->assertSet('notice', fn ($n) => str_starts_with($n, 'Đã gửi nhắc 1 lượt'));
        $this->assertSame(2, Announcement::count());
    }

    public function test_chon_ca_trang(): void
    {
        $ids = collect(range(1, 3))->map(fn () => (string) $this->enroll(null, ['due_date' => '2026-10-01'])->id)->all();

        Livewire::test(Dashboard::class)
            ->call('togglePage', $ids)
            ->assertSet('selected', $ids)
            ->call('togglePage', $ids)
            ->assertSet('selected', []);
    }

    public function test_thieu_quyen_bao_cao_hoc_tap_thi_khong_gui_nhac_duoc(): void
    {
        $e = $this->enroll(null, ['due_date' => '2026-10-01']);

        $hr = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $hr->assignRole(RoleName::HR->value); // có dashboard.view, không có report_learning.update
        $this->actingAs($hr);

        Livewire::test(Dashboard::class)
            ->assertDontSee('Gửi nhắc hàng loạt')
            ->assertDontSeeHtml('wire:click="remind(' . $e->id . ')"')
            ->assertDontSee('Báo cáo chi tiết') // không có quyền xem báo cáo nào
            ->call('remind', $e->id)
            ->assertForbidden();

        Livewire::test(Dashboard::class)->set('selected', [(string) $e->id])->call('remindSelected')->assertForbidden();

        $this->assertNull($e->fresh()->last_reminded_at);
        $this->assertSame(0, Announcement::count());
    }

    public function test_menu_bao_cao_chi_tiet_chi_hien_bao_cao_duoc_xem(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['dashboard.view', 'report_quiz.view']);
        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertSee('Báo cáo chi tiết')
            ->assertSeeHtml('href="' . route('admin.reports.quiz') . '"')
            ->assertDontSeeHtml('href="' . route('admin.reports.security') . '"')
            ->assertDontSee('Xem tất cả trong báo cáo');
    }

    public function test_khong_co_quyen_tong_quan_thi_chuyen_toi_man_duoc_xem(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('employees.view');
        $this->actingAs($user);

        Livewire::test(Dashboard::class)->assertRedirect(route('admin.employees'));
    }

    public function test_trang_tai_duoc_qua_http_va_trang_thai_trong(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Tổng quan đào tạo')
            ->assertSee('Dữ liệu cập nhật lúc 12:00 · 21/10/2026 (GMT+7)')
            ->assertSee('Không có lượt ghi danh nào trong phạm vi lọc.');
    }
}
