<?php

namespace Tests\Feature;

use App\Models\DeviceSession;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Employee;
use App\Models\LoginHistory;
use App\Models\SecurityAlert;
use App\Models\Setting;
use App\Models\User;
use App\Services\SecurityMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kiểm chứng giám sát truy cập bất thường và giới hạn thiết bị (spec 3.3.2). */
class SecurityMonitorServiceTest extends TestCase
{
    use RefreshDatabase;

    private SecurityMonitorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SecurityMonitorService::class);
    }

    // ---- Giới hạn thiết bị --------------------------------------------------

    public function test_dang_ky_thiet_bi_moi(): void
    {
        $user = User::factory()->create();

        $session = $this->service->registerDevice($user, 'session-1');

        $this->assertTrue($session->is_active);
        $this->assertNotNull($session->device_label);
        $this->assertSame(1, DeviceSession::where('user_id', $user->id)->where('is_active', true)->count());
    }

    public function test_vuot_gioi_han_thi_thu_hoi_phien_cu_nhat(): void
    {
        Setting::set('security.max_concurrent_devices', '2', 'integer');
        $user = User::factory()->create();

        // Hai phiên cũ, phiên đầu ít hoạt động nhất
        DeviceSession::create([
            'user_id' => $user->id, 'session_id' => 'old-1', 'is_active' => true,
            'last_activity_at' => now()->subHours(3),
        ]);
        DeviceSession::create([
            'user_id' => $user->id, 'session_id' => 'old-2', 'is_active' => true,
            'last_activity_at' => now()->subHour(),
        ]);

        $this->service->registerDevice($user, 'new-session');

        // Giới hạn 2: phiên mới + 1 phiên gần nhất được giữ, phiên cũ nhất bị thu hồi
        $this->assertSame(2, DeviceSession::where('user_id', $user->id)->where('is_active', true)->count());
        $this->assertFalse(DeviceSession::where('session_id', 'old-1')->value('is_active'));
        $this->assertTrue((bool) DeviceSession::where('session_id', 'old-2')->value('is_active'));
        $this->assertTrue((bool) DeviceSession::where('session_id', 'new-session')->value('is_active'));
    }

    public function test_thu_hoi_phien_sinh_canh_bao(): void
    {
        Setting::set('security.max_concurrent_devices', '1', 'integer');
        $user = User::factory()->create();

        DeviceSession::create([
            'user_id' => $user->id, 'session_id' => 'old', 'is_active' => true,
            'last_activity_at' => now()->subHour(),
        ]);

        $this->service->registerDevice($user, 'new');

        $this->assertDatabaseHas('security_alerts', [
            'user_id' => $user->id,
            'type' => SecurityAlert::TYPE_MULTI_DEVICE,
            'status' => SecurityAlert::STATUS_OPEN,
        ]);
    }

    public function test_dang_nhap_lai_cung_thiet_bi_khong_tinh_la_phien_moi(): void
    {
        Setting::set('security.max_concurrent_devices', '2', 'integer');
        $user = User::factory()->create();

        $this->service->registerDevice($user, 'same-session');
        $this->service->registerDevice($user, 'same-session');

        $this->assertSame(1, DeviceSession::where('user_id', $user->id)->count());
        $this->assertSame(0, SecurityAlert::where('user_id', $user->id)->count());
    }

    public function test_trong_gioi_han_thi_khong_thu_hoi_gi(): void
    {
        Setting::set('security.max_concurrent_devices', '3', 'integer');
        $user = User::factory()->create();

        $this->service->registerDevice($user, 's1');
        $this->service->registerDevice($user, 's2');
        $this->service->registerDevice($user, 's3');

        $this->assertSame(3, DeviceSession::where('user_id', $user->id)->where('is_active', true)->count());
        $this->assertSame(0, SecurityAlert::where('type', SecurityAlert::TYPE_MULTI_DEVICE)->count());
    }

    public function test_thu_hoi_toan_bo_phien(): void
    {
        $user = User::factory()->create();
        $this->service->registerDevice($user, 's1');
        $this->service->registerDevice($user, 's2');

        $revoked = $this->service->revokeAllSessions($user);

        $this->assertGreaterThan(0, $revoked);
        $this->assertSame(0, DeviceSession::where('user_id', $user->id)->where('is_active', true)->count());
    }

    // ---- Phát hiện tải hàng loạt --------------------------------------------

    public function test_phat_hien_truy_cap_nhieu_tai_lieu(): void
    {
        Setting::set('security.mass_download_threshold', '5', 'integer');
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        foreach (range(1, 6) as $i) {
            $document = Document::factory()->create();
            DocumentAccessLog::create([
                'document_id' => $document->id,
                'user_id' => $user->id,
                'employee_id' => $employee->id,
                'action' => DocumentAccessLog::ACTION_VIEW,
                'accessed_at' => now()->subMinutes(5),
            ]);
        }

        $created = $this->service->detectMassDownload();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('security_alerts', [
            'user_id' => $user->id,
            'type' => SecurityAlert::TYPE_MASS_DOWNLOAD,
            'severity' => 'high',
        ]);
    }

    public function test_duoi_nguong_thi_khong_canh_bao(): void
    {
        Setting::set('security.mass_download_threshold', '10', 'integer');
        $user = User::factory()->create();

        foreach (range(1, 3) as $i) {
            DocumentAccessLog::create([
                'document_id' => Document::factory()->create()->id,
                'user_id' => $user->id,
                'action' => DocumentAccessLog::ACTION_VIEW,
                'accessed_at' => now(),
            ]);
        }

        $this->assertSame(0, $this->service->detectMassDownload());
    }

    public function test_xem_lai_cung_mot_tai_lieu_khong_bi_tinh_la_hang_loat(): void
    {
        Setting::set('security.mass_download_threshold', '3', 'integer');
        $user = User::factory()->create();
        $document = Document::factory()->create();

        // Mở đi mở lại một tài liệu là hành vi bình thường
        foreach (range(1, 10) as $i) {
            DocumentAccessLog::create([
                'document_id' => $document->id,
                'user_id' => $user->id,
                'action' => DocumentAccessLog::ACTION_VIEW,
                'accessed_at' => now(),
            ]);
        }

        $this->assertSame(0, $this->service->detectMassDownload());
    }

    public function test_khong_tao_canh_bao_trung_trong_cung_cua_so(): void
    {
        Setting::set('security.mass_download_threshold', '3', 'integer');
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            DocumentAccessLog::create([
                'document_id' => Document::factory()->create()->id,
                'user_id' => $user->id,
                'action' => DocumentAccessLog::ACTION_VIEW,
                'accessed_at' => now(),
            ]);
        }

        $this->service->detectMassDownload();
        $second = $this->service->detectMassDownload();

        $this->assertSame(0, $second, 'Chạy lại job không được sinh cảnh báo trùng');
        $this->assertSame(1, SecurityAlert::where('type', SecurityAlert::TYPE_MASS_DOWNLOAD)->count());
    }

    public function test_log_ngoai_cua_so_thoi_gian_khong_bi_tinh(): void
    {
        Setting::set('security.mass_download_threshold', '3', 'integer');
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            DocumentAccessLog::create([
                'document_id' => Document::factory()->create()->id,
                'user_id' => $user->id,
                'action' => DocumentAccessLog::ACTION_VIEW,
                'accessed_at' => now()->subDay(),
            ]);
        }

        $this->assertSame(0, $this->service->detectMassDownload(60));
    }

    // ---- Phát hiện dò mật khẩu ----------------------------------------------

    public function test_phat_hien_dang_nhap_that_bai_lien_tiep(): void
    {
        Setting::set('security.failed_login_threshold', '3', 'integer');
        $user = User::factory()->create();

        foreach (range(1, 4) as $i) {
            LoginHistory::create([
                'user_id' => $user->id,
                'result' => 'failed',
                'logged_in_at' => now()->subMinutes(2),
            ]);
        }

        $created = $this->service->detectBruteForce();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('security_alerts', [
            'user_id' => $user->id,
            'type' => SecurityAlert::TYPE_BRUTE_FORCE,
            'severity' => 'critical',
        ]);
    }

    public function test_dang_nhap_thanh_cong_khong_tinh_vao_brute_force(): void
    {
        Setting::set('security.failed_login_threshold', '3', 'integer');
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            LoginHistory::create([
                'user_id' => $user->id,
                'result' => 'success',
                'logged_in_at' => now(),
            ]);
        }

        $this->assertSame(0, $this->service->detectBruteForce());
    }

    // ---- IP lạ --------------------------------------------------------------

    public function test_dang_nhap_dau_tien_khong_bi_coi_la_ip_la(): void
    {
        $user = User::factory()->create();

        $alert = $this->service->detectUnusualIp($user, '203.0.113.5');

        $this->assertNull($alert, 'Lần đăng nhập đầu tiên không có gì để so sánh');
    }

    public function test_ip_moi_sinh_canh_bao_khi_da_co_lich_su(): void
    {
        $user = User::factory()->create();

        LoginHistory::create([
            'user_id' => $user->id,
            'result' => 'success',
            'ip_address' => '10.0.0.1',
            'logged_in_at' => now()->subDays(2),
        ]);

        $alert = $this->service->detectUnusualIp($user, '203.0.113.99');

        $this->assertNotNull($alert);
        $this->assertSame(SecurityAlert::TYPE_UNUSUAL_IP, $alert->type);
    }

    public function test_ip_quen_thuoc_khong_sinh_canh_bao(): void
    {
        $user = User::factory()->create();

        LoginHistory::create([
            'user_id' => $user->id,
            'result' => 'success',
            'ip_address' => '10.0.0.1',
            'logged_in_at' => now()->subDays(2),
        ]);

        $this->assertNull($this->service->detectUnusualIp($user, '10.0.0.1'));
    }

    // ---- Xử lý cảnh báo -----------------------------------------------------

    public function test_ghi_nhan_va_dong_canh_bao(): void
    {
        $user = User::factory()->create();
        $handler = User::factory()->create();

        $alert = SecurityAlert::create([
            'user_id' => $user->id,
            'type' => SecurityAlert::TYPE_UNUSUAL_IP,
            'severity' => 'medium',
            'title' => 'Test',
            'status' => SecurityAlert::STATUS_OPEN,
        ]);

        $this->service->acknowledge($alert, $handler->id);
        $this->assertSame(SecurityAlert::STATUS_ACKNOWLEDGED, $alert->refresh()->status);

        $this->service->resolve($alert, $handler->id);
        $this->assertSame(SecurityAlert::STATUS_RESOLVED, $alert->refresh()->status);
        $this->assertSame($handler->id, $alert->handled_by);
        $this->assertNotNull($alert->handled_at);
    }

    public function test_danh_dau_canh_bao_nham(): void
    {
        $alert = SecurityAlert::create([
            'user_id' => User::factory()->create()->id,
            'type' => SecurityAlert::TYPE_MULTI_DEVICE,
            'severity' => 'low',
            'title' => 'Test',
            'status' => SecurityAlert::STATUS_OPEN,
        ]);

        $this->service->resolve($alert, null, falsePositive: true);

        $this->assertSame(SecurityAlert::STATUS_FALSE_POSITIVE, $alert->refresh()->status);
    }
}
