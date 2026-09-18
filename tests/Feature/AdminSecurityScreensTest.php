<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\DocumentAccessManager;
use App\Livewire\Admin\SecurityCenter;
use App\Models\Department;
use App\Models\DeviceSession;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentAccessRule;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\SecurityAlert;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Kiểm chứng màn hình phân quyền tài liệu (spec 3.3.1) và trung tâm bảo mật (spec 3.3.2). */
class AdminSecurityScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $this->admin->id]);
        $this->actingAs($this->admin->refresh());
    }

    // ---- 3.3.1 Phân quyền tài liệu ------------------------------------------

    public function test_tao_quy_tac_cho_phep_theo_phong_ban(): void
    {
        $document = Document::factory()->create();
        $department = Department::factory()->create();

        Livewire::test(DocumentAccessManager::class)
            ->call('create')
            ->set('scope', 'document')
            ->set('documentId', $document->id)
            ->set('departmentId', $department->id)
            ->set('canView', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('document_access_rules', [
            'document_id' => $document->id,
            'department_id' => $department->id,
            'can_view' => true,
            'effect' => DocumentAccessRule::EFFECT_ALLOW,
        ]);
    }

    public function test_tao_quy_tac_cho_ca_danh_muc(): void
    {
        $category = DocumentCategory::create(['code' => 'CAT-X', 'name' => 'Danh mục X']);

        Livewire::test(DocumentAccessManager::class)
            ->call('create')
            ->set('scope', 'category')
            ->set('categoryId', $category->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('document_access_rules', [
            'document_category_id' => $category->id,
            'document_id' => null,
        ]);
    }

    public function test_bat_buoc_chon_tai_lieu_khi_pham_vi_la_tai_lieu(): void
    {
        Livewire::test(DocumentAccessManager::class)
            ->call('create')
            ->set('scope', 'document')
            ->set('documentId', null)
            ->call('save')
            ->assertHasErrors('documentId');
    }

    public function test_quy_tac_chan_khong_cap_quyen_gi(): void
    {
        $document = Document::factory()->create();

        Livewire::test(DocumentAccessManager::class)
            ->call('create')
            ->set('scope', 'document')
            ->set('documentId', $document->id)
            ->set('effect', DocumentAccessRule::EFFECT_DENY)
            // Cố tình bật quyền: rule chặn phải ghi đè về false
            ->set('canView', true)
            ->set('canDownload', true)
            ->call('save')
            ->assertHasNoErrors();

        $rule = DocumentAccessRule::where('document_id', $document->id)->first();

        $this->assertSame(DocumentAccessRule::EFFECT_DENY, $rule->effect);
        $this->assertFalse($rule->can_view);
        $this->assertFalse($rule->can_download);
    }

    public function test_thu_quyen_cho_ket_qua_dung_khi_duoc_phep(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $document = Document::factory()->create();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $department->id,
            'can_view' => true,
        ]);

        $component = Livewire::test(DocumentAccessManager::class)
            ->call('openTester')
            ->set('testEmployeeId', $employee->id)
            ->set('testDocumentId', $document->id)
            ->call('runTest')
            ->assertHasNoErrors();

        $result = $component->get('testResult');

        $this->assertTrue($result['can_view']);
        $this->assertSame($employee->full_name, $result['employee']);
    }

    public function test_thu_quyen_cho_ket_qua_dung_khi_bi_chan(): void
    {
        $employee = Employee::factory()->create();
        $document = Document::factory()->create();

        // Không có rule nào => mặc định đóng
        $component = Livewire::test(DocumentAccessManager::class)
            ->call('openTester')
            ->set('testEmployeeId', $employee->id)
            ->set('testDocumentId', $document->id)
            ->call('runTest');

        $result = $component->get('testResult');

        $this->assertFalse($result['can_view']);
        $this->assertNotEmpty($result['reason']);
    }

    public function test_thu_quyen_bao_khi_co_tai_lieu_chan_tai_xuong(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        // Tài liệu tắt cờ cho phép tải
        $document = Document::factory()->create(['allow_download' => false]);

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $department->id,
            'can_view' => true,
            'can_download' => true,
        ]);

        $component = Livewire::test(DocumentAccessManager::class)
            ->call('openTester')
            ->set('testEmployeeId', $employee->id)
            ->set('testDocumentId', $document->id)
            ->call('runTest');

        $result = $component->get('testResult');

        $this->assertTrue($result['can_view']);
        $this->assertFalse($result['can_download'], 'Cờ của tài liệu là trần cứng');
        $this->assertTrue($result['download_capped'], 'Phải báo cho admin biết vì sao bị chặn');
    }

    public function test_thu_quyen_voi_dieu_kien_cap_bac_toi_thieu(): void
    {
        $lowGrade = JobGrade::factory()->create(['level' => 2]);
        $highGrade = JobGrade::factory()->create(['level' => 8]);
        $document = Document::factory()->create();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'min_grade_level' => 5,
            'can_view' => true,
        ]);

        $junior = Employee::factory()->create(['job_grade_id' => $lowGrade->id]);
        $senior = Employee::factory()->create(['job_grade_id' => $highGrade->id]);

        $juniorResult = Livewire::test(DocumentAccessManager::class)
            ->call('openTester')
            ->set('testEmployeeId', $junior->id)
            ->set('testDocumentId', $document->id)
            ->call('runTest')
            ->get('testResult');

        $seniorResult = Livewire::test(DocumentAccessManager::class)
            ->call('openTester')
            ->set('testEmployeeId', $senior->id)
            ->set('testDocumentId', $document->id)
            ->call('runTest')
            ->get('testResult');

        $this->assertFalse($juniorResult['can_view']);
        $this->assertTrue($seniorResult['can_view']);
    }

    public function test_tat_quy_tac_thi_khong_con_hieu_luc(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $document = Document::factory()->create();

        $rule = DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $department->id,
            'can_view' => true,
            'is_active' => true,
        ]);

        Livewire::test(DocumentAccessManager::class)->call('toggleActive', $rule->id);

        $result = Livewire::test(DocumentAccessManager::class)
            ->call('openTester')
            ->set('testEmployeeId', $employee->id)
            ->set('testDocumentId', $document->id)
            ->call('runTest')
            ->get('testResult');

        $this->assertFalse($rule->refresh()->is_active);
        $this->assertFalse($result['can_view']);
    }

    // ---- 3.3.2 Trung tâm bảo mật --------------------------------------------

    public function test_hien_thi_canh_bao_chua_xu_ly(): void
    {
        SecurityAlert::create([
            'user_id' => $this->admin->id,
            'type' => SecurityAlert::TYPE_MASS_DOWNLOAD,
            'severity' => 'high',
            'title' => 'Cảnh báo thử nghiệm',
            'status' => SecurityAlert::STATUS_OPEN,
        ]);

        Livewire::test(SecurityCenter::class)
            ->assertSee('Cảnh báo thử nghiệm')
            ->assertSee('Chưa xử lý');
    }

    public function test_ghi_nhan_canh_bao(): void
    {
        $alert = SecurityAlert::create([
            'user_id' => $this->admin->id,
            'type' => SecurityAlert::TYPE_UNUSUAL_IP,
            'severity' => 'medium',
            'title' => 'IP lạ',
            'status' => SecurityAlert::STATUS_OPEN,
        ]);

        Livewire::test(SecurityCenter::class)->call('acknowledge', $alert->id);

        $alert->refresh();
        $this->assertSame(SecurityAlert::STATUS_ACKNOWLEDGED, $alert->status);
        $this->assertSame($this->admin->id, $alert->handled_by);
    }

    public function test_dong_canh_bao_ghi_nguoi_xu_ly(): void
    {
        $alert = SecurityAlert::create([
            'user_id' => $this->admin->id,
            'type' => SecurityAlert::TYPE_MULTI_DEVICE,
            'severity' => 'low',
            'title' => 'Nhiều thiết bị',
            'status' => SecurityAlert::STATUS_OPEN,
        ]);

        Livewire::test(SecurityCenter::class)->call('resolve', $alert->id);

        $alert->refresh();
        $this->assertSame(SecurityAlert::STATUS_RESOLVED, $alert->status);
        $this->assertNotNull($alert->handled_at);
    }

    public function test_danh_dau_canh_bao_nham(): void
    {
        $alert = SecurityAlert::create([
            'user_id' => $this->admin->id,
            'type' => SecurityAlert::TYPE_UNUSUAL_IP,
            'severity' => 'medium',
            'title' => 'IP lạ',
            'status' => SecurityAlert::STATUS_OPEN,
        ]);

        Livewire::test(SecurityCenter::class)->call('markFalsePositive', $alert->id);

        $this->assertSame(SecurityAlert::STATUS_FALSE_POSITIVE, $alert->refresh()->status);
    }

    public function test_tab_nhat_ky_tai_lieu_hien_luot_truy_cap(): void
    {
        $employee = Employee::factory()->create(['full_name' => 'Người Xem Tài Liệu']);
        $document = Document::factory()->create(['title' => 'Tài liệu bị theo dõi']);

        DocumentAccessLog::create([
            'document_id' => $document->id,
            'user_id' => $this->admin->id,
            'employee_id' => $employee->id,
            'action' => DocumentAccessLog::ACTION_VIEW,
            'ip_address' => '10.1.2.3',
            'watermark_token' => 'abc123def456',
            'accessed_at' => now(),
        ]);

        Livewire::test(SecurityCenter::class)
            ->call('setTab', 'document-logs')
            ->assertSee('Người Xem Tài Liệu')
            ->assertSee('Tài liệu bị theo dõi')
            ->assertSee('10.1.2.3');
    }

    public function test_loc_nhat_ky_theo_hanh_dong(): void
    {
        $employee = Employee::factory()->create();
        $viewed = Document::factory()->create(['title' => 'Tài Liệu Được Xem']);
        $denied = Document::factory()->create(['title' => 'Tài Liệu Bị Từ Chối']);

        DocumentAccessLog::create([
            'document_id' => $viewed->id, 'employee_id' => $employee->id,
            'action' => DocumentAccessLog::ACTION_VIEW, 'accessed_at' => now(),
        ]);
        DocumentAccessLog::create([
            'document_id' => $denied->id, 'employee_id' => $employee->id,
            'action' => DocumentAccessLog::ACTION_DENIED, 'accessed_at' => now(),
        ]);

        Livewire::test(SecurityCenter::class)
            ->call('setTab', 'document-logs')
            ->set('actionFilter', DocumentAccessLog::ACTION_DENIED)
            ->assertSee('Tài Liệu Bị Từ Chối')
            ->assertDontSee('Tài Liệu Được Xem');
    }

    public function test_log_cu_hon_khoang_thoi_gian_khong_hien(): void
    {
        $employee = Employee::factory()->create();
        $old = Document::factory()->create(['title' => 'Tài Liệu Cũ Lâu Rồi']);

        DocumentAccessLog::create([
            'document_id' => $old->id, 'employee_id' => $employee->id,
            'action' => DocumentAccessLog::ACTION_VIEW,
            'accessed_at' => now()->subDays(60),
        ]);

        Livewire::test(SecurityCenter::class)
            ->call('setTab', 'document-logs')
            ->set('days', 7)
            ->assertDontSee('Tài Liệu Cũ Lâu Rồi');
    }

    public function test_thu_hoi_mot_phien_thiet_bi(): void
    {
        $session = DeviceSession::create([
            'user_id' => $this->admin->id,
            'session_id' => 'sess-1',
            'is_active' => true,
            'last_activity_at' => now(),
        ]);

        Livewire::test(SecurityCenter::class)
            ->call('setTab', 'devices')
            ->call('revokeSession', $session->id);

        $session->refresh();
        $this->assertFalse($session->is_active);
        $this->assertNotNull($session->revoked_at);
    }

    public function test_thu_hoi_toan_bo_phien_cua_mot_tai_khoan(): void
    {
        $target = User::factory()->create();

        foreach (['s1', 's2', 's3'] as $sid) {
            DeviceSession::create([
                'user_id' => $target->id, 'session_id' => $sid,
                'is_active' => true, 'last_activity_at' => now(),
            ]);
        }

        Livewire::test(SecurityCenter::class)
            ->call('setTab', 'devices')
            ->call('revokeAllForUser', $target->id);

        $this->assertSame(0, DeviceSession::where('user_id', $target->id)->where('is_active', true)->count());
    }

    public function test_quet_thu_cong_sinh_canh_bao(): void
    {
        \App\Models\Setting::set('security.mass_download_threshold', '3', 'integer');
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            DocumentAccessLog::create([
                'document_id' => Document::factory()->create()->id,
                'user_id' => $user->id,
                'action' => DocumentAccessLog::ACTION_VIEW,
                'accessed_at' => now(),
            ]);
        }

        Livewire::test(SecurityCenter::class)->call('runScan');

        $this->assertDatabaseHas('security_alerts', [
            'user_id' => $user->id,
            'type' => SecurityAlert::TYPE_MASS_DOWNLOAD,
        ]);
    }

    // ---- Phân quyền màn hình ------------------------------------------------

    public function test_nhan_vien_khong_vao_duoc_trung_tam_bao_mat(): void
    {
        $employee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $employee->assignRole(RoleName::EMPLOYEE->value);
        Employee::factory()->create(['user_id' => $employee->id]);

        $this->actingAs($employee->refresh())
            ->get(route('admin.security'))
            ->assertRedirect(route('learn.events'));
    }

    public function test_quan_tri_vien_vao_duoc_man_hinh_phan_quyen_tai_lieu(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin->refresh())
            ->get(route('admin.access-rules'))
            ->assertOk();
    }
}
