<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleName;
use App\Livewire\Admin\Reports\LearningProgressReport;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Reports\TrainingEffectivenessReportService;
use App\Support\Reports\ReportPeriod;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Báo cáo khuôn mẫu: trang in A4 + Excel theo mẫu cho các màn báo cáo,
 * và số liệu mẫu 1 "Báo cáo hiệu quả chương trình đào tạo".
 */
class ReportTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Department $parent;

    private Department $child;

    private Department $other;

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 UTC = 17:00 giờ VN 21/10/2026; kỳ 30 ngày = 22/09 → 21/10
        Carbon::setTestNow('2026-10-21 10:00:00');

        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id, 'full_name' => 'Phạm Ngọc Mai Anh']);
        $this->actingAs($admin->refresh());

        $this->parent = Department::factory()->create(['name' => 'Khối Vận hành']);
        $this->child = Department::factory()->create(['name' => 'Phòng Thẻ', 'parent_id' => $this->parent->id]);
        $this->other = Department::factory()->create(['name' => 'Chi nhánh Hà Nội']);

        $courseA = Course::factory()->create(['title' => 'An toàn thông tin']);
        $courseB = Course::factory()->create(['title' => 'Phòng chống rửa tiền']);
        $quizA = Quiz::factory()->create(['course_id' => $courseA->id, 'pass_score' => 70]);
        $quizB = Quiz::factory()->create(['course_id' => $courseB->id, 'pass_score' => 70]);

        $inParent = Employee::factory()->create(['department_id' => $this->parent->id]);
        $inChild = Employee::factory()->create(['department_id' => $this->child->id]);
        $inOther = Employee::factory()->create(['department_id' => $this->other->id]);

        // Khóa A — Khối Vận hành (Phòng Thẻ gộp lên khối): 1 đúng hạn, 1 trễ hạn
        $this->enroll($inParent, $courseA, ['status' => 'completed', 'progress_percent' => 100,
            'completed_at' => '2026-10-09 23:30:00', 'due_date' => '2026-10-10']); // 06:30 10/10 giờ VN → đúng hạn, 24,9 ngày
        $this->enroll($inChild, $courseA, ['status' => 'completed', 'progress_percent' => 100,
            'completed_at' => '2026-10-15 03:00:00', 'due_date' => '2026-10-12']); // trễ, 30,04 ngày
        // Khóa A — Chi nhánh Hà Nội: quá hạn, chưa xong
        $this->enroll($inOther, $courseA, ['status' => 'in_progress', 'progress_percent' => 30, 'due_date' => '2026-10-20']);
        // Khóa B — Chi nhánh Hà Nội: xong đúng hạn sau 16 ngày
        $this->enroll($inOther, $courseB, ['status' => 'completed', 'progress_percent' => 100,
            'completed_at' => '2026-10-01 02:00:00', 'due_date' => '2026-10-05']);
        // Đã hủy: không tính
        $this->enroll($inParent, $courseB, ['status' => 'cancelled', 'due_date' => '2026-10-05']);

        // Lần đầu: 90 (đạt), 60, 40 — lần 2 không được tính vào điểm TB / đạt lần 1
        $this->attempt($quizA, $inParent, 1, 90);
        $this->attempt($quizA, $inParent, 2, 50);
        $this->attempt($quizA, $inChild, 1, 60);
        $this->attempt($quizA, $inChild, 2, 80);
        $this->attempt($quizA, $inOther, 1, 40);
        // Bài của khóa B nhưng lượt ghi danh khóa B của người này đã hủy → không tính
        $this->attempt($quizB, $inParent, 1, 100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enroll(Employee $employee, Course $course, array $attrs): Enrollment
    {
        return Enrollment::create(array_merge([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'is_mandatory' => true,
            'total_lessons' => 4,
            'assigned_at' => '2026-09-15 02:00:00',
        ], $attrs));
    }

    private function attempt(Quiz $quiz, Employee $employee, int $no, float $pct): void
    {
        QuizAttempt::create([
            'quiz_id' => $quiz->id, 'employee_id' => $employee->id, 'attempt_no' => $no,
            'status' => QuizAttempt::STATUS_GRADED, 'score' => $pct, 'max_score' => 100, 'percentage' => $pct,
            'is_passed' => $pct >= $quiz->pass_score,
            'started_at' => '2026-10-05 03:00:00', 'submitted_at' => '2026-10-05 03:0' . $no . ':00',
        ]);
    }

    private function sheet(TestResponse $response): Worksheet
    {
        $response->assertOk()->assertDownload();

        return IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
    }

    // ---- Số liệu mẫu 1 ----------------------------------------------------------

    public function test_so_lieu_hieu_qua_dao_tao_theo_khoa_va_don_vi(): void
    {
        $data = app(TrainingEffectivenessReportService::class)->build([], ReportPeriod::make('30d'));
        $rows = $data['rows'];

        $this->assertSame(
            [['An toàn thông tin', 'Chi nhánh Hà Nội'], ['An toàn thông tin', 'Khối Vận hành'], ['Phòng chống rửa tiền', 'Chi nhánh Hà Nội']],
            $rows->map(fn ($r) => [$r['course'], $r['unit']])->all()
        );

        // Khóa A × Chi nhánh: chưa ai xong, điểm lần đầu 40
        $this->assertSame(1, $rows[0]['employees']);
        $this->assertSame(0, $rows[0]['on_time']);
        $this->assertEqualsWithDelta(40.0, $rows[0]['avg_score'], 0.001);
        $this->assertEqualsWithDelta(0.0, $rows[0]['first_pass_rate'], 0.001);
        $this->assertNull($rows[0]['avg_days']);
        $this->assertSame('Cần cải thiện', $rows[0]['rating']);

        // Khóa A × Khối Vận hành (gồm Phòng Thẻ): 1/2 đúng hạn, điểm (90 + 60) / 2, đạt lần 1 = 1/2
        $this->assertSame(2, $rows[1]['employees']);
        $this->assertSame(1, $rows[1]['on_time']);
        $this->assertEqualsWithDelta(50.0, $rows[1]['on_time_rate'], 0.001);
        $this->assertEqualsWithDelta(75.0, $rows[1]['avg_score'], 0.001);
        $this->assertEqualsWithDelta(50.0, $rows[1]['first_pass_rate'], 0.001);
        $this->assertEqualsWithDelta((24 + 21.5 / 24 + 30 + 1 / 24) / 2, $rows[1]['avg_days'], 0.001);
        $this->assertEqualsWithDelta((50 + 75 + 50) / 3, $rows[1]['index'], 0.001);
        $this->assertSame('Cần cải thiện', $rows[1]['rating']);

        // Khóa B × Chi nhánh: xong đúng hạn, không có lượt thi hợp lệ → đánh giá chỉ theo đúng hạn
        $this->assertSame(1, $rows[2]['on_time']);
        $this->assertNull($rows[2]['avg_score']);
        $this->assertEqualsWithDelta(16.0, $rows[2]['avg_days'], 0.001);
        $this->assertSame('Xuất sắc', $rows[2]['rating']);

        // Tổng cộng: 4 lượt / 3 nhân viên, điểm TB 3 lượt lần đầu
        $this->assertSame(3, $data['employees']);
        $this->assertSame(4, $data['totals']['employees']);
        $this->assertSame(2, $data['totals']['on_time']);
        $this->assertEqualsWithDelta(190 / 3, $data['totals']['avg_score'], 0.001);
        $this->assertEqualsWithDelta(100 / 3, $data['totals']['first_pass_rate'], 0.001);
    }

    public function test_chon_mot_don_vi_thi_tach_theo_don_vi_con(): void
    {
        $rows = app(TrainingEffectivenessReportService::class)
            ->build(['department' => $this->parent->id], ReportPeriod::make('30d'))['rows'];

        $this->assertSame(['Khối Vận hành', 'Phòng Thẻ'], $rows->pluck('unit')->all());
        $this->assertSame([1, 0], $rows->pluck('on_time')->all());
    }

    // ---- Trang in ---------------------------------------------------------------

    public function test_trang_in_mau_hieu_qua_dao_tao(): void
    {
        $this->get(route('admin.reports.template', ['report' => 'tien-do-hoc-tap']))
            ->assertOk()
            ->assertSee('NGÂN HÀNG THƯƠNG MẠI CỔ PHẦN KỸ THƯƠNG VIỆT NAM')
            ->assertSee('Khối Quản trị Nguồn nhân lực – Phòng Đào tạo & Phát triển')
            ->assertSee('BÁO CÁO HIỆU QUẢ CHƯƠNG TRÌNH ĐÀO TẠO')
            ->assertSee('Kỳ báo cáo: 22/09/2026 – 21/10/2026', false)
            ->assertSee('Tổng 3 nhân viên đào tạo')
            ->assertSee('Hoàn thành đúng hạn: 2 (50%)')
            ->assertSeeInOrder(['STT', 'Chương trình đào tạo', 'Đơn vị', 'Số NV', 'Hoàn thành đúng hạn', 'Điểm KT TB', 'Tỷ lệ', 'Đánh giá'])
            ->assertSeeInOrder(['An toàn thông tin', 'Khối Vận hành', '75,0', '50%', 'Cần cải thiện'])
            ->assertSee('class="al-center tone-great b">Xuất sắc</td>', false)
            ->assertSeeInOrder(['Tổng cộng', '4', '2', '50%', '63,3', '33%'])
            ->assertSee('Người lập báo cáo')
            ->assertSee('Phạm Ngọc Mai Anh')
            ->assertSee('Hà Nội, ngày 21 tháng 10 năm 2026')
            ->assertSee('In / Lưu PDF')
            ->assertSee('Tải Excel theo mẫu')
            ->assertSee('@page { size: A4 landscape', false);
    }

    public function test_trang_in_nhan_bo_loc_tu_query_string(): void
    {
        $this->get(route('admin.reports.template', ['report' => 'tien-do-hoc-tap', 'don-vi' => $this->parent->id]))
            ->assertOk()
            ->assertSee('Đơn vị: Khối Vận hành')
            ->assertSee('Phòng Thẻ')
            ->assertDontSee('Chi nhánh Hà Nội');
    }

    public function test_nut_xuat_mau_mang_theo_bo_loc_hien_tai(): void
    {
        $component = Livewire::test(LearningProgressReport::class)
            ->set('department', (string) $this->parent->id)
            ->assertSee('Xuất báo cáo')
            ->assertDontSee('Xuất báo cáo (mẫu)');

        $url = $component->instance()->templateUrl();
        $this->assertStringContainsString('/bao-cao/mau/tien-do-hoc-tap', $url);
        $this->assertStringContainsString('don-vi=' . $this->parent->id, $url);
    }

    public function test_cac_bao_cao_khac_dung_chung_khung_mau(): void
    {
        $titles = [
            'tong-quan' => 'BÁO CÁO TỔNG QUAN ĐÀO TẠO',
            'ket-qua-kiem-tra' => 'BÁO CÁO KẾT QUẢ KIỂM TRA',
            'truy-cap-tai-lieu' => 'BÁO CÁO TRUY CẬP VÀ SỬ DỤNG TÀI LIỆU',
            'bao-mat-dang-nhap' => 'BÁO CÁO BẢO MẬT VÀ ĐĂNG NHẬP',
            'yeu-cau-ho-tro' => 'BÁO CÁO YÊU CẦU HỖ TRỢ',
        ];

        foreach ($titles as $key => $title) {
            $this->get(route('admin.reports.template', ['report' => $key]))
                ->assertOk()
                ->assertSee($title)
                ->assertSee('NGÂN HÀNG THƯƠNG MẠI CỔ PHẦN KỸ THƯƠNG VIỆT NAM')
                ->assertSee('Giám đốc Đào tạo &amp; Phát triển', false);

            $sheet = $this->sheet($this->get(route('admin.reports.template', ['report' => $key, 'dinh-dang' => 'xlsx'])));
            $this->assertStringStartsWith($title, $sheet->getCell('A5')->getValue());
        }

        // Kết quả kiểm tra: 4 dòng NV × bài trong kỳ (3 nhân viên), 1 dòng đã đạt lần đầu ở bài A + 1 ở bài B
        $this->get(route('admin.reports.template', ['report' => 'ket-qua-kiem-tra']))
            ->assertSee('Tổng 4 lượt NV × bài (3 nhân viên)')
            ->assertSee('Đạt lần đầu: 2 (50%)');

        // Tổng quan in khổ dọc
        $this->get(route('admin.reports.template', ['report' => 'tong-quan']))
            ->assertSee('@page { size: A4 portrait', false)
            ->assertSee('Số lượt theo trạng thái');
    }

    // ---- Quyền -------------------------------------------------------------------

    public function test_thieu_quyen_xem_bao_cao_thi_403(): void
    {
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo('report_quiz.view');
        $this->actingAs($viewer);

        $this->get(route('admin.reports.template', ['report' => 'tien-do-hoc-tap']))->assertForbidden();
        $this->get(route('admin.reports.template', ['report' => 'tien-do-hoc-tap', 'dinh-dang' => 'xlsx']))->assertForbidden();
        $this->get(route('admin.reports.template', ['report' => 'tong-quan']))->assertForbidden();
        $this->get(route('admin.reports.template', ['report' => 'ket-qua-kiem-tra']))->assertOk();
        $this->get('/quan-tri/bao-cao/mau/khong-ton-tai')->assertNotFound();
    }

    // ---- Excel theo mẫu ------------------------------------------------------------

    public function test_excel_theo_mau_co_dau_trang_header_hai_tang_va_dong_tong(): void
    {
        $sheet = $this->sheet($this->get(route('admin.reports.template', ['report' => 'tien-do-hoc-tap', 'dinh-dang' => 'xlsx'])));
        $merges = array_keys($sheet->getMergeCells());

        $this->assertSame('NGÂN HÀNG THƯƠNG MẠI CỔ PHẦN KỸ THƯƠNG VIỆT NAM', $sheet->getCell('A1')->getValue());
        $this->assertSame('C00016', $sheet->getStyle('A1')->getFont()->getColor()->getRGB());
        $this->assertSame('BÁO CÁO HIỆU QUẢ CHƯƠNG TRÌNH ĐÀO TẠO', $sheet->getCell('A5')->getValue());
        $this->assertStringContainsString('Tổng 3 nhân viên đào tạo', $sheet->getCell('A8')->getValue());

        // Header 2 tầng: "Hoàn thành đúng hạn" gộp E10:F10, cột đơn gộp dọc 2 dòng; nền đỏ chữ trắng
        $this->assertContains('E10:F10', $merges);
        $this->assertContains('A10:A11', $merges);
        $this->assertSame('Hoàn thành đúng hạn', $sheet->getCell('E10')->getValue());
        $this->assertSame('Số NV', $sheet->getCell('E11')->getValue());
        $this->assertSame('Tỷ lệ', $sheet->getCell('F11')->getValue());
        $this->assertSame('C00016', $sheet->getStyle('E10')->getFill()->getStartColor()->getRGB());
        $this->assertSame('FFFFFF', $sheet->getStyle('E10')->getFont()->getColor()->getRGB());

        // Dữ liệu là số thật (định dạng %), tô màu theo ngưỡng
        $this->assertSame('An toàn thông tin', $sheet->getCell('B13')->getValue());
        $this->assertSame('Khối Vận hành', $sheet->getCell('C13')->getValue());
        $this->assertEqualsWithDelta(75.0, $sheet->getCell('G13')->getValue(), 0.001);
        $this->assertEqualsWithDelta(0.5, $sheet->getCell('F13')->getValue(), 0.0001);
        $this->assertSame('0%', $sheet->getStyle('F13')->getNumberFormat()->getFormatCode());
        $this->assertSame('C81E1E', $sheet->getStyle('F12')->getFont()->getColor()->getRGB());
        $this->assertSame('Xuất sắc', $sheet->getCell('J14')->getValue());

        // Dòng Tổng cộng gộp 3 ô đầu, chân ký bên dưới
        $this->assertContains('A15:C15', $merges);
        $this->assertSame('Tổng cộng', $sheet->getCell('A15')->getValue());
        $this->assertEquals(4, $sheet->getCell('D15')->getValue());

        $values = collect($sheet->toArray(null, false, false, false))->flatten()->filter()->values();
        $this->assertContains('Người lập báo cáo', $values);
        $this->assertContains('Phạm Ngọc Mai Anh', $values);

        // Khổ A4 ngang, lặp 2 dòng tiêu đề bảng mỗi trang
        $this->assertSame('landscape', $sheet->getPageSetup()->getOrientation());
        $this->assertEquals([10, 11], $sheet->getPageSetup()->getRowsToRepeatAtTop());
    }
}
