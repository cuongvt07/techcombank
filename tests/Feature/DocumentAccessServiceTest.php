<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentAccessRule;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Services\DocumentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kiểm chứng phân quyền tài liệu theo điều kiện ở spec 3.3.1 và log truy cập ở spec 3.3.2. */
class DocumentAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DocumentAccessService::class);
    }

    public function test_mac_dinh_dong_khi_khong_co_rule_nao(): void
    {
        $employee = Employee::factory()->create();
        $document = Document::factory()->create();

        $this->assertFalse($this->service->canView($employee, $document));
    }

    public function test_cho_xem_khi_khop_dieu_kien_phong_ban(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $document = Document::factory()->create();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $department->id,
            'can_view' => true,
        ]);

        $this->assertTrue($this->service->canView($employee, $document));
    }

    public function test_khong_cho_xem_khi_khac_phong_ban(): void
    {
        $allowed = Department::factory()->create();
        $other = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $other->id]);
        $document = Document::factory()->create();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $allowed->id,
            'can_view' => true,
        ]);

        $this->assertFalse($this->service->canView($employee, $document));
    }

    public function test_rule_phong_ban_cha_ap_cho_phong_ban_con(): void
    {
        $parent = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $parent->id]);
        $employee = Employee::factory()->create(['department_id' => $child->id]);
        $document = Document::factory()->create();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $parent->id,
            'include_sub_departments' => true,
            'can_view' => true,
        ]);

        $this->assertTrue($this->service->canView($employee, $document));
    }

    public function test_tat_include_sub_departments_thi_phong_con_khong_duoc_thua_ke(): void
    {
        $parent = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $parent->id]);
        $employee = Employee::factory()->create(['department_id' => $child->id]);
        $document = Document::factory()->create();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $parent->id,
            'include_sub_departments' => false,
            'can_view' => true,
        ]);

        $this->assertFalse($this->service->canView($employee, $document));
    }

    public function test_rule_deny_thang_rule_allow_cung_muc(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $document = Document::factory()->create();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $department->id,
            'can_view' => true,
            'effect' => DocumentAccessRule::EFFECT_ALLOW,
            'priority' => 5,
        ]);
        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $department->id,
            'effect' => DocumentAccessRule::EFFECT_DENY,
            'priority' => 5,
        ]);

        $this->assertFalse($this->service->canView($employee, $document));
    }

    public function test_rule_cua_tai_lieu_de_rule_cua_danh_muc(): void
    {
        $category = DocumentCategory::create(['code' => 'CAT1', 'name' => 'Danh mục 1']);
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $document = Document::factory()->create(['document_category_id' => $category->id]);

        // Danh mục chặn, nhưng rule riêng của tài liệu cho phép => tài liệu thắng
        DocumentAccessRule::create([
            'document_category_id' => $category->id,
            'department_id' => $department->id,
            'effect' => DocumentAccessRule::EFFECT_DENY,
        ]);
        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $department->id,
            'can_view' => true,
            'effect' => DocumentAccessRule::EFFECT_ALLOW,
        ]);

        $this->assertTrue($this->service->canView($employee, $document));
    }

    public function test_dieu_kien_cap_bac_toi_thieu(): void
    {
        $low = JobGrade::factory()->create(['level' => 2]);
        $high = JobGrade::factory()->create(['level' => 8]);
        $document = Document::factory()->create();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'min_grade_level' => 5,
            'can_view' => true,
        ]);

        $junior = Employee::factory()->create(['job_grade_id' => $low->id]);
        $senior = Employee::factory()->create(['job_grade_id' => $high->id]);

        $this->assertFalse($this->service->canView($junior, $document));
        $this->assertTrue($this->service->canView($senior, $document));
    }

    public function test_nhan_vien_nghi_viec_bi_chan_moi_tai_lieu(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->resigned()->create(['department_id' => $department->id]);
        $document = Document::factory()->create();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $department->id,
            'can_view' => true,
        ]);

        $this->assertFalse($this->service->canView($employee, $document));
    }

    public function test_tai_lieu_chua_xuat_ban_khong_hien_thi(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $document = Document::factory()->draft()->create();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $department->id,
            'can_view' => true,
        ]);

        $this->assertFalse($this->service->canView($employee, $document));
    }

    public function test_co_allow_download_cua_tai_lieu_la_tran_cung(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        // Tài liệu cấm tải; rule cho phép tải cũng không được vượt
        $document = Document::factory()->create(['allow_download' => false]);

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $department->id,
            'can_view' => true,
            'can_download' => true,
        ]);

        $this->assertTrue($this->service->canView($employee, $document));
        $this->assertFalse($this->service->canDownload($employee, $document));
    }

    public function test_query_danh_muc_chi_tra_ve_tai_lieu_duoc_cap_quyen(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);

        $visible = Document::factory()->create();
        $hidden = Document::factory()->create();

        DocumentAccessRule::create([
            'document_id' => $visible->id,
            'department_id' => $department->id,
            'can_view' => true,
        ]);

        $ids = $this->service->visibleDocumentsQuery($employee)->pluck('id')->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_ghi_log_truy_cap_kem_ma_watermark(): void
    {
        $employee = Employee::factory()->create();
        $document = Document::factory()->create();

        $log = $this->service->logAccess($employee, $document, DocumentAccessLog::ACTION_VIEW);

        $this->assertDatabaseHas('document_access_logs', [
            'id' => $log->id,
            'document_id' => $document->id,
            'employee_id' => $employee->id,
            'action' => DocumentAccessLog::ACTION_VIEW,
        ]);
        $this->assertNotEmpty($log->watermark_token);
    }

    public function test_noi_dung_watermark_chua_danh_tinh_nguoi_xem(): void
    {
        $employee = Employee::factory()->create([
            'full_name' => 'Nguyễn Văn A',
            'email' => 'a.nguyen@example.com',
        ]);

        $text = $this->service->watermarkText($employee);

        $this->assertStringContainsString('Nguyễn Văn A', $text);
        $this->assertStringContainsString('a.nguyen@example.com', $text);
    }
}
