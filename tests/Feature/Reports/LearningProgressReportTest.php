<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleName;
use App\Livewire\Admin\Reports\LearningProgressReport;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\CourseAssignmentRule;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Reports\LearningProgressReportService;
use App\Support\Reports\ReportPeriod;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Báo cáo tiến độ học tập: KPI, bộ lọc, lọc theo ngày từ biểu đồ, gửi nhắc, xuất file. */
class LearningProgressReportTest extends TestCase
{
    use RefreshDatabase;

    private LearningProgressReportService $service;

    private Department $parent;

    private Department $child;

    private Department $other;

    private Course $courseA;

    private Course $courseB;

    /** @var array<string, Enrollment> */
    private array $e = [];

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 UTC = 17:00 giờ VN ngày 21/10/2026; kỳ 30 ngày = 22/09 → 21/10
        Carbon::setTestNow('2026-10-21 10:00:00');

        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin->refresh());

        $this->service = app(LearningProgressReportService::class);

        $this->parent = Department::factory()->create(['name' => 'Khối Vận hành']);
        $this->child = Department::factory()->create(['name' => 'Phòng Thẻ', 'parent_id' => $this->parent->id]);
        $this->other = Department::factory()->create(['name' => 'Chi nhánh Hà Nội']);
        $this->courseA = Course::factory()->create(['title' => 'An toàn thông tin']);
        $this->courseB = Course::factory()->create(['title' => 'Phòng chống rửa tiền']);
        $rule = CourseAssignmentRule::create(['course_id' => $this->courseA->id, 'is_mandatory' => true]);

        $inParent = Employee::factory()->create(['department_id' => $this->parent->id, 'full_name' => 'Nguyễn Minh Anh']);
        $inChild = Employee::factory()->create(['department_id' => $this->child->id, 'full_name' => 'Trần Quốc Bảo']);
        $inOther = Employee::factory()->create(['department_id' => $this->other->id, 'full_name' => 'Lê Hồng Diệp']);
        $resigned = Employee::factory()->create(['department_id' => $this->other->id, 'employment_status' => Employee::STATUS_RESIGNED]);

        // Hoàn thành đúng hạn: xong 23:30 UTC 09/10 = 06:30 ngày 10/10 giờ VN, hạn 10/10
        $this->e['onTime'] = $this->enroll($inParent, $this->courseA, [
            'status' => 'completed', 'progress_percent' => 100, 'completed_lessons' => 4, 'total_lessons' => 4,
            'completed_at' => '2026-10-09 23:30:00', 'due_date' => '2026-10-10', 'course_assignment_rule_id' => $rule->id,
        ]);
        // Hoàn thành trễ: xong 15/10, hạn 12/10
        $this->e['late'] = $this->enroll($inChild, $this->courseA, [
            'status' => 'completed', 'progress_percent' => 100, 'completed_lessons' => 4, 'total_lessons' => 4,
            'completed_at' => '2026-10-15 03:00:00', 'due_date' => '2026-10-12', 'course_assignment_rule_id' => $rule->id,
        ]);
        // Quá hạn: hạn 01/10, mới học 25% (status trong CSDL vẫn in_progress)
        $this->e['overdue'] = $this->enroll($inOther, $this->courseB, [
            'status' => 'in_progress', 'progress_percent' => 25, 'completed_lessons' => 1, 'total_lessons' => 4, 'due_date' => '2026-10-01',
        ]);
        // Đang học, hạn hôm nay (chưa quá hạn, sắp đến hạn)
        $this->e['learning'] = $this->enroll($inParent, $this->courseB, [
            'status' => 'in_progress', 'progress_percent' => 50, 'completed_lessons' => 2, 'total_lessons' => 4,
            'due_date' => '2026-10-21', 'is_mandatory' => false,
        ]);
        // Chưa bắt đầu, nhân viên đã nghỉ, hạn 20/10 → quá hạn
        $this->e['resigned'] = $this->enroll($resigned, $this->courseB, [
            'status' => 'not_started', 'total_lessons' => 4, 'due_date' => '2026-10-20',
        ]);
        // Chưa bắt đầu, hạn ngoài kỳ (05/11) → chỉ hiện khi đổi mốc / kỳ
        $this->e['future'] = $this->enroll($inChild, $this->courseB, [
            'status' => 'not_started', 'total_lessons' => 4, 'due_date' => '2026-11-05',
        ]);
        // Đã hủy: không bao giờ tính
        $this->enroll($inOther, $this->courseA, ['status' => 'cancelled', 'due_date' => '2026-10-05']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enroll(Employee $employee, Course $course, array $attrs): Enrollment
    {
        return Enrollment::create(array_merge([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'is_mandatory' => true,
            'assigned_at' => '2026-09-15 02:00:00',
        ], $attrs));
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::make('30d');
    }

    /** @return array<int, int> id các dòng của bảng (mọi trang) */
    private function tableIds(array $filters): array
    {
        return $this->service->rows($filters, $this->period(), 'due', 'asc', 50)->pluck('id')->sort()->values()->all();
    }

    private function ids(string ...$keys): array
    {
        return collect($keys)->map(fn ($k) => $this->e[$k]->id)->sort()->values()->all();
    }

    // ---- KPI ----------------------------------------------------------------

    public function test_kpi_chia_trang_thai_va_ty_le_dung_han(): void
    {
        $k = $this->service->kpis([], $this->period());

        // 5 lượt có hạn trong kỳ (bỏ lượt hạn 05/11 và lượt đã hủy)
        $this->assertSame(5, $k['total']);
        $this->assertSame(2, $k['completed']);
        $this->assertSame(1, $k['in_progress']);
        $this->assertSame(0, $k['not_started']);
        // "Quá hạn" tính từ due_date, không phụ thuộc cột status
        $this->assertSame(2, $k['overdue']);
        $this->assertSame(2, $k['overdue_employees']);
        $this->assertSame(40.0, $k['completed_pct']);
        $this->assertSame(40.0, $k['overdue_pct']);
        // Đúng hạn: 1 (onTime, so theo ngày VN) / 4 lượt đã có kết quả (2 xong + 2 quá hạn)
        $this->assertSame(1, $k['on_time']);
        $this->assertSame(4, $k['judged']);
        $this->assertSame(25.0, $k['on_time_pct']);
    }

    public function test_kpi_khong_co_du_lieu_tra_ve_rong(): void
    {
        $k = $this->service->kpis(['course' => 999999], $this->period());

        $this->assertSame(0, $k['total']);
        $this->assertNull($k['completed_pct']);
        $this->assertNull($k['on_time_pct']);
    }

    // ---- Bộ lọc -------------------------------------------------------------

    public function test_moc_thoi_gian_quyet_dinh_cot_ap_dung_ky(): void
    {
        // Ngày giao 15/09 nằm ngoài kỳ 22/09–21/10
        $this->assertSame(0, $this->service->kpis(['basis' => 'assigned'], $this->period())['total']);
        // Ngày hoàn thành: chỉ 2 lượt đã xong
        $this->assertSame($this->ids('onTime', 'late'), $this->tableIds(['basis' => 'completed']));
        // Mốc hạn + kỳ tùy chọn tới 10/11 → có cả lượt hạn 05/11
        $wide = ReportPeriod::make('custom', '2026-09-22', '2026-11-10');
        $this->assertSame(6, $this->service->kpis([], $wide)['total']);
    }

    public function test_loc_don_vi_gom_phong_con(): void
    {
        $this->assertSame($this->ids('onTime', 'late', 'learning'), $this->tableIds(['department' => $this->parent->id]));
        $this->assertSame($this->ids('late'), $this->tableIds(['department' => $this->child->id]));
    }

    public function test_loc_khoa_hoc_trang_thai_va_tim_kiem(): void
    {
        $this->assertSame($this->ids('onTime', 'late'), $this->tableIds(['course' => $this->courseA->id]));
        $this->assertSame($this->ids('overdue', 'resigned'), $this->tableIds(['status' => 'overdue']));
        $this->assertSame($this->ids('learning'), $this->tableIds(['status' => 'in_progress']));
        $this->assertSame($this->ids('onTime', 'late'), $this->tableIds(['status' => 'completed']));
        // Quá hạn + sắp đến hạn (hạn hôm nay còn trong 7 ngày)
        $this->assertSame($this->ids('overdue', 'resigned', 'learning'), $this->tableIds(['status' => 'attention']));
        $this->assertSame($this->ids('learning'), $this->tableIds(['status' => 'due_soon']));
        // Tìm theo họ tên / đơn vị / tên khóa
        $this->assertSame($this->ids('onTime', 'learning'), $this->tableIds(['search' => 'Minh Anh']));
        $this->assertSame($this->ids('late'), $this->tableIds(['search' => 'Phòng Thẻ']));
        $this->assertSame($this->ids('onTime', 'late'), $this->tableIds(['search' => 'An toàn']));

        // Trạng thái chỉ lọc bảng, KPI giữ nguyên
        $this->assertSame(5, $this->service->kpis(['status' => 'overdue'], $this->period())['total']);
    }

    public function test_bo_loc_nang_cao(): void
    {
        $this->assertSame($this->ids('learning'), $this->tableIds(['courseType' => 'elective']));
        $this->assertSame($this->ids('overdue', 'learning'), $this->tableIds(['progressMin' => 10, 'progressMax' => 80]));
        $this->assertSame($this->ids('onTime', 'late'), $this->tableIds(['source' => 'rule']));
        $this->assertSame($this->ids('overdue', 'learning', 'resigned'), $this->tableIds(['source' => 'manual']));
        $this->assertSame($this->ids('onTime', 'late', 'overdue', 'learning'), $this->tableIds(['excludeResigned' => true]));
    }

    public function test_tong_cong_cuoi_bang(): void
    {
        $t = $this->service->totals([], $this->period());

        $this->assertSame(5, $t['total']);
        $this->assertSame(11, $t['lessons_done']);
        $this->assertSame(20, $t['lessons_total']);
        $this->assertSame(55.0, $t['avg_progress']);
        $this->assertSame(3, $t['unfinished']);
        $this->assertSame(2, $t['overdue']);
    }

    // ---- Biểu đồ ------------------------------------------------------------

    public function test_bieu_do_dem_theo_ngay_vn_va_luy_ke(): void
    {
        $chart = $this->service->chart([], $this->period(), 5);
        $byDate = array_combine($chart['dates'], $chart['bars']);
        $line = array_combine($chart['dates'], $chart['line']);

        $this->assertCount(30, $chart['dates']);
        // 23:30 UTC 09/10 là ngày 10/10 giờ VN
        $this->assertSame(0, $byDate['2026-10-09']);
        $this->assertSame(1, $byDate['2026-10-10']);
        $this->assertSame(1, $byDate['2026-10-15']);
        $this->assertSame(0.0, $line['2026-10-09']);
        $this->assertSame(20.0, $line['2026-10-10']);
        $this->assertSame(40.0, $line['2026-10-21']);
    }

    public function test_bam_cot_bieu_do_loc_bang_theo_ngay_hoan_thanh(): void
    {
        $index = array_search('2026-10-10', $this->period()->dates()->all(), true);

        $component = Livewire::test(LearningProgressReport::class)
            ->call('filterCompletedOn', $index)
            ->assertSet('completedOn', '2026-10-10')
            ->assertSee('Ngày hoàn thành: 10/10/2026');

        $this->assertSame([$this->e['onTime']->id], $component->viewData('rows')->pluck('id')->all());

        // Bấm lại cùng cột / bấm ✕ trên chip → bỏ lọc
        $component->call('clearFilter', 'completedOn')->assertSet('completedOn', '');
        $this->assertSame(5, $component->viewData('rows')->total());
    }

    // ---- Component, gửi nhắc, xuất ------------------------------------------

    public function test_trang_hien_thi_va_bam_the_kpi_loc_trang_thai(): void
    {
        $this->get(route('admin.reports.learning'))->assertOk()->assertSee('Danh sách lượt ghi danh');

        Livewire::test(LearningProgressReport::class)
            ->call('filterStatus', 'overdue')
            ->assertSet('status', 'overdue')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2)
            ->call('filterStatus', 'overdue')
            ->assertSet('status', '')
            ->set('courseType', 'elective')
            ->assertSee('Loại khóa: Tự chọn')
            ->call('resetFilters')
            ->assertSet('courseType', '');
    }

    public function test_gui_nhac_hang_loat_khong_chon_thi_nhac_moi_luot_chua_xong(): void
    {
        Livewire::test(LearningProgressReport::class)
            ->call('sendReminders')
            ->assertSet('notice', 'Đã gửi nhắc 3 lượt.');

        $this->assertSame(3, Announcement::whereNotNull('employee_id')->count());
        $this->assertNotNull($this->e['overdue']->fresh()->last_reminded_at);
        $this->assertNull($this->e['onTime']->fresh()->last_reminded_at);
        // Lượt ngoài bộ lọc (hạn 05/11) không bị nhắc
        $this->assertNull($this->e['future']->fresh()->last_reminded_at);
    }

    public function test_gui_nhac_lai_bi_chan_trong_2_gio(): void
    {
        $only = [(string) $this->e['overdue']->id];

        Livewire::test(LearningProgressReport::class)->set('selected', $only)->call('sendReminders');
        Livewire::test(LearningProgressReport::class)->set('selected', $only)->call('sendReminders')
            ->assertSet('notice', fn ($n) => str_contains($n, 'vừa được nhắc trong 2 giờ'));
        $this->assertSame(1, Announcement::whereNotNull('employee_id')->count());

        $this->travel(121)->minutes();
        Livewire::test(LearningProgressReport::class)->set('selected', $only)->call('sendReminders');
        $this->assertSame(2, Announcement::whereNotNull('employee_id')->count());
    }

    public function test_gui_nhac_chi_cac_dong_dang_chon(): void
    {
        Livewire::test(LearningProgressReport::class)
            ->set('selected', [(string) $this->e['overdue']->id, (string) $this->e['onTime']->id])
            ->call('sendReminders')
            ->assertSet('selected', []);

        $this->assertSame(1, Announcement::whereNotNull('employee_id')->count());
        $this->assertNotNull($this->e['overdue']->fresh()->last_reminded_at);
        $this->assertNull($this->e['learning']->fresh()->last_reminded_at);
    }

    public function test_gui_nhac_bi_chan_khi_thieu_quyen_sua(): void
    {
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo('report_learning.view');
        $this->actingAs($viewer);

        Livewire::test(LearningProgressReport::class)
            ->assertDontSee('Gửi nhắc hàng loạt')
            ->call('sendReminders')
            ->assertForbidden();

        $this->assertSame(0, Announcement::whereNotNull('employee_id')->count());
    }

    public function test_xuat_file_theo_bo_loc_va_cot_hien_thi(): void
    {
        Livewire::test(LearningProgressReport::class)
            ->call('export', 'xlsx')
            ->assertFileDownloaded();

        // Nội dung CSV: đúng cột đang bật, đúng dòng khớp bộ lọc
        $component = Livewire::test(LearningProgressReport::class)
            ->set('status', 'overdue')
            ->set('columns', ['code', 'course', 'status']);
        $response = $component->instance()->export('csv');
        $csv = file_get_contents($response->getFile()->getPathname());
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('"Mã NV","Tên khóa","Trạng thái"', $lines[0]);
        $this->assertStringContainsString('Quá hạn', $lines[1]);
    }
}
