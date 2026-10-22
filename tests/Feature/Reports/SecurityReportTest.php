<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleName;
use App\Livewire\Admin\Reports\SecurityReport;
use App\Models\Employee;
use App\Models\LoginHistory;
use App\Models\SecurityAlert;
use App\Models\User;
use App\Services\Reports\SecurityReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Báo cáo bảo mật và đăng nhập (SCR_008): chỉ số, bản đồ nhiệt giờ VN, giới hạn kỳ, che mã NV, xuất file. */
class SecurityReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // 05:00 UTC = 12:00 thứ Năm 22/10/2026 giờ VN. Kỳ 7 ngày: 16/10 – 22/10 (giờ VN)
        $this->travelTo(CarbonImmutable::parse('2026-10-22 05:00:00', 'UTC'));

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $this->admin->id]);

        $this->staff = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Employee::factory()->create(['user_id' => $this->staff->id, 'employee_code' => 'NV001234']);

        $this->actingAs($this->admin->refresh());
    }

    private function login(string $utc, string $result = 'success', ?User $user = null): LoginHistory
    {
        return LoginHistory::create([
            'user_id' => ($user ?? $this->staff)->id,
            'ip_address' => '10.0.0.1',
            'result' => $result,
            'failure_reason' => $result === 'success' ? null : 'Sai thông tin đăng nhập',
            'logged_in_at' => CarbonImmutable::parse($utc, 'UTC'),
        ]);
    }

    private function alert(string $createdUtc, array $attrs = []): SecurityAlert
    {
        $alert = SecurityAlert::create($attrs + [
            'user_id' => $this->staff->id,
            'type' => SecurityAlert::TYPE_BRUTE_FORCE,
            'severity' => 'high',
            'title' => 'Dò mật khẩu',
            'detail' => '6 lần sai mật khẩu',
            'status' => SecurityAlert::STATUS_OPEN,
        ]);
        $alert->forceFill(['created_at' => CarbonImmutable::parse($createdUtc, 'UTC')])->save();

        return $alert->refresh();
    }

    // ---- Quyền --------------------------------------------------------------

    public function test_trang_mo_duoc_va_bi_chan_khi_thieu_quyen(): void
    {
        $this->get(route('admin.reports.security'))
            ->assertOk()
            ->assertSee('Báo cáo bảo mật và đăng nhập')
            ->assertSee('Mở Trung tâm bảo mật');

        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->assignRole(RoleName::HR->value);
        $this->actingAs($viewer)->get(route('admin.reports.security'))->assertForbidden();
    }

    // ---- Chỉ số -------------------------------------------------------------

    public function test_kpi_dang_nhap_ty_le_that_bai_va_chenh_lech_ky_truoc(): void
    {
        // Kỳ này: 8 thành công, 1 thất bại, 1 bị chặn -> 20%
        foreach (range(1, 8) as $i) {
            $this->login('2026-10-20 03:00:00');
        }
        $this->login('2026-10-21 03:00:00', 'failed');
        $this->login('2026-10-21 04:00:00', 'blocked');
        // Kỳ trước (09–15/10 VN): 9 thành công, 1 thất bại -> 10%
        foreach (range(1, 9) as $i) {
            $this->login('2026-10-12 03:00:00');
        }
        $this->login('2026-10-12 04:00:00', 'failed');
        // Ngoài cả hai kỳ
        $this->login('2026-09-01 03:00:00', 'failed');

        User::factory()->create(['status' => User::STATUS_LOCKED]);

        $kpis = Livewire::test(SecurityReport::class)->viewData('kpis');

        $this->assertSame(10, $kpis['logins']);
        $this->assertSame(2, $kpis['failed']);
        $this->assertEqualsWithDelta(20.0, $kpis['fail_rate'], 0.001);
        $this->assertEqualsWithDelta(10.0, $kpis['fail_rate_delta'], 0.001);
        $this->assertSame(1, $kpis['locked_accounts']);
        $this->assertSame(1, $kpis['blocked_attempts']);
    }

    public function test_ranh_gioi_ngay_theo_gio_viet_nam(): void
    {
        // 15/10 17:30 UTC = 16/10 00:30 giờ VN -> thuộc kỳ; 15/10 16:30 UTC = 15/10 23:30 VN -> không
        $this->login('2026-10-15 17:30:00');
        $this->login('2026-10-15 16:30:00');

        $component = Livewire::test(SecurityReport::class);

        $this->assertSame(1, $component->viewData('kpis')['logins']);
        $daily = $component->viewData('daily');
        $this->assertCount(7, $daily);
        $this->assertSame('T6 16/10', $daily->first()['label']);
        $this->assertSame(1, $daily->first()['success']);
    }

    public function test_mtta_mttr_la_trung_vi_tren_tap_da_loc(): void
    {
        $base = CarbonImmutable::parse('2026-10-20 00:00:00', 'UTC');

        // Ghi nhận sau 1h, 2h, 10h -> trung vị 2h. Đóng sau 4h, 6h -> trung vị 5h
        $this->alert('2026-10-20 00:00:00', ['status' => 'resolved', 'acknowledged_at' => $base->addHour(), 'resolved_at' => $base->addHours(4)]);
        $this->alert('2026-10-20 00:00:00', ['status' => 'resolved', 'acknowledged_at' => $base->addHours(2), 'resolved_at' => $base->addHours(6)]);
        $this->alert('2026-10-20 00:00:00', ['status' => 'acknowledged', 'acknowledged_at' => $base->addHours(10)]);
        $this->alert('2026-10-20 00:00:00');
        // Mức độ Thấp — bị loại khi lọc mức Cao
        $this->alert('2026-10-20 00:00:00', ['severity' => 'low', 'status' => 'resolved', 'acknowledged_at' => $base->addHours(50), 'resolved_at' => $base->addHours(90)]);
        // Ngoài kỳ
        $this->alert('2026-09-01 00:00:00', ['status' => 'resolved', 'acknowledged_at' => now(), 'resolved_at' => now()]);

        $kpis = Livewire::test(SecurityReport::class)->set('severity', 'high')->viewData('kpis');

        $this->assertSame(4, $kpis['alerts']);
        $this->assertSame(2, $kpis['alerts_unclosed']);
        $this->assertEqualsWithDelta(2.0, $kpis['mtta_hours'], 0.001);
        $this->assertSame(3, $kpis['mtta_count']);
        $this->assertEqualsWithDelta(5.0, $kpis['mttr_hours'], 0.001);

        // Bỏ lọc: ghi nhận 1, 2, 10, 50 -> trung vị (2 + 10) / 2 = 6
        $all = Livewire::test(SecurityReport::class)->viewData('kpis');
        $this->assertSame(5, $all['alerts']);
        $this->assertEqualsWithDelta(6.0, $all['mtta_hours'], 0.001);
    }

    public function test_trung_vi(): void
    {
        $this->assertNull(SecurityReportService::median([]));
        $this->assertSame(3.0, SecurityReportService::median([5, 1, 3]));
        $this->assertSame(2.5, SecurityReportService::median([4, 1, 2, 3]));
    }

    public function test_canh_bao_theo_loai_gop_nghiem_trong_vao_cao(): void
    {
        $this->alert('2026-10-20 01:00:00', ['severity' => 'critical']);
        $this->alert('2026-10-20 01:00:00', ['severity' => 'high']);
        $this->alert('2026-10-20 01:00:00', ['severity' => 'low']);
        foreach (range(1, 4) as $i) {
            $this->alert('2026-10-20 01:00:00', ['type' => SecurityAlert::TYPE_MULTI_DEVICE, 'severity' => 'medium']);
        }
        $this->alert('2026-10-20 01:00:00', ['type' => SecurityAlert::TYPE_MULTI_DEVICE, 'severity' => 'high']);

        $component = Livewire::test(SecurityReport::class);
        $byType = $component->viewData('byType')->keyBy('type');

        $this->assertSame(['high' => 2, 'medium' => 0, 'low' => 1, 'total' => 3],
            collect($byType[SecurityAlert::TYPE_BRUTE_FORCE])->only(['high', 'medium', 'low', 'total'])->all());
        $this->assertSame(5, $byType[SecurityAlert::TYPE_MULTI_DEVICE]['total']);
        // Dò mật khẩu ít hơn nhưng tỷ lệ Cao 2/3 lớn nhất
        $this->assertStringContainsString('Dò mật khẩu ít về số lượng nhưng có tỷ lệ mức Cao lớn nhất: 2 trong 3', $component->viewData('typeInsight'));

        // Lọc theo loại
        $filtered = Livewire::test(SecurityReport::class)->set('type', SecurityAlert::TYPE_MULTI_DEVICE);
        $this->assertSame(5, $filtered->viewData('kpis')['alerts']);
        $this->assertSame(5, $filtered->viewData('rows')->total());
    }

    // ---- Bản đồ nhiệt -------------------------------------------------------

    public function test_ban_do_nhiet_theo_gio_viet_nam_va_danh_dau_ngoai_gio(): void
    {
        // 19/10 (Thứ 2) 23:30 UTC = Thứ 3 20/10 06:30 giờ VN
        $this->login('2026-10-19 23:30:00');
        // Chủ nhật 18/10 23:10 VN = 18/10 16:10 UTC — 11 lượt ngoài giờ -> viền đỏ
        foreach (range(1, 11) as $i) {
            $this->login('2026-10-18 16:10:00', 'failed');
        }
        // Thứ 2 19/10 10:00 VN — 11 lượt trong giờ -> không đánh dấu
        foreach (range(1, 11) as $i) {
            $this->login('2026-10-19 03:00:00');
        }

        $component = Livewire::test(SecurityReport::class);
        $matrix = $component->viewData('heatmap');

        $this->assertSame(1, $matrix[1][6]);
        $this->assertSame(0, $matrix[0][23]);
        $this->assertSame(11, $matrix[6][23]);
        $this->assertSame(11, $matrix[0][10]);
        $this->assertSame([[6, 23]], $component->viewData('flagged'));

        // Bấm ô Chủ nhật 23h -> tab Lịch sử đăng nhập lọc đúng 11 lượt
        $component->call('selectHeatCell', 6, 23)
            ->assertSet('tab', 'logins')
            ->assertSet('loginWeekday', 7)
            ->assertSet('loginHour', 23)
            ->assertSee('Chủ nhật · 23:00–00:00');
        $this->assertSame(11, $component->viewData('rows')->total());

        $component->call('clearHeatCell');
        $this->assertSame(23, $component->viewData('rows')->total());
    }

    // ---- Kỳ -----------------------------------------------------------------

    public function test_ky_mac_dinh_7_ngay_va_toi_da_92_ngay(): void
    {
        Livewire::test(SecurityReport::class)->assertSet('preset', '7d');
        Livewire::withQueryParams(['ky' => '30d'])->test(SecurityReport::class)->assertSet('preset', '30d');

        // Kỳ tùy chọn 01/01 – 22/10 bị cắt còn 92 ngày: 23/07 – 22/10 -> lượt 01/07 bị loại
        $this->login('2026-07-01 03:00:00');
        $this->login('2026-07-25 03:00:00');

        $component = Livewire::test(SecurityReport::class)
            ->set('from', '2026-01-01')
            ->set('to', '2026-10-22');

        $period = $component->viewData('period');
        $this->assertSame(92, $period->days());
        $this->assertSame('2026-07-23', $period->startLocal->toDateString());
        $this->assertTrue($component->viewData('clamped'));
        $this->assertSame(1, $component->viewData('kpis')['logins']);
        $component->assertSee('đã rút còn 92 ngày');
    }

    // ---- Bảng cảnh báo + che mã NV ------------------------------------------

    public function test_bang_canh_bao_ma_trang_thai_va_che_ma_nhan_vien(): void
    {
        $old = $this->alert('2026-10-21 01:00:00'); // mở hơn 4 giờ
        $fresh = $this->alert('2026-10-22 04:00:00'); // mới 1 giờ

        // Admin có quyền xem nhân sự -> thấy đủ mã
        Livewire::test(SecurityReport::class)
            ->assertSee(sprintf('AL-2026-%04d', $old->id))
            ->assertSee('Mới · quá 4 giờ')
            ->assertSee('NV001234')
            ->assertDontSee('NV00****');

        // Người xem có quyền báo cáo nhưng không có quyền employees.view -> mã bị che
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo('report_security.view');

        $this->actingAs($viewer)->get(route('admin.reports.security'))->assertOk();

        Livewire::actingAs($viewer)->test(SecurityReport::class)
            ->assertSee('NV00****')
            ->assertDontSee('NV001234')
            ->assertSee('Mã nhân viên được che')
            ->assertDontSee('Mở Trung tâm bảo mật');

        $this->assertSame('NV00****', SecurityReportService::maskCode('NV001234'));
        $this->assertFalse(SecurityReportService::isOpenOverdue($fresh));
    }

    public function test_sap_xep_theo_thoi_diem_phat_sinh(): void
    {
        $first = $this->alert('2026-10-17 01:00:00');
        $last = $this->alert('2026-10-21 01:00:00');

        $component = Livewire::test(SecurityReport::class);
        $this->assertSame($last->id, $component->viewData('rows')->first()->id);

        $component->call('sortByTime');
        $this->assertSame($first->id, $component->viewData('rows')->first()->id);
    }

    // ---- Xuất file ----------------------------------------------------------

    public function test_xuat_file_canh_bao_va_lich_su_dang_nhap(): void
    {
        $this->alert('2026-10-21 01:00:00');
        $this->login('2026-10-21 01:00:00');

        Livewire::test(SecurityReport::class)
            ->call('export', 'xlsx')
            ->assertFileDownloaded();

        Livewire::test(SecurityReport::class)
            ->call('setTab', 'logins')
            ->call('export', 'csv')
            ->assertFileDownloaded();
    }

    public function test_dat_lai_xoa_moi_bo_loc(): void
    {
        Livewire::test(SecurityReport::class)
            ->set('severity', 'high')
            ->set('status', 'open')
            ->call('setPreset', '30d')
            ->call('selectHeatCell', 0, 9)
            ->call('resetFilters')
            ->assertSet('severity', null)
            ->assertSet('status', null)
            ->assertSet('loginWeekday', null)
            ->assertSet('loginHour', null)
            ->assertSet('preset', '7d');
    }
}
