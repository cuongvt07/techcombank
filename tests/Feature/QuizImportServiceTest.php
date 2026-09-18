<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Quiz;
use App\Services\QuizImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** Kiểm chứng import bộ câu hỏi từ Excel (spec 3.2.3). */
class QuizImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuizImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QuizImportService::class);
    }

    protected function tearDown(): void
    {
        foreach (glob(Storage::disk('local')->path('test-import-*.xlsx')) as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_import_cau_hoi_hop_le(): void
    {
        $path = $this->makeExcel([
            ['noi_dung', 'loai', 'dap_an_a', 'dap_an_b', 'dap_an_c', 'dap_an_dung', 'diem', 'giai_thich'],
            ['Thủ đô Việt Nam là gì?', 'single', 'Hà Nội', 'Huế', 'Đà Nẵng', 'A', '1', 'Hà Nội là thủ đô.'],
            ['Chọn các thành phố trực thuộc TW', 'multiple', 'Hà Nội', 'Huế', 'Cần Thơ', 'A,C', '2', ''],
        ]);

        $result = $this->service->preview($path);

        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['rows']);

        $quiz = Quiz::factory()->create();
        $imported = $this->service->import($quiz, $result['rows']);

        $this->assertSame(2, $imported);
        $this->assertSame(2, $quiz->questions()->count());

        $single = $quiz->questions()->where('type', Question::TYPE_SINGLE)->first();
        $this->assertSame('Hà Nội', $single->options()->where('is_correct', true)->value('content'));
        $this->assertSame(3, $single->options()->count());

        $multiple = $quiz->questions()->where('type', Question::TYPE_MULTIPLE)->first();
        $this->assertSame(2, $multiple->options()->where('is_correct', true)->count());
        $this->assertEquals(2.0, (float) $multiple->score);
    }

    public function test_nhan_dien_tieu_de_cot_co_dau_va_viet_hoa(): void
    {
        $path = $this->makeExcel([
            ['Nội Dung', 'Loại', 'Đáp án A', 'Đáp án B', 'Đáp án đúng'],
            ['Câu hỏi thử', 'single', 'Đúng', 'Sai', 'A'],
        ]);

        $result = $this->service->preview($path);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['rows']);
    }

    public function test_bao_loi_khi_thieu_cot_bat_buoc(): void
    {
        $path = $this->makeExcel([
            ['cot_la', 'cot_khac'],
            ['giá trị', 'giá trị'],
        ]);

        $result = $this->service->preview($path);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Thiếu cột bắt buộc', $result['errors'][0]);
        $this->assertSame([], $result['rows']);
    }

    public function test_bao_loi_khi_dap_an_dung_khong_ton_tai(): void
    {
        $path = $this->makeExcel([
            ['noi_dung', 'dap_an_a', 'dap_an_b', 'dap_an_dung'],
            ['Câu hỏi lỗi', 'Đáp án 1', 'Đáp án 2', 'D'],
        ]);

        $result = $this->service->preview($path);

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Dòng 2', $result['errors'][0]);
        $this->assertStringContainsString('không tồn tại', $result['errors'][0]);
    }

    public function test_bao_loi_khi_it_hon_hai_dap_an(): void
    {
        $path = $this->makeExcel([
            ['noi_dung', 'dap_an_a', 'dap_an_dung'],
            ['Câu hỏi một đáp án', 'Chỉ có một', 'A'],
        ]);

        $result = $this->service->preview($path);

        $this->assertStringContainsString('ít nhất 2 đáp án', $result['errors'][0]);
    }

    public function test_bao_loi_khi_cau_chon_mot_lai_co_nhieu_dap_an_dung(): void
    {
        $path = $this->makeExcel([
            ['noi_dung', 'loai', 'dap_an_a', 'dap_an_b', 'dap_an_dung'],
            ['Câu hỏi mâu thuẫn', 'single', 'A', 'B', 'A,B'],
        ]);

        $result = $this->service->preview($path);

        $this->assertStringContainsString('chỉ cho phép 1 đáp án đúng', $result['errors'][0]);
    }

    public function test_bo_qua_dong_trong(): void
    {
        $path = $this->makeExcel([
            ['noi_dung', 'dap_an_a', 'dap_an_b', 'dap_an_dung'],
            ['Câu hỏi 1', 'A', 'B', 'A'],
            ['', '', '', ''],
            ['Câu hỏi 2', 'A', 'B', 'B'],
        ]);

        $result = $this->service->preview($path);

        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['rows']);
    }

    public function test_khong_ghi_gi_khi_file_co_dong_loi(): void
    {
        $path = $this->makeExcel([
            ['noi_dung', 'dap_an_a', 'dap_an_b', 'dap_an_dung'],
            ['Câu hỏi tốt', 'A', 'B', 'A'],
            ['Câu hỏi lỗi', 'A', 'B', 'Z'],
        ]);

        $result = $this->service->preview($path);
        $quiz = Quiz::factory()->create();

        // Có lỗi thì admin phải sửa file, không import nửa vời
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, $quiz->questions()->count());
    }

    public function test_import_noi_tiep_khong_ghi_de_cau_hoi_cu(): void
    {
        $quiz = Quiz::factory()->create();
        Question::factory()->create(['quiz_id' => $quiz->id, 'sort_order' => 1]);

        $path = $this->makeExcel([
            ['noi_dung', 'dap_an_a', 'dap_an_b', 'dap_an_dung'],
            ['Câu hỏi import thêm', 'A', 'B', 'A'],
        ]);

        $result = $this->service->preview($path);
        $this->service->import($quiz, $result['rows']);

        $this->assertSame(2, $quiz->questions()->count());
        // Câu mới xếp sau câu đã có, không giẫm lên sort_order cũ
        $this->assertSame(2, $quiz->questions()->max('sort_order'));
    }

    public function test_nhan_dien_loai_cau_hoi_dung_sai(): void
    {
        $path = $this->makeExcel([
            ['noi_dung', 'loai', 'dap_an_a', 'dap_an_b', 'dap_an_dung'],
            ['Mật khẩu dùng chung là an toàn.', 'dungsai', 'Đúng', 'Sai', 'B'],
        ]);

        $result = $this->service->preview($path);

        $this->assertSame(Question::TYPE_TRUE_FALSE, $result['rows'][0]['type']);
    }

    public function test_diem_phai_la_so_duong(): void
    {
        $path = $this->makeExcel([
            ['noi_dung', 'dap_an_a', 'dap_an_b', 'dap_an_dung', 'diem'],
            ['Câu hỏi điểm sai', 'A', 'B', 'A', '-5'],
        ]);

        $result = $this->service->preview($path);

        $this->assertStringContainsString('điểm phải là số lớn hơn 0', $result['errors'][0]);
    }

    public function test_file_mau_import_lai_duoc_khong_loi(): void
    {
        // File mẫu phát cho admin phải tự import lại được, nếu không là mẫu sai
        $path = $this->makeExcel($this->service->templateRows());

        $result = $this->service->preview($path);

        $this->assertSame([], $result['errors']);
        $this->assertCount(3, $result['rows']);
    }

    /** Ghi một file .xlsx thật để test đi qua đúng đường parse của maatwebsite/excel. */
    private function makeExcel(array $rows): string
    {
        $name = 'test-import-' . uniqid() . '.xlsx';

        Excel::store(new class($rows) implements \Maatwebsite\Excel\Concerns\FromArray {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        }, $name, 'local');

        // Lấy đường dẫn từ chính disk: Laravel 11 đặt root của disk local
        // ở storage/app/private, không phải storage/app như bản cũ.
        return Storage::disk('local')->path($name);
    }
}
