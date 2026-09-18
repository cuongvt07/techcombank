<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nhận diện loại file để quyết định xem thẳng trong trang hay mở ứng dụng ngoài.
 */
class DocumentViewerTest extends TestCase
{
    use RefreshDatabase;

    private function documentWithFile(string $extension, ?string $mime = null): Document
    {
        $document = Document::create([
            'title' => 'Tài liệu thử',
            'slug' => 'tai-lieu-thu-' . $extension,
            'kind' => Document::KIND_FILE,
            'status' => Document::STATUS_PUBLISHED,
        ]);

        $file = StoredFile::create([
            'name' => 'file.' . $extension,
            'original_name' => 'file.' . $extension,
            'disk' => 'local',
            'path' => 'kho-tai-nguyen/file.' . $extension,
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => 100,
        ]);

        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version_no' => 1,
            'stored_file_id' => $file->id,
        ]);

        $document->update(['current_version_id' => $version->id]);

        return $document->refresh();
    }

    public function test_pdf_xem_duoc_trong_trang(): void
    {
        $document = $this->documentWithFile('pdf', 'application/pdf');

        $this->assertTrue($document->isPdf());
        $this->assertTrue($document->isInlineViewable());
        $this->assertFalse($document->isWord());
    }

    public function test_nhan_dien_pdf_qua_mime_khi_thieu_phan_mo_rong(): void
    {
        // File tải lên có thể không kèm phần mở rộng chuẩn
        $document = $this->documentWithFile('', 'application/pdf');

        $this->assertTrue($document->isPdf());
    }

    public function test_docx_khong_xem_duoc_trong_trang(): void
    {
        // Trình duyệt không đọc được Word. Chuyển đổi để xem trong trang phải
        // qua dịch vụ ngoài — tức đẩy tài liệu nội bộ ra Internet.
        $document = $this->documentWithFile(
            'docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        $this->assertTrue($document->isWord());
        $this->assertFalse($document->isInlineViewable());
    }

    public function test_doc_cu_cung_nhan_dien_la_word(): void
    {
        $this->assertTrue($this->documentWithFile('doc')->isWord());
    }

    public function test_phan_mo_rong_viet_hoa_van_nhan_dien_dung(): void
    {
        $this->assertTrue($this->documentWithFile('PDF')->isPdf());
        $this->assertTrue($this->documentWithFile('DOCX')->isWord());
    }

    public function test_tai_lieu_khong_co_file_thi_khong_xem_trong_trang(): void
    {
        // Tài liệu dạng video nhúng không có file đính kèm
        $document = Document::create([
            'title' => 'Video nhúng',
            'slug' => 'video-nhung',
            'kind' => Document::KIND_VIDEO,
            'video_url' => 'https://www.youtube.com/watch?v=abc',
            'status' => Document::STATUS_PUBLISHED,
        ]);

        $this->assertNull($document->fileExtension());
        $this->assertFalse($document->isPdf());
        $this->assertFalse($document->isInlineViewable());
    }

    public function test_excel_khong_xem_duoc_trong_trang(): void
    {
        $document = $this->documentWithFile('xlsx');

        $this->assertFalse($document->isInlineViewable());
        $this->assertFalse($document->isWord());
    }
}
