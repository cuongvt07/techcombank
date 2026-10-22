<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\DocumentLibrary;
use App\Models\Department;
use App\Models\Document;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Kiểm chứng thư viện tài liệu, versioning và cấp độ bảo mật (spec 3.2.1 + 3.2.2). */
class DocumentLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin->refresh());
    }

    public function test_tao_tai_lieu_sinh_phien_ban_dau_tien(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Quy trình mở tài khoản')
            ->set('kind', Document::KIND_FILE)
            ->set('upload', UploadedFile::fake()->create('quy-trinh.pdf', 100, 'application/pdf'))
            ->call('continueToPermissions')
            ->call('save')
            ->assertHasNoErrors();

        $document = Document::where('title', 'Quy trình mở tài khoản')->first();

        $this->assertNotNull($document);
        $this->assertSame(Document::STATUS_DRAFT, $document->status);
        // Phiên bản đầu phải được tạo và trỏ current_version_id sang nó
        $this->assertSame(1, $document->versions()->count());
        $this->assertNotNull($document->current_version_id);
        $this->assertSame(1, $document->currentVersion->version_no);
    }

    public function test_sua_tai_lieu_co_buoc_phan_quyen_va_cap_nhat_quy_tac_chinh(): void
    {
        $dept = Department::factory()->create();
        $other = Department::factory()->create();
        $grade = JobGrade::factory()->create();

        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Tài liệu sửa phân quyền')
            ->set('upload', UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'))
            ->call('continueToPermissions')
            ->set('accessDepartmentId', $dept->id)
            ->call('save')
            ->assertHasNoErrors();

        $document = Document::where('title', 'Tài liệu sửa phân quyền')->firstOrFail();
        $rule = $document->accessRules()->sole();

        // Mở sửa: bước 2 nạp đúng quy tắc hiện có
        Livewire::test(DocumentLibrary::class)
            ->call('edit', $document->id)
            ->assertSet('createStep', 1)
            ->call('continueToPermissions')
            ->assertSet('createStep', 2)
            ->assertSet('accessRuleId', $rule->id)
            ->assertSet('accessDepartmentId', $dept->id)
            ->set('accessDepartmentId', $other->id)
            ->set('accessJobGradeIds', [$grade->id])
            ->set('accessCanDownload', true)
            ->call('save')
            ->assertHasNoErrors();

        // Sửa đúng quy tắc cũ chứ không sinh thêm quy tắc mới
        $rule = $document->accessRules()->sole();
        $this->assertSame($other->id, $rule->department_id);
        $this->assertSame([$grade->id], $rule->gradeIds());
        $this->assertTrue((bool) $rule->can_download);
        $this->assertTrue((bool) $document->fresh()->allow_download);
    }

    public function test_loc_tai_lieu_theo_cap_do_bao_mat(): void
    {
        Document::factory()->create(['title' => 'Tài Liệu Tối Mật', 'confidentiality' => 'restricted']);
        Document::factory()->create(['title' => 'Tài Liệu Công Khai', 'confidentiality' => 'public']);

        Livewire::test(DocumentLibrary::class)
            ->set('confidentialityFilter', 'restricted')
            ->assertSee('Tài Liệu Tối Mật')
            ->assertDontSee('Tài Liệu Công Khai');
    }

    public function test_bo_chon_danh_muc_khong_loi(): void
    {
        // Ô chọn có tìm kiếm gửi null khi chọn lại "Mọi danh mục"
        Livewire::test(DocumentLibrary::class)
            ->set('categoryFilter', null)
            ->assertOk();
    }

    public function test_tai_lieu_moi_bat_buoc_co_file(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Tài liệu không file')
            ->call('continueToPermissions')
            ->call('save')
            ->assertHasErrors(['upload' => 'required']);
    }

    public function test_tai_len_file_moi_sinh_phien_ban_moi_va_giu_lich_su(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Sổ tay nhân viên')
            ->set('upload', UploadedFile::fake()->create('v1.pdf', 50, 'application/pdf'))
            ->call('continueToPermissions')
            ->call('save');

        $document = Document::where('title', 'Sổ tay nhân viên')->first();
        $firstVersionId = $document->current_version_id;

        Livewire::test(DocumentLibrary::class)
            ->call('edit', $document->id)
            ->set('upload', UploadedFile::fake()->create('v2.pdf', 60, 'application/pdf'))
            ->set('change_note', 'Cập nhật chương 3')
            ->call('continueToPermissions')
            ->call('save')
            ->assertHasNoErrors();

        $document->refresh();

        $this->assertSame(2, $document->versions()->count());
        $this->assertSame(2, $document->currentVersion->version_no);
        // Phiên bản cũ vẫn còn nguyên trong lịch sử
        $this->assertDatabaseHas('document_versions', ['id' => $firstVersionId, 'version_no' => 1]);
    }

    public function test_khoi_phuc_ve_phien_ban_cu(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Chính sách bảo mật')
            ->set('upload', UploadedFile::fake()->create('v1.pdf', 50, 'application/pdf'))
            ->call('continueToPermissions')
            ->call('save');

        $document = Document::where('title', 'Chính sách bảo mật')->first();
        $v1 = $document->current_version_id;

        Livewire::test(DocumentLibrary::class)
            ->call('edit', $document->id)
            ->set('upload', UploadedFile::fake()->create('v2.pdf', 60, 'application/pdf'))
            ->call('continueToPermissions')
            ->call('save');

        $this->assertNotSame($v1, $document->refresh()->current_version_id);

        Livewire::test(DocumentLibrary::class)->call('rollback', $v1);

        // Rollback chỉ đổi con trỏ, không xoá phiên bản v2
        $this->assertSame($v1, $document->refresh()->current_version_id);
        $this->assertSame(2, $document->versions()->count());
    }

    public function test_khong_the_xuat_ban_tai_lieu_chua_co_file(): void
    {
        $document = Document::factory()->draft()->create(['current_version_id' => null]);

        Livewire::test(DocumentLibrary::class)->call('publish', $document->id);

        $this->assertSame(Document::STATUS_DRAFT, $document->refresh()->status);
    }

    public function test_xuat_ban_tai_lieu_da_co_file(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Hướng dẫn sử dụng hệ thống')
            ->set('upload', UploadedFile::fake()->create('huong-dan.pdf', 40, 'application/pdf'))
            ->call('continueToPermissions')
            ->call('save');

        $document = Document::where('title', 'Hướng dẫn sử dụng hệ thống')->first();

        Livewire::test(DocumentLibrary::class)->call('publish', $document->id);

        $document->refresh();
        $this->assertSame(Document::STATUS_PUBLISHED, $document->status);
        $this->assertNotNull($document->published_at);
    }

    public function test_video_bat_buoc_co_link_nguon(): void
    {
        // Video không upload file (giới hạn 2MB), phải nhập link nguồn ngoài
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Video đào tạo')
            ->set('kind', Document::KIND_VIDEO)
            ->call('continueToPermissions')
            ->call('save')
            ->assertHasErrors('video_url');
    }

    public function test_video_chan_link_khong_nhan_dien_duoc(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Video link lạ')
            ->set('kind', Document::KIND_VIDEO)
            ->set('video_url', 'https://example.com/khong-phai-video')
            ->call('continueToPermissions')
            ->call('save')
            ->assertHasErrors('video_url');
    }

    public function test_video_luu_duoc_voi_link_youtube(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Video hướng dẫn')
            ->set('kind', Document::KIND_VIDEO)
            ->set('video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->call('continueToPermissions')
            ->call('save')
            ->assertHasNoErrors();

        $document = Document::where('title', 'Video hướng dẫn')->first();

        // Mã video được tách sẵn để dựng link nhúng, không phải parse lại mỗi lần hiển thị
        $this->assertSame('youtube', $document->video_provider);
        $this->assertSame('dQw4w9WgXcQ', $document->video_embed_id);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $document->embedUrl());
    }

    public function test_video_xuat_ban_duoc_khi_co_link(): void
    {
        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Video xuất bản')
            ->set('kind', Document::KIND_VIDEO)
            ->set('video_url', 'https://vimeo.com/123456789')
            ->call('continueToPermissions')
            ->call('save');

        $document = Document::where('title', 'Video xuất bản')->first();

        // Video không cần file trong kho, chỉ cần link nguồn
        Livewire::test(DocumentLibrary::class)->call('publish', $document->id);

        $this->assertSame(Document::STATUS_PUBLISHED, $document->refresh()->status);
    }

    public function test_slug_khong_bi_trung_khi_tieu_de_giong_nhau(): void
    {
        foreach (['a.pdf', 'b.pdf'] as $file) {
            Livewire::test(DocumentLibrary::class)
                ->call('create')
                ->set('title', 'Tài liệu cùng tên')
                ->set('upload', UploadedFile::fake()->create($file, 30, 'application/pdf'))
                ->call('continueToPermissions')
                ->call('save')
                ->assertHasNoErrors();
        }

        $slugs = Document::where('title', 'Tài liệu cùng tên')->pluck('slug');

        $this->assertCount(2, $slugs);
        $this->assertSame(2, $slugs->unique()->count(), 'Slug phải duy nhất vì dùng làm định danh URL');
    }

    public function test_loc_theo_loai_va_trang_thai(): void
    {
        Document::factory()->create(['title' => 'Video Bài Giảng A', 'kind' => Document::KIND_VIDEO]);
        Document::factory()->create(['title' => 'Tài Liệu Văn Bản B', 'kind' => Document::KIND_FILE]);

        Livewire::test(DocumentLibrary::class)
            ->set('kindFilter', Document::KIND_VIDEO)
            ->assertSee('Video Bài Giảng A')
            ->assertDontSee('Tài Liệu Văn Bản B');
    }

    public function test_go_xuat_ban_chuyen_sang_luu_tru(): void
    {
        $document = Document::factory()->create(['status' => Document::STATUS_PUBLISHED]);

        Livewire::test(DocumentLibrary::class)->call('unpublish', $document->id);

        $this->assertSame(Document::STATUS_ARCHIVED, $document->refresh()->status);
    }
}
