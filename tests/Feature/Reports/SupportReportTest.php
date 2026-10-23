<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleName;
use App\Livewire\Admin\Reports\SupportReport;
use App\Models\Employee;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Reports\SupportReportService;
use App\Support\Reports\ReportPeriod;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Báo cáo yêu cầu hỗ trợ (SCR_009): KPI, tồn đọng theo tuần, lọc quá SLA, phân công hàng loạt, xuất file. */
class SupportReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $employee;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // 12:00 trưa 21/10 giờ VN → kỳ 30 ngày = 22/09 – 21/10, kỳ trước = 23/08 – 21/09
        Carbon::setTestNow('2026-10-21 05:00:00');

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Quản trị']);
        $this->admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $this->admin->id]);
        $this->employee = Employee::factory()->create();
        $this->actingAs($this->admin->refresh());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Tạo phiếu với mốc gửi/xong tùy ý; hạn SLA tự đặt theo ưu tiên + created_at. */
    private function ticket(array $attrs): SupportRequest
    {
        $request = new SupportRequest();
        $request->forceFill(array_merge([
            'ticket_no' => 'YC-T' . (++$this->seq),
            'employee_id' => $this->employee->id,
            'topic' => 'Khác',
            'subject' => 'Phiếu thử ' . $this->seq,
            'content' => 'Nội dung bí mật',
            'priority' => 'normal',
            'status' => SupportRequest::STATUS_NEW,
        ], $attrs));
        $request->save();

        return $request;
    }

    /** Bộ dữ liệu chuẩn cho KPI và bảng. */
    private function seedScenario(): array
    {
        $handler = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        return [
            // A: Cao, xong sau 4 giờ — đúng hạn (SLA 8h)
            'a' => $this->ticket(['priority' => 'high', 'topic' => 'Tài khoản', 'status' => 'resolved', 'assigned_to' => $handler->id,
                'created_at' => '2026-10-20 05:00:00', 'resolved_at' => '2026-10-20 09:00:00']),
            // B: Thường, xong sau 48 giờ — trễ (SLA 24h)
            'b' => $this->ticket(['priority' => 'normal', 'topic' => 'Kỹ thuật', 'status' => 'resolved', 'assigned_to' => $handler->id,
                'created_at' => '2026-10-10 05:00:00', 'resolved_at' => '2026-10-12 05:00:00']),
            // C: Thấp, đóng thẳng sau 10 giờ (không có resolved_at) — đúng hạn
            'c' => $this->ticket(['priority' => 'low', 'topic' => 'Tài khoản', 'status' => 'closed',
                'created_at' => '2026-10-15 05:00:00', 'closed_at' => '2026-10-15 15:00:00']),
            // D: Cao, mới, chưa phân công, hạn 20/10 13:00 UTC → đang quá SLA 16 giờ
            'd' => $this->ticket(['priority' => 'high', 'topic' => 'Kỹ thuật', 'created_at' => '2026-10-20 05:00:00']),
            // E: Thường, đang xử lý, còn hạn
            'e' => $this->ticket(['priority' => 'normal', 'topic' => 'Kỹ thuật', 'status' => 'in_progress', 'assigned_to' => $handler->id,
                'created_at' => '2026-10-21 00:00:00']),
            // F: gửi trước kỳ, vẫn mở, chưa phân công → tồn đọng + quá SLA, không tính "mới"
            'f' => $this->ticket(['priority' => 'normal', 'topic' => 'Khác', 'created_at' => '2026-08-01 05:00:00']),
            // G: kỳ trước, xong đúng hạn → tỷ lệ kỳ trước 100%
            'g' => $this->ticket(['priority' => 'normal', 'topic' => 'Khác', 'status' => 'resolved',
                'created_at' => '2026-09-01 05:00:00', 'resolved_at' => '2026-09-01 10:00:00']),
        ];
    }

    // ---- KPI --------------------------------------------------------------------

    public function test_kpi_dem_moi_mo_giai_quyet_trung_vi_va_ty_le_dung_sla(): void
    {
        $this->seedScenario();

        $kpi = app(SupportReportService::class)->kpis(ReportPeriod::make('30d'), []);

        $this->assertSame(5, $kpi['new']);              // A, B, C, D, E
        $this->assertSame(3, $kpi['open']);             // D, E, F (F gửi trước kỳ vẫn là tồn đọng)
        $this->assertSame(2, $kpi['open_over_sla']);    // D, F
        $this->assertSame(3, $kpi['resolved']);         // A, B, C (C đóng thẳng vẫn tính)
        $this->assertSame(10.0, $kpi['median_hours']);  // trung vị của 4h, 48h, 10h
        $this->assertSame(66.7, $kpi['sla_rate']);      // 2/3 đúng hạn
        $this->assertSame(100.0, $kpi['sla_rate_prev']);
        $this->assertSame(2, $kpi['unassigned']);       // D, F
    }

    public function test_kpi_theo_bo_loc_uu_tien_va_phu_trach(): void
    {
        $this->seedScenario();
        $service = app(SupportReportService::class);
        $period = ReportPeriod::make('30d');

        $high = $service->kpis($period, ['priority' => 'high']);
        $this->assertSame(2, $high['new']);
        $this->assertSame(1, $high['open']);
        $this->assertSame(4.0, $high['median_hours']);

        $none = $service->kpis($period, ['assignee' => 'none']);
        $this->assertSame(2, $none['open']);
        $this->assertSame(2, $none['unassigned']);
    }

    public function test_phan_bo_chu_de_tinh_trung_vi_va_canh_bao_chu_de_cham(): void
    {
        $this->seedScenario();

        $topics = app(SupportReportService::class)->topics(ReportPeriod::make('30d'), []);
        $rows = collect($topics['rows'])->keyBy('topic');

        $this->assertSame(3, $rows['Kỹ thuật']['count']);
        $this->assertSame(48.0, $rows['Kỹ thuật']['median_hours']);
        $this->assertSame(2, $rows['Tài khoản']['count']);
        $this->assertSame(7.0, $rows['Tài khoản']['median_hours']);
        $this->assertTrue($topics['alert']);
        $this->assertStringContainsString('Kỹ thuật', $topics['note']);
        $this->assertStringContainsString('vượt SLA 24 giờ', $topics['note']);
    }

    // ---- Tồn đọng theo tuần -------------------------------------------------------

    public function test_ton_dong_cuoi_moi_tuan_va_nhan_dinh_tang_lien_tiep(): void
    {
        // Ba tuần trọn: 28/09 – 18/10 (Thứ hai → Chủ nhật)
        $this->ticket(['created_at' => '2026-09-29 03:00:00']);                                                     // T1 mở
        $this->ticket(['created_at' => '2026-10-06 03:00:00', 'status' => 'resolved', 'resolved_at' => '2026-10-07 03:00:00']);
        $this->ticket(['created_at' => '2026-10-07 03:00:00']);                                                     // T3 mở
        $this->ticket(['created_at' => '2026-10-13 03:00:00', 'status' => 'resolved', 'resolved_at' => '2026-10-20 03:00:00']); // xong sau kỳ
        $this->ticket(['created_at' => '2026-10-14 03:00:00']);                                                     // T5 mở

        $weekly = app(SupportReportService::class)->weekly(ReportPeriod::make('custom', '2026-09-28', '2026-10-18'), []);

        $this->assertSame(['28/09–04/10', '05/10–11/10', '12/10–18/10'], $weekly['labels']);
        $this->assertSame([1, 2, 2], $weekly['new']);
        $this->assertSame([0, 1, 0], $weekly['resolved']);
        $this->assertSame([1, 2, 4], $weekly['backlog']);
        $this->assertSame('Tồn đọng tăng 2 tuần liên tiếp: mỗi tuần đều giải quyết ít hơn số nhận vào.', $weekly['note']);
    }

    // ---- Bảng + bộ lọc --------------------------------------------------------------

    public function test_bang_mac_dinh_chi_phieu_mo_qua_sla_xep_theo_qua_han(): void
    {
        $t = $this->seedScenario();

        $rows = Livewire::test(SupportReport::class)
            ->assertSee('Yêu cầu quá SLA')
            ->assertDontSee('Nội dung bí mật')
            ->viewData('rows');

        // F quá hạn lâu nhất đứng đầu, E còn hạn và các phiếu đã xong bị loại
        $this->assertSame([$t['f']->id, $t['d']->id], $rows->pluck('id')->all());
    }

    public function test_tat_chi_qua_sla_va_doi_trang_thai(): void
    {
        $t = $this->seedScenario();

        $all = Livewire::test(SupportReport::class)
            ->set('overSla', false)
            ->assertSee('Danh sách yêu cầu')
            ->viewData('rows');
        $this->assertEqualsCanonicalizing([$t['d']->id, $t['e']->id, $t['f']->id], $all->pluck('id')->all());

        // Mọi trạng thái + chỉ quá SLA: thêm B (giải quyết trễ), không có A/C (đúng hạn)
        $late = Livewire::test(SupportReport::class)->set('status', '')->viewData('rows');
        $this->assertEqualsCanonicalizing([$t['b']->id, $t['d']->id, $t['f']->id], $late->pluck('id')->all());

        $unassigned = Livewire::test(SupportReport::class)->set('overSla', false)->set('assignee', 'none')->viewData('rows');
        $this->assertEqualsCanonicalizing([$t['d']->id, $t['f']->id], $unassigned->pluck('id')->all());

        $topic = Livewire::test(SupportReport::class)->set('status', '')->set('topic', 'Kỹ thuật')->viewData('rows');
        $this->assertEqualsCanonicalizing([$t['b']->id, $t['d']->id], $topic->pluck('id')->all());
    }

    public function test_dat_lai_xoa_bo_loc(): void
    {
        Livewire::test(SupportReport::class)
            ->set('topic', 'Kỹ thuật')->set('overSla', false)->set('status', '')->call('setPreset', '7d')
            ->call('resetFilters')
            ->assertSet('topic', '')->assertSet('overSla', true)->assertSet('status', 'open')->assertSet('preset', '30d');
    }

    // ---- Phân công hàng loạt ------------------------------------------------------

    public function test_phan_cong_hang_loat_chuyen_phieu_moi_sang_dang_xu_ly(): void
    {
        $t = $this->seedScenario();
        $agent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $agent->givePermissionTo('support.update');

        Livewire::test(SupportReport::class)
            ->set('selected', [(string) $t['d']->id, (string) $t['e']->id, (string) $t['b']->id])
            ->set('assignTo', (string) $agent->id)
            ->call('bulkAssign')
            ->assertHasNoErrors()
            ->assertSet('selected', []);

        $this->assertSame($agent->id, $t['d']->fresh()->assigned_to);
        $this->assertSame(SupportRequest::STATUS_IN_PROGRESS, $t['d']->fresh()->status);
        // Phiếu đã có người phụ trách thì không bị phân công đè
        $this->assertNotSame($agent->id, $t['e']->fresh()->assigned_to);
        $this->assertNotNull($t['e']->fresh()->assigned_to);
        // Phiếu đã giải quyết không bị đổi người
        $this->assertNotSame($agent->id, $t['b']->fresh()->assigned_to);
        $this->assertSame(SupportRequest::STATUS_RESOLVED, $t['b']->fresh()->status);
    }

    public function test_phieu_da_co_nguoi_phu_trach_khong_co_o_chon(): void
    {
        $t = $this->seedScenario();

        Livewire::test(SupportReport::class)
            ->set('overSla', false)
            ->set('status', '')
            // Phiếu chưa phân công (d) có ô chọn; phiếu đang xử lý đã có người (e) thì không
            ->assertSeeHtml('aria-label="Chọn ' . $t['d']->ticket_no . '"')
            ->assertDontSeeHtml('aria-label="Chọn ' . $t['e']->ticket_no . '"');
    }

    public function test_khong_phan_cong_cho_nguoi_khong_co_quyen_xu_ly(): void
    {
        $t = $this->seedScenario();
        $outsider = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $outsider->assignRole(RoleName::EMPLOYEE->value);

        Livewire::test(SupportReport::class)
            ->set('selected', [(string) $t['d']->id])
            ->set('assignTo', (string) $outsider->id)
            ->call('bulkAssign')
            ->assertHasErrors(['assignTo' => 'in']);

        $this->assertNull($t['d']->fresh()->assigned_to);
    }

    public function test_thieu_quyen_sua_bao_cao_bi_chan_phan_cong(): void
    {
        $t = $this->seedScenario();
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->assignRole(RoleName::HR->value);
        $viewer->givePermissionTo('report_support.view');
        $this->actingAs($viewer);

        Livewire::test(SupportReport::class)
            ->assertDontSee('Phân công hàng loạt')
            ->call('openAssign')
            ->assertForbidden();

        Livewire::test(SupportReport::class)
            ->set('selected', [(string) $t['d']->id])
            ->set('assignTo', (string) $this->admin->id)
            ->call('bulkAssign')
            ->assertForbidden();

        $this->assertNull($t['d']->fresh()->assigned_to);
    }

    // ---- Trang + xuất file ---------------------------------------------------------

    public function test_trang_mo_duoc_va_chan_nguoi_khong_co_quyen_xem(): void
    {
        $this->seedScenario();

        $this->get(route('admin.reports.support'))
            ->assertOk()
            ->assertSee('SLA theo độ ưu tiên: Cao 8 giờ · Thường 24 giờ · Thấp 72 giờ (giờ lịch)')
            ->assertSee('Phân bố theo chủ đề');

        $hr = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $hr->assignRole(RoleName::HR->value);
        $this->actingAs($hr)->get(route('admin.reports.support'))->assertForbidden();
    }

    public function test_xuat_file_tra_ve_download(): void
    {
        $this->seedScenario();

        Livewire::test(SupportReport::class)->call('export', 'xlsx')->assertFileDownloaded();
        Livewire::test(SupportReport::class)->call('export', 'csv')->assertFileDownloaded();
    }

    public function test_trang_trong_khong_loi(): void
    {
        Livewire::test(SupportReport::class)
            ->assertOk()
            ->assertSee('Chưa có yêu cầu nào trong kỳ.')
            ->assertSee('Không có yêu cầu quá SLA khớp bộ lọc.');
    }
}
