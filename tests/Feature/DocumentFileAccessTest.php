<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentAccessRule;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\User;
use App\Services\FileStorageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kiểm chứng phát file tài liệu: quyền được kiểm tra lại ở tầng route
 * và mọi lượt truy cập đều để lại dấu vết (spec 3.3.2).
 */
class DocumentFileAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(FileStorageService::DISK);

        $this->department = Department::factory()->create();
        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->user->assignRole(RoleName::EMPLOYEE->value);
        $this->employee = Employee::factory()->create([
            'user_id' => $this->user->id,
            'department_id' => $this->department->id,
        ]);
    }

    // ---- Kiểm soát quyền ----------------------------------------------------

    public function test_khach_chua_dang_nhap_khong_xem_duoc_file(): void
    {
        $document = $this->makeDocumentWithFile();

        $this->get(route('learn.documents.stream', $document))
            ->assertRedirect(route('login'));
    }

    public function test_khong_co_quyen_thi_bi_tu_choi(): void
    {
        $document = $this->makeDocumentWithFile();

        // Không tạo rule nào => mặc định đóng
        $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document))
            ->assertForbidden();
    }

    public function test_luot_bi_tu_choi_duoc_ghi_log(): void
    {
        $document = $this->makeDocumentWithFile();

        $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document));

        // Chuỗi lượt từ chối liên tiếp là dấu hiệu dò tìm tài liệu
        $this->assertDatabaseHas('document_access_logs', [
            'document_id' => $document->id,
            'employee_id' => $this->employee->id,
            'action' => DocumentAccessLog::ACTION_DENIED,
        ]);
    }

    public function test_co_quyen_thi_xem_duoc_file(): void
    {
        $document = $this->makeDocumentWithFile();
        $this->allowView($document);

        $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_luot_xem_thanh_cong_duoc_ghi_log(): void
    {
        $document = $this->makeDocumentWithFile();
        $this->allowView($document);

        $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document));

        $log = DocumentAccessLog::where('document_id', $document->id)
            ->where('action', DocumentAccessLog::ACTION_VIEW)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->employee->id, $log->employee_id);
        $this->assertNotEmpty($log->watermark_token);
    }

    public function test_tai_lieu_nghi_viec_khong_xem_duoc(): void
    {
        $document = $this->makeDocumentWithFile();
        $this->allowView($document);

        $this->employee->update(['employment_status' => Employee::STATUS_RESIGNED]);

        $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document))
            ->assertForbidden();
    }

    public function test_tai_lieu_chua_xuat_ban_khong_xem_duoc(): void
    {
        $document = $this->makeDocumentWithFile(['status' => Document::STATUS_DRAFT]);
        $this->allowView($document);

        $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document))
            ->assertForbidden();
    }

    // ---- Tải xuống ----------------------------------------------------------

    public function test_khong_tai_duoc_khi_tai_lieu_cam_tai(): void
    {
        $document = $this->makeDocumentWithFile(['allow_download' => false]);

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $this->department->id,
            'can_view' => true,
            // Rule cho phép tải nhưng cờ tài liệu là trần cứng
            'can_download' => true,
        ]);

        $this->actingAs($this->user)
            ->get(route('learn.documents.download', $document))
            ->assertForbidden();
    }

    public function test_tai_duoc_khi_ca_hai_deu_cho_phep(): void
    {
        $document = $this->makeDocumentWithFile(['allow_download' => true]);

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $this->department->id,
            'can_view' => true,
            'can_download' => true,
        ]);

        $this->actingAs($this->user)
            ->get(route('learn.documents.download', $document))
            ->assertOk()
            ->assertDownload();
    }

    public function test_khong_tai_duoc_khi_rule_chi_cho_xem(): void
    {
        $document = $this->makeDocumentWithFile(['allow_download' => true]);
        $this->allowView($document);

        // Tài liệu cho phép tải nhưng rule chỉ cấp quyền xem
        $this->actingAs($this->user)
            ->get(route('learn.documents.download', $document))
            ->assertForbidden();
    }

    public function test_luot_tai_xuong_ghi_log_rieng(): void
    {
        $document = $this->makeDocumentWithFile(['allow_download' => true]);

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $this->department->id,
            'can_view' => true,
            'can_download' => true,
        ]);

        $this->actingAs($this->user)
            ->get(route('learn.documents.download', $document));

        $this->assertDatabaseHas('document_access_logs', [
            'document_id' => $document->id,
            'action' => DocumentAccessLog::ACTION_DOWNLOAD,
        ]);
    }

    // ---- Điều kiện phân quyền -----------------------------------------------

    public function test_dieu_kien_cap_bac_ap_dung_o_tang_route(): void
    {
        $lowGrade = JobGrade::factory()->create(['level' => 2]);
        $this->employee->update(['job_grade_id' => $lowGrade->id]);

        $document = $this->makeDocumentWithFile();
        DocumentAccessRule::create([
            'document_id' => $document->id,
            'min_grade_level' => 5,
            'can_view' => true,
        ]);

        $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document))
            ->assertForbidden();

        // Nâng cấp bậc thì xem được ngay, không cần cấu hình lại gì.
        // Nạp lại user để request sau không dùng quan hệ đã cache ở request trước.
        $highGrade = JobGrade::factory()->create(['level' => 8]);
        $this->employee->update(['job_grade_id' => $highGrade->id]);

        $this->actingAs(User::find($this->user->id))
            ->get(route('learn.documents.stream', $document))
            ->assertOk();
    }

    public function test_rule_chan_thang_rule_cho_phep(): void
    {
        $document = $this->makeDocumentWithFile();

        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $this->department->id,
            'can_view' => true,
            'effect' => DocumentAccessRule::EFFECT_ALLOW,
            'priority' => 5,
        ]);
        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $this->department->id,
            'effect' => DocumentAccessRule::EFFECT_DENY,
            'priority' => 5,
        ]);

        $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document))
            ->assertForbidden();
    }

    // ---- Xử lý file thiếu ---------------------------------------------------

    public function test_tai_lieu_chua_co_file_tra_ve_404(): void
    {
        $document = Document::factory()->create(['current_version_id' => null]);
        $this->allowView($document);

        $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document))
            ->assertNotFound();
    }

    public function test_file_bi_xoa_ngoai_he_thong_tra_ve_404(): void
    {
        $document = $this->makeDocumentWithFile();
        $this->allowView($document);

        // Xoá file vật lý nhưng giữ bản ghi
        $storedFile = $document->currentVersion->storedFile;
        Storage::disk($storedFile->disk)->delete($storedFile->path);

        $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document))
            ->assertNotFound();
    }

    // ---- Header bảo mật -----------------------------------------------------

    public function test_khong_cho_cache_o_proxy_trung_gian(): void
    {
        $document = $this->makeDocumentWithFile();
        $this->allowView($document);

        $response = $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document));

        // Tài liệu nội bộ không được nằm lại trên máy khác sau khi đóng phiên
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_tai_lieu_bat_watermark_co_ma_truy_vet(): void
    {
        $document = $this->makeDocumentWithFile(['enable_watermark' => true]);
        $this->allowView($document);

        $response = $this->actingAs($this->user)
            ->get(route('learn.documents.stream', $document));

        $this->assertNotEmpty($response->headers->get('X-Access-Token'));
    }

    // ---- Trợ giúp -----------------------------------------------------------

    private function makeDocumentWithFile(array $attributes = []): Document
    {
        $document = Document::factory()->create(array_merge([
            'status' => Document::STATUS_PUBLISHED,
        ], $attributes));

        $storedFile = app(FileStorageService::class)->storeForPurpose(
            UploadedFile::fake()->create('tai-lieu.pdf', 100, 'application/pdf'),
            FileStorageService::PURPOSE_DOCUMENT,
        );

        $document->newVersion([
            'stored_file_id' => $storedFile->id,
            'original_filename' => $storedFile->original_name,
            'mime_type' => 'application/pdf',
            'size_bytes' => $storedFile->size_bytes,
        ]);

        return $document->refresh();
    }

    private function allowView(Document $document): void
    {
        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $this->department->id,
            'can_view' => true,
        ]);
    }
}
