<?php

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\StoredFile;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** Kiểm chứng kho tài nguyên: cây thư mục, tải file, di chuyển, xóa. */
class FileStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    private FileStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(FileStorageService::DISK);
        $this->service = app(FileStorageService::class);
    }

    // ---- Thư mục ------------------------------------------------------------

    public function test_tao_thu_muc_goc(): void
    {
        $folder = $this->service->createFolder('Tài liệu chung');

        $this->assertSame('Tài liệu chung', $folder->name);
        $this->assertNull($folder->parent_id);
        $this->assertSame('/', $folder->path);
        $this->assertSame(0, $folder->depth);
    }

    public function test_thu_muc_con_tinh_dung_path_va_depth(): void
    {
        $root = $this->service->createFolder('Gốc');
        $child = $this->service->createFolder('Con', $root->id);
        $grandchild = $this->service->createFolder('Cháu', $child->id);

        $this->assertSame('/' . $root->id . '/', $child->path);
        $this->assertSame(1, $child->depth);

        $this->assertSame('/' . $root->id . '/' . $child->id . '/', $grandchild->path);
        $this->assertSame(2, $grandchild->depth);
    }

    public function test_khong_cho_trung_ten_trong_cung_thu_muc_cha(): void
    {
        $this->service->createFolder('Báo cáo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('đã tồn tại');

        $this->service->createFolder('Báo cáo');
    }

    public function test_trung_ten_o_thu_muc_cha_khac_thi_duoc(): void
    {
        $a = $this->service->createFolder('Phòng A');
        $b = $this->service->createFolder('Phòng B');

        $this->service->createFolder('Báo cáo', $a->id);
        $second = $this->service->createFolder('Báo cáo', $b->id);

        $this->assertSame('Báo cáo', $second->name);
        $this->assertSame(2, FileFolder::where('name', 'Báo cáo')->count());
    }

    public function test_ten_thu_muc_khong_duoc_de_trong(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->createFolder('   ');
    }

    public function test_lay_duoc_toan_bo_thu_muc_con_moi_cap(): void
    {
        $root = $this->service->createFolder('Gốc');
        $child = $this->service->createFolder('Con', $root->id);
        $this->service->createFolder('Cháu', $child->id);
        $this->service->createFolder('Ngoài nhánh');

        $this->assertSame(2, $root->descendants()->count());
    }

    public function test_breadcrumb_dung_thu_tu_tu_goc(): void
    {
        $root = $this->service->createFolder('Gốc');
        $child = $this->service->createFolder('Con', $root->id);
        $grandchild = $this->service->createFolder('Cháu', $child->id);

        $names = $grandchild->ancestors()->pluck('name')->all();

        $this->assertSame(['Gốc', 'Con'], $names);
        $this->assertSame('Gốc / Con / Cháu', $grandchild->fullPath());
    }

    // ---- Di chuyển thư mục --------------------------------------------------

    public function test_di_chuyen_thu_muc_cap_nhat_ca_nhanh_con(): void
    {
        $a = $this->service->createFolder('A');
        $b = $this->service->createFolder('B');
        $child = $this->service->createFolder('Con của A', $a->id);
        $grandchild = $this->service->createFolder('Cháu', $child->id);

        $this->service->moveFolder($child, $b->id);

        $child->refresh();
        $grandchild->refresh();

        $this->assertSame($b->id, $child->parent_id);
        $this->assertSame('/' . $b->id . '/', $child->path);
        // Nhánh con phải được cập nhật theo, nếu không sẽ bị cắt rời khỏi cây
        $this->assertSame('/' . $b->id . '/' . $child->id . '/', $grandchild->path);
        $this->assertSame(2, $grandchild->depth);
    }

    public function test_khong_cho_di_chuyen_thu_muc_vao_chinh_no(): void
    {
        $folder = $this->service->createFolder('Thư mục');

        $this->expectException(RuntimeException::class);
        $this->service->moveFolder($folder, $folder->id);
    }

    public function test_khong_cho_di_chuyen_vao_nhanh_con_cua_chinh_no(): void
    {
        $parent = $this->service->createFolder('Cha');
        $child = $this->service->createFolder('Con', $parent->id);

        // Chuyển cha vào trong con sẽ tạo vòng lặp, cắt rời cả nhánh khỏi cây
        $this->expectException(RuntimeException::class);
        $this->service->moveFolder($parent, $child->id);
    }

    public function test_di_chuyen_ra_goc_duoc(): void
    {
        $parent = $this->service->createFolder('Cha');
        $child = $this->service->createFolder('Con', $parent->id);

        $this->service->moveFolder($child, null);

        $child->refresh();
        $this->assertNull($child->parent_id);
        $this->assertSame('/', $child->path);
        $this->assertSame(0, $child->depth);
    }

    // ---- Xóa thư mục --------------------------------------------------------

    public function test_khong_xoa_duoc_thu_muc_con_noi_dung(): void
    {
        $folder = $this->service->createFolder('Có nội dung');
        $this->service->createFolder('Con', $folder->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('đang chứa');

        $this->service->deleteFolder($folder);
    }

    public function test_xoa_duoc_khi_xac_nhan_xoa_ca_noi_dung(): void
    {
        $folder = $this->service->createFolder('Có nội dung');
        $child = $this->service->createFolder('Con', $folder->id);
        $file = $this->uploadTo($child->id);

        $this->service->deleteFolder($folder, force: true);

        $this->assertSoftDeleted('file_folders', ['id' => $folder->id]);
        $this->assertSoftDeleted('file_folders', ['id' => $child->id]);
        $this->assertSoftDeleted('stored_files', ['id' => $file->id]);
    }

    public function test_khong_xoa_duoc_thu_muc_he_thong(): void
    {
        $this->service->ensureSystemFolders();
        $system = FileFolder::where('is_system', true)->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hệ thống');

        $this->service->deleteFolder($system, force: true);
    }

    // ---- Tải file lên -------------------------------------------------------

    public function test_tai_file_len_thu_muc(): void
    {
        $folder = $this->service->createFolder('Tài liệu');
        $file = $this->uploadTo($folder->id, 'quy-trinh.pdf');

        $this->assertSame($folder->id, $file->folder_id);
        $this->assertSame('quy-trinh.pdf', $file->name);
        $this->assertSame('pdf', $file->extension);
        $this->assertNotEmpty($file->checksum);

        Storage::disk(FileStorageService::DISK)->assertExists($file->path);
    }

    public function test_ten_file_tren_dia_khac_ten_goc(): void
    {
        $file = $this->uploadTo(null, 'tên có dấu và khoảng trắng.pdf');

        // Tên gốc giữ ở CSDL, tên trên đĩa là uuid — tránh xung đột và
        // tránh tên người dùng đặt lọt vào đường dẫn hệ thống
        $this->assertSame('tên có dấu và khoảng trắng.pdf', $file->name);
        $this->assertStringNotContainsString('tên có dấu', $file->path);
        $this->assertStringEndsWith('.pdf', $file->path);
    }

    public function test_file_luu_theo_nam_thang(): void
    {
        $file = $this->uploadTo();

        $this->assertStringContainsString(now()->format('Y/m'), $file->path);
    }

    public function test_phat_hien_file_trung_noi_dung(): void
    {
        $first = $this->uploadTo(null, 'a.pdf');

        $duplicate = $this->service->findDuplicate($first->checksum, $first->id);
        $this->assertNull($duplicate, 'Chưa có bản sao nào');

        // Tải lại đúng nội dung đó
        $second = StoredFile::create([
            'name' => 'b.pdf',
            'original_name' => 'b.pdf',
            'disk' => FileStorageService::DISK,
            'path' => 'fake/b.pdf',
            'checksum' => $first->checksum,
            'size_bytes' => 100,
        ]);

        $found = $this->service->findDuplicate($first->checksum, $second->id);
        $this->assertSame($first->id, $found?->id);
    }

    // ---- Thao tác file ------------------------------------------------------

    public function test_doi_ten_file(): void
    {
        $file = $this->uploadTo(null, 'cu.pdf');

        $this->service->renameFile($file, 'Tên mới.pdf');

        $this->assertSame('Tên mới.pdf', $file->refresh()->name);
        // Tên gốc lúc tải lên vẫn giữ để truy vết
        $this->assertSame('cu.pdf', $file->original_name);
    }

    public function test_di_chuyen_file_sang_thu_muc_khac(): void
    {
        $a = $this->service->createFolder('A');
        $b = $this->service->createFolder('B');
        $file = $this->uploadTo($a->id);

        $this->service->moveFile($file, $b->id);

        $this->assertSame($b->id, $file->refresh()->folder_id);
    }

    public function test_khong_di_chuyen_duoc_vao_thu_muc_khong_ton_tai(): void
    {
        $file = $this->uploadTo();

        $this->expectException(RuntimeException::class);
        $this->service->moveFile($file, 99999);
    }

    public function test_xoa_mem_giu_lai_file_vat_ly(): void
    {
        $file = $this->uploadTo();
        $path = $file->path;

        $this->service->deleteFile($file);

        $this->assertSoftDeleted('stored_files', ['id' => $file->id]);
        // Xoá mềm phải khôi phục được, nên file vật lý chưa được xoá
        Storage::disk(FileStorageService::DISK)->assertExists($path);
    }

    public function test_xoa_vinh_vien_thi_xoa_ca_file_vat_ly(): void
    {
        $file = $this->uploadTo();
        $path = $file->path;

        $file->forceDelete();

        Storage::disk(FileStorageService::DISK)->assertMissing($path);
    }

    // ---- Thư mục hệ thống ---------------------------------------------------

    public function test_tao_bo_thu_muc_mac_dinh(): void
    {
        $this->service->ensureSystemFolders();

        // Đếm theo số mục đích khai báo trong service thay vì con số cứng:
        // thêm loại file mới thì test không phải sửa theo
        $this->assertSame(
            count(FileStorageService::purposes()),
            FileFolder::where('is_system', true)->count()
        );
        $this->assertDatabaseHas('file_folders', ['name' => 'Tài liệu đào tạo', 'is_system' => true]);
    }

    public function test_goi_lai_khong_tao_trung_thu_muc_he_thong(): void
    {
        $this->service->ensureSystemFolders();
        $this->service->ensureSystemFolders();

        $this->assertSame(
            count(FileStorageService::purposes()),
            FileFolder::where('is_system', true)->count()
        );
    }

    // ---- Dung lượng ---------------------------------------------------------

    public function test_tinh_tong_dung_luong_ca_nhanh(): void
    {
        $root = $this->service->createFolder('Gốc');
        $child = $this->service->createFolder('Con', $root->id);

        $this->uploadTo($root->id, 'a.pdf', 10);
        $this->uploadTo($child->id, 'b.pdf', 20);

        // Tổng phải gộp cả file trong thư mục con
        $this->assertGreaterThan(0, $root->totalSize());
        $this->assertSame(
            (int) StoredFile::sum('size_bytes'),
            $root->totalSize(),
        );
    }

    public function test_hien_thi_dung_luong_de_doc(): void
    {
        $this->assertSame('512 B', $this->service->humanSize(512));
        $this->assertSame('2 KB', $this->service->humanSize(2048));
        $this->assertSame('1.5 MB', $this->service->humanSize(1572864));
    }

    private function uploadTo(?int $folderId = null, string $name = 'test.pdf', int $sizeKb = 10): StoredFile
    {
        return $this->service->storeUpload(
            UploadedFile::fake()->create($name, $sizeKb, 'application/pdf'),
            $folderId,
        );
    }
}
