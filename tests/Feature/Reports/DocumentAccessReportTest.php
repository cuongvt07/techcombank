<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleName;
use App\Livewire\Admin\Reports\DocumentAccessReport;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentAccessRule;
use App\Models\Employee;
use App\Models\User;
use App\Services\Reports\DocumentAccessReportService;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Số liệu báo cáo truy cập tài liệu (SCR_007) trên dữ liệu dựng tay. */
class DocumentAccessReportTest extends TestCase
{
    use RefreshDatabase;

    private DocumentAccessReportService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $this->admin->id]);
        $this->actingAs($this->admin->refresh());

        $this->service = app(DocumentAccessReportService::class);
    }

    private function log(Document $doc, ?Employee $emp, string $action, $at): DocumentAccessLog
    {
        return DocumentAccessLog::create([
            'document_id' => $doc->id,
            'employee_id' => $emp?->id,
            'action' => $action,
            'ip_address' => '10.0.0.1',
            'watermark_token' => 'wm-' . uniqid(),
            'accessed_at' => $at,
        ]);
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::make('30d');
    }

    public function test_gop_luot_xem_lap_trong_5_phut(): void
    {
        $doc = Document::factory()->create();
        $a = Employee::factory()->create();
        $b = Employee::factory()->create();
        $base = CarbonImmutable::now()->subDays(2)->startOfHour();

        // Người A: 0', 3', 7' (chuỗi cách nhau < 5 phút) => 1 lượt; 20' => lượt mới
        foreach ([0, 3, 7, 20] as $m) {
            $this->log($doc, $a, 'view', $base->addMinutes($m));
        }
        // Người B cùng thời điểm: lượt riêng, không gộp với A
        $this->log($doc, $b, 'view', $base->addMinutes(1));
        // Cách đúng 5 phút => lượt mới
        $this->log($doc, $b, 'view', $base->addMinutes(6));

        $kpis = $this->service->kpis($this->period(), []);

        $this->assertSame(4, $kpis['views']);
        $this->assertSame(2, $kpis['viewers']);
    }

    public function test_kpi_tai_chan_va_nguoi_bi_chan_nhieu_trong_gio(): void
    {
        $doc = Document::factory()->create();
        $a = Employee::factory()->create();
        $b = Employee::factory()->create();
        $hour = CarbonImmutable::now()->subDays(3)->startOfHour();

        $this->log($doc, $a, 'download', $hour);
        $this->log($doc, $b, 'download', $hour);
        foreach (range(0, 4) as $i) {
            $this->log($doc, $a, 'denied', $hour->addMinutes($i * 10));
        }
        // B bị chặn 4 lần trong giờ: dưới ngưỡng
        foreach (range(0, 3) as $i) {
            $this->log($doc, $b, 'denied', $hour->addMinutes($i));
        }
        // Ngoài kỳ: không tính
        $this->log($doc, $a, 'download', now()->subDays(60));

        $kpis = $this->service->kpis($this->period(), []);

        $this->assertSame(2, $kpis['downloads']);
        $this->assertSame(9, $kpis['denied']);
        $this->assertSame(1, $kpis['burst_people']);

        // Lọc hành động "Tải": chỉ số của hành động khác không áp dụng
        $filtered = $this->service->kpis($this->period(), ['action' => 'download']);
        $this->assertSame(2, $filtered['downloads']);
        $this->assertNull($filtered['views']);
        $this->assertNull($filtered['denied']);
    }

    public function test_da_xuat_ban_chua_ai_mo_va_qua_han_ra_soat(): void
    {
        $opened = Document::factory()->create();
        $unopened = Document::factory()->create();
        $onlyDenied = Document::factory()->create();
        Document::factory()->draft()->create();
        $old = Document::factory()->create(['published_at' => now()->subMonths(20)]);
        $emp = Employee::factory()->create();

        $this->log($opened, $emp, 'view', now()->subDay());
        $this->log($old, $emp, 'stream', now()->subDay());
        $this->log($onlyDenied, $emp, 'denied', now()->subDay());

        $kpis = $this->service->kpis($this->period(), []);

        $this->assertSame(2, $kpis['unopened']);
        $this->assertEqualsCanonicalizing(
            [$unopened->id, $onlyDenied->id],
            $this->service->unopenedDocumentsQuery($this->period(), [])->pluck('id')->all()
        );
        $this->assertSame(1, $kpis['stale']);

        // Có phiên bản mới gần đây => hết quá hạn
        $old->newVersion(['original_filename' => 'v2.pdf']);
        $this->assertSame(0, $this->service->kpis($this->period(), [])['stale']);
    }

    public function test_ty_le_tiep_can_theo_quy_tac_phan_quyen(): void
    {
        $doc = Document::factory()->create();
        DocumentAccessRule::create(['document_id' => $doc->id, 'can_view' => true, 'effect' => 'allow', 'is_active' => true]);
        $noRule = Document::factory()->create();

        // 1 NV có sẵn trong setUp + 4 NV đang làm + 1 NV đã nghỉ (không tính)
        $emps = Employee::factory()->count(4)->create();
        Employee::factory()->resigned()->create();

        $this->log($doc, $emps[0], 'view', now()->subDay());
        $this->log($doc, $emps[1], 'view', now()->subDay());

        $rows = $this->service->withReach($this->service->documentRows($this->period(), []))->keyBy('id');

        $this->assertSame(5, $rows[$doc->id]['granted']);
        $this->assertSame(2, $rows[$doc->id]['viewers']);
        $this->assertSame(40.0, $rows[$doc->id]['reach']);
        $this->assertSame(0, $rows[$noRule->id]['granted']);
        $this->assertNull($rows[$noRule->id]['reach']);

        // Số được cấp quyền cache theo ngày
        $this->assertSame(5, Cache::get($this->service->grantedCacheKey($doc->id)));

        Livewire::test(DocumentAccessReport::class)
            ->assertSee('40,0%')
            ->assertSee('Số người được cấp quyền tính lại mỗi ngày');
    }

    public function test_bo_loc_do_mat(): void
    {
        $restricted = Document::factory()->restricted()->create();
        Document::factory()->create();
        $emp = Employee::factory()->create();
        $this->log($restricted, $emp, 'view', now()->subDay());

        $rows = $this->service->documentRows($this->period(), ['confidentiality' => 'restricted']);
        $this->assertSame([$restricted->id], $rows->pluck('id')->all());
        $this->assertSame(1, $this->service->kpis($this->period(), ['confidentiality' => 'internal'])['unopened']);
    }

    public function test_tab_nhat_ky_can_quyen_security_view(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('report_documents.view');
        $this->actingAs($user->refresh());

        $doc = Document::factory()->create();
        $this->log($doc, Employee::factory()->create(), 'view', now()->subDay())->update(['watermark_token' => 'wm-bi-mat']);

        Livewire::test(DocumentAccessReport::class)
            ->call('setTab', 'log')
            ->assertForbidden();

        // Mở thẳng bằng link ?tab=log cũng không lộ nhật ký
        Livewire::withQueryParams(['tab' => 'log'])->test(DocumentAccessReport::class)
            ->assertSee('Theo tài liệu')
            ->assertDontSee('wm-bi-mat');

        // Gửi thẳng request đặt tab=log rồi xuất: vẫn bị chặn ở server
        Livewire::test(DocumentAccessReport::class)
            ->set('tab', 'log')
            ->call('export', 'csv')
            ->assertForbidden();

        $this->get(route('admin.reports.documents'))->assertOk()->assertSee('Nhật ký cần quyền security.view');
    }

    public function test_admin_xem_nhat_ky_ky_toi_da_92_ngay(): void
    {
        $doc = Document::factory()->create();
        $emp = Employee::factory()->create();
        $this->log($doc, $emp, 'view', now()->subDay())->update(['watermark_token' => 'wm-gan-day']);
        $this->log($doc, $emp, 'view', now()->subDays(120))->update(['watermark_token' => 'wm-qua-cu']);

        $from = now('Asia/Ho_Chi_Minh')->subDays(200)->toDateString();
        $to = now('Asia/Ho_Chi_Minh')->toDateString();

        Livewire::withQueryParams(['ky' => 'custom', 'tu' => $from, 'den' => $to])
            ->test(DocumentAccessReport::class)
            ->call('setTab', 'log')
            ->assertSee('wm-gan-day')
            ->assertDontSee('wm-qua-cu')
            ->assertSee('kỳ đã cắt còn 92 ngày');
    }

    public function test_xuat_file(): void
    {
        $doc = Document::factory()->create(['title' => 'Quy chế xuất thử']);
        $this->log($doc, Employee::factory()->create(), 'view', now()->subDay());

        Livewire::test(DocumentAccessReport::class)
            ->call('export', 'xlsx')
            ->assertFileDownloaded();

        Livewire::test(DocumentAccessReport::class)
            ->call('setTab', 'log')
            ->call('export', 'csv')
            ->assertFileDownloaded();
    }

    public function test_trang_hien_thi_va_trang_thai_trong(): void
    {
        $this->get(route('admin.reports.documents'))
            ->assertOk()
            ->assertSee('Báo cáo truy cập và sử dụng tài liệu')
            ->assertSee('Không có lượt truy cập nào trong kỳ khớp bộ lọc.')
            ->assertSee('Không có tài liệu đã xuất bản nào khớp bộ lọc.');
    }
}
