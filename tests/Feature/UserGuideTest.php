<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use App\Services\PdfWatermarkService;
use App\Services\UserGuideService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/** Tài liệu hướng dẫn sử dụng và watermark khi tải file. */
class UserGuideTest extends TestCase
{
    use RefreshDatabase;

    private User $employeeUser;
    private User $adminUser;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->employeeUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->employeeUser->assignRole(RoleName::EMPLOYEE->value);
        $this->employee = Employee::factory()->create([
            'user_id' => $this->employeeUser->id,
            'full_name' => 'Nguyễn Văn Minh',
        ]);

        $this->adminUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->adminUser->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $this->adminUser->id]);
    }

    /** Tạo một PDF nhiều trang để thử đóng dấu. */
    private function makePdf(int $pages = 2): string
    {
        $path = tempnam(sys_get_temp_dir(), 'src_') . '.pdf';

        $pdf = new \FPDF();

        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', '', 12);
            $pdf->Cell(0, 10, "Trang {$i}", 0, 1);
        }

        $pdf->Output('F', $path);

        return $path;
    }

    private function pageCount(string $path): int
    {
        return (new Fpdi())->setSourceFile($path);
    }

    // ---- Đóng watermark vào PDF ---------------------------------------------

    public function test_dong_duoc_watermark_vao_pdf(): void
    {
        $src = $this->makePdf(1);

        $out = app(PdfWatermarkService::class)->stamp($src, ['Nguyễn Văn Minh', 'Ma: abc123']);

        $this->assertNotNull($out);
        $this->assertGreaterThan(filesize($src), filesize($out));
        $this->assertStringStartsWith('%PDF', file_get_contents($out, false, null, 0, 4));

        @unlink($src);
        @unlink($out);
    }

    public function test_giu_nguyen_so_trang_khi_dong_dau(): void
    {
        // Cell() vẽ gần đáy trang từng khiến FPDF tự chèn trang mới:
        // PDF 2 trang phình thành 18 trang
        foreach ([1, 2, 5] as $pages) {
            $src = $this->makePdf($pages);
            $out = app(PdfWatermarkService::class)->stamp($src, ['Người tải']);

            $this->assertNotNull($out);
            $this->assertSame($pages, $this->pageCount($out), "PDF {$pages} trang phải giữ đúng số trang");

            @unlink($src);
            @unlink($out);
        }
    }

    public function test_watermark_ghi_vao_moi_trang(): void
    {
        $src = $this->makePdf(3);
        $out = app(PdfWatermarkService::class)->stamp($src, ['DAUHIEUWATERMARK']);

        $raw = file_get_contents($out);
        $found = 0;

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams);

        foreach ($streams[1] as $stream) {
            $data = @gzuncompress($stream);

            if ($data !== false && str_contains($data, 'DAUHIEUWATERMARK')) {
                $found++;
            }
        }

        $this->assertSame(3, $found, 'Cả 3 trang đều phải có watermark');

        @unlink($src);
        @unlink($out);
    }

    public function test_bo_dau_tieng_viet_de_font_ve_duoc(): void
    {
        // FPDF dùng font core Latin-1, chữ có dấu sẽ ra ký tự lỗi
        $src = $this->makePdf(1);
        $out = app(PdfWatermarkService::class)->stamp($src, ['Nguyễn Văn Minh']);

        $raw = file_get_contents($out);
        $text = '';

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams);

        foreach ($streams[1] as $stream) {
            $data = @gzuncompress($stream);

            if ($data !== false) {
                $text .= $data;
            }
        }

        $this->assertStringContainsString('Nguyen Van Minh', $text);

        @unlink($src);
        @unlink($out);
    }

    public function test_file_khong_ton_tai_thi_tra_null(): void
    {
        $this->assertNull(
            app(PdfWatermarkService::class)->stamp('/khong/co/file.pdf', ['Test'])
        );
    }

    public function test_khong_co_dong_chu_nao_thi_tra_null(): void
    {
        $src = $this->makePdf(1);

        $this->assertNull(app(PdfWatermarkService::class)->stamp($src, []));
        $this->assertNull(app(PdfWatermarkService::class)->stamp($src, ['', '  ']));

        @unlink($src);
    }

    public function test_file_khong_phai_pdf_thi_tra_null_khong_vo(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'txt_');
        file_put_contents($path, 'day khong phai PDF');

        $this->assertNull(app(PdfWatermarkService::class)->stamp($path, ['Test']));

        @unlink($path);
    }

    // ---- Sinh tài liệu hướng dẫn --------------------------------------------

    public function test_sinh_duoc_tai_lieu_cho_nhan_vien(): void
    {
        $path = app(UserGuideService::class)
            ->generate(UserGuideService::AUDIENCE_EMPLOYEE, $this->employee, watermark: false);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, $this->pageCount($path));

        @unlink($path);
    }

    public function test_ban_quan_tri_khac_ban_nhan_vien(): void
    {
        $service = app(UserGuideService::class);

        $nv = $service->generate(UserGuideService::AUDIENCE_EMPLOYEE, null, watermark: false);
        $qt = $service->generate(UserGuideService::AUDIENCE_ADMIN, null, watermark: false);

        // Bản quản trị nêu thêm phân quyền, bảo mật, cấu hình nên dài hơn
        $this->assertGreaterThan(filesize($nv), filesize($qt));

        @unlink($nv);
        @unlink($qt);
    }

    public function test_bat_watermark_thi_file_co_ten_nguoi_tai(): void
    {
        $service = app(UserGuideService::class);

        $khong = $service->generate(UserGuideService::AUDIENCE_EMPLOYEE, $this->employee, watermark: false);
        $co = $service->generate(UserGuideService::AUDIENCE_EMPLOYEE, $this->employee, watermark: true);

        $this->assertGreaterThan(filesize($khong), filesize($co));

        @unlink($khong);
        @unlink($co);
    }

    public function test_ten_file_theo_vai_tro(): void
    {
        $service = app(UserGuideService::class);

        $this->assertStringContainsString('Quan-tri', $service->fileName(UserGuideService::AUDIENCE_ADMIN));
        $this->assertStringContainsString('Nhan-vien', $service->fileName(UserGuideService::AUDIENCE_EMPLOYEE));
    }

    // ---- Tải tài liệu -------------------------------------------------------

    public function test_nhan_vien_tai_duoc_tai_lieu(): void
    {
        $this->actingAs($this->employeeUser)
            ->get(route('learn.guide'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_nhan_vien_khong_tai_duoc_ban_quan_tri(): void
    {
        // Bản quản trị nêu chi tiết cấu hình bảo mật và phân quyền
        $this->actingAs($this->employeeUser)
            ->get(route('admin.guide', 'admin'))
            ->assertRedirect(route('learn.events'));
    }

    public function test_admin_tai_duoc_ca_hai_ban(): void
    {
        $this->actingAs($this->adminUser)->get(route('admin.guide', 'admin'))->assertOk();
        $this->actingAs($this->adminUser)->get(route('admin.guide', 'employee'))->assertOk();
    }

    public function test_khach_chua_dang_nhap_khong_tai_duoc(): void
    {
        $this->get(route('learn.guide'))->assertRedirect(route('login'));
    }

    public function test_tat_trong_cau_hinh_thi_khong_tai_duoc(): void
    {
        Setting::set('guide.enabled', '0', 'boolean', 'guide');

        $this->actingAs($this->employeeUser)
            ->get(route('learn.guide'))
            ->assertNotFound();
    }
}
