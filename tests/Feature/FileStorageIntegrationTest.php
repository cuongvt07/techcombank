<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\ContractManager;
use App\Livewire\Admin\DocumentLibrary;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Document;
use App\Models\Employee;
use App\Models\FileFolder;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\FileStorageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiểm chứng mọi file upload trong hệ thống đều đi vào kho tài nguyên chung.
 */
class FileStorageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(FileStorageService::DISK);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin->refresh());
    }

    // ---- Tài liệu đào tạo ---------------------------------------------------

    public function test_tai_lieu_upload_vao_kho_tai_nguyen(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Quy trình mở tài khoản')
            ->set('kind', Document::KIND_FILE)
            ->set('upload', UploadedFile::fake()->create('quy-trinh.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $document = Document::where('title', 'Quy trình mở tài khoản')->first();
        $version = $document->currentVersion;

        // Phiên bản phải trỏ tới một file trong kho
        $this->assertNotNull($version->stored_file_id);

        $storedFile = $version->storedFile;
        $this->assertNotNull($storedFile);
        $this->assertSame('quy-trinh.pdf', $storedFile->name);
        Storage::disk(FileStorageService::DISK)->assertExists($storedFile->path);
    }

    public function test_tai_lieu_vao_dung_thu_muc_he_thong(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Sổ tay nhân viên')
            ->set('kind', Document::KIND_FILE)
            ->set('upload', UploadedFile::fake()->create('so-tay.pdf', 50, 'application/pdf'))
            ->call('save');

        $storedFile = Document::where('title', 'Sổ tay nhân viên')
            ->first()->currentVersion->storedFile;

        $this->assertSame('Tài liệu đào tạo', $storedFile->folder->name);
        $this->assertTrue($storedFile->folder->is_system);
    }

    public function test_video_khong_tao_file_trong_kho(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Video hướng dẫn')
            ->set('kind', Document::KIND_VIDEO)
            ->set('video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->call('save')
            ->assertHasNoErrors();

        $document = Document::where('title', 'Video hướng dẫn')->first();

        // Video nhúng từ nguồn ngoài: không có file nào được lưu vào kho
        $this->assertNull($document->current_version_id);
        $this->assertSame(0, StoredFile::count());
        $this->assertNotNull($document->video_url);
    }

    public function test_moi_phien_ban_tai_lieu_la_mot_file_rieng_trong_kho(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Chính sách bảo mật')
            ->set('upload', UploadedFile::fake()->create('v1.pdf', 50, 'application/pdf'))
            ->call('save');

        $document = Document::where('title', 'Chính sách bảo mật')->first();
        $firstFileId = $document->currentVersion->stored_file_id;

        Livewire::test(DocumentLibrary::class)
            ->call('edit', $document->id)
            ->set('upload', UploadedFile::fake()->create('v2.pdf', 60, 'application/pdf'))
            ->call('save');

        $document->refresh();

        // Phiên bản mới có file riêng, file cũ vẫn còn để rollback được
        $this->assertNotSame($firstFileId, $document->currentVersion->stored_file_id);
        $this->assertSame(2, StoredFile::count());
        $this->assertDatabaseHas('stored_files', ['id' => $firstFileId]);
    }

    public function test_rollback_van_tro_dung_file_cu(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Tài liệu rollback')
            ->set('upload', UploadedFile::fake()->create('v1.pdf', 50, 'application/pdf'))
            ->call('save');

        $document = Document::where('title', 'Tài liệu rollback')->first();
        $v1 = $document->currentVersion;
        $v1FileId = $v1->stored_file_id;

        Livewire::test(DocumentLibrary::class)
            ->call('edit', $document->id)
            ->set('upload', UploadedFile::fake()->create('v2.pdf', 60, 'application/pdf'))
            ->call('save');

        Livewire::test(DocumentLibrary::class)->call('rollback', $v1->id);

        $document->refresh();
        $this->assertSame($v1FileId, $document->currentVersion->stored_file_id);
    }

    // ---- Hợp đồng -----------------------------------------------------------

    public function test_file_hop_dong_upload_vao_kho(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['code' => 'HD1', 'name' => 'Xác định thời hạn']);

        Livewire::test(ContractManager::class)
            ->call('create')
            ->set('employee_id', $employee->id)
            ->set('contract_type_id', $type->id)
            ->set('contract_no', 'HD-KHO-001')
            ->set('effective_from', now()->toDateString())
            ->set('file', UploadedFile::fake()->create('hop-dong.pdf', 200, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $contract = Contract::where('contract_no', 'HD-KHO-001')->first();

        $this->assertNotNull($contract->stored_file_id);
        $this->assertSame('hop-dong.pdf', $contract->storedFile->name);
        $this->assertSame('Hợp đồng', $contract->storedFile->folder->name);
        Storage::disk(FileStorageService::DISK)->assertExists($contract->storedFile->path);
    }

    public function test_hop_dong_khong_co_file_van_luu_duoc(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['code' => 'HD2', 'name' => 'Thử việc']);

        Livewire::test(ContractManager::class)
            ->call('create')
            ->set('employee_id', $employee->id)
            ->set('contract_type_id', $type->id)
            ->set('contract_no', 'HD-KHONG-FILE')
            ->set('effective_from', now()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $contract = Contract::where('contract_no', 'HD-KHONG-FILE')->first();
        $this->assertNull($contract->stored_file_id);
    }

    // ---- Thư mục hệ thống ---------------------------------------------------

    public function test_thu_muc_he_thong_tu_tao_khi_chua_co(): void
    {
        // Chưa seed thư mục nào
        $this->assertSame(0, FileFolder::count());

        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Tài liệu đầu tiên')
            ->set('upload', UploadedFile::fake()->create('a.pdf', 30, 'application/pdf'))
            ->call('save');

        // Luồng upload không được vỡ chỉ vì thư mục chưa tồn tại
        $this->assertDatabaseHas('file_folders', [
            'name' => 'Tài liệu đào tạo',
            'is_system' => true,
        ]);
    }

    public function test_file_tu_moi_nguon_deu_thay_duoc_trong_kho(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['code' => 'HD3', 'name' => 'Ngắn hạn']);

        // Tải lên từ hai màn hình khác nhau
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Tài liệu A')
            ->set('upload', UploadedFile::fake()->create('a.pdf', 30, 'application/pdf'))
            ->call('save');

        Livewire::test(ContractManager::class)
            ->call('create')
            ->set('employee_id', $employee->id)
            ->set('contract_type_id', $type->id)
            ->set('contract_no', 'HD-XX-001')
            ->set('effective_from', now()->toDateString())
            ->set('file', UploadedFile::fake()->create('b.pdf', 40, 'application/pdf'))
            ->call('save');

        // Cả hai đều nằm trong kho chung
        $this->assertSame(2, StoredFile::count());

        $names = StoredFile::pluck('name')->all();
        $this->assertContains('a.pdf', $names);
        $this->assertContains('b.pdf', $names);
    }
}
