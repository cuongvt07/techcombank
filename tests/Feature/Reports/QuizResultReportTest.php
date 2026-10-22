<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleName;
use App\Livewire\Admin\Reports\QuizResultReport;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptAnswer;
use App\Models\QuizAttemptGrant;
use App\Models\User;
use App\Services\Reports\QuizResultReportService;
use App\Support\Reports\ReportPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Báo cáo kết quả kiểm tra: KPI, lượt đến khi đạt, cấp thêm lượt, gửi nhắc, cờ chất lượng, xuất file. */
class QuizResultReportTest extends TestCase
{
    use RefreshDatabase;

    private Quiz $quiz;
    private Department $deptA;
    private Department $deptB;

    /** @var array<string, Employee> */
    private array $emp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin->refresh());

        $this->deptA = Department::factory()->create(['name' => 'CN Hải Phòng']);
        $this->deptB = Department::factory()->create(['name' => 'Khối Công nghệ']);
        $this->quiz = Quiz::factory()->create(['title' => 'Nhận diện lừa đảo', 'pass_score' => 70, 'max_attempts' => 3]);

        // first: đạt lần 1 · second: đạt lần 2 · third: đạt lần 3 · retry: trượt 1 lần · out: trượt đủ 3 lần
        $plans = [
            'first' => [$this->deptA, [80]],
            'second' => [$this->deptA, [50, 75]],
            'third' => [$this->deptB, [40, 60, 90]],
            'retry' => [$this->deptB, [65]],
            'out' => [$this->deptA, [45, 60, 60]],
        ];

        foreach ($plans as $key => [$dept, $scores]) {
            $this->emp[$key] = Employee::factory()->create(['department_id' => $dept->id]);
            foreach ($scores as $i => $pct) {
                $this->attempt($this->quiz, $this->emp[$key], $i + 1, $pct, now()->subDays(5 - $i));
            }
        }
    }

    private function attempt(Quiz $quiz, Employee $employee, int $no, float $pct, $submittedAt, string $status = QuizAttempt::STATUS_GRADED): QuizAttempt
    {
        return QuizAttempt::create([
            'quiz_id' => $quiz->id, 'employee_id' => $employee->id, 'attempt_no' => $no,
            'status' => $status, 'score' => $pct, 'max_score' => 100, 'percentage' => $pct,
            'is_passed' => $pct >= $quiz->pass_score, 'started_at' => $submittedAt, 'submitted_at' => $submittedAt,
        ]);
    }

    private function service(): QuizResultReportService
    {
        return app(QuizResultReportService::class);
    }

    private function key(string $who): string
    {
        return $this->emp[$who]->id . ':' . $this->quiz->id;
    }

    public function test_kpi_va_luot_den_khi_dat(): void
    {
        $kpi = $this->service()->pairKpis([], ReportPeriod::make('30d'));

        $this->assertSame(5, $kpi['total']);
        $this->assertSame(3, $kpi['passed']);
        $this->assertSame(1, $kpi['retry']);
        $this->assertSame(1, $kpi['exhausted']);
        $this->assertSame([1, 1, 1], [$kpi['pass1'], $kpi['pass2'], $kpi['pass3']]);
        $this->assertEqualsWithDelta(2.0, $kpi['avg_pass_no'], 0.001);   // (1 + 2 + 3) / 3
        $this->assertEqualsWithDelta(20.0, $kpi['first_pass_rate'], 0.001);
        $this->assertNull($kpi['prev_first_pass_rate']);
    }

    public function test_luot_ngoai_ky_va_luot_chua_nop_khong_tinh(): void
    {
        $other = Employee::factory()->create();
        $this->attempt($this->quiz, $other, 1, 95, now()->subDays(40));
        QuizAttempt::create([
            'quiz_id' => $this->quiz->id, 'employee_id' => $other->id, 'attempt_no' => 2,
            'status' => QuizAttempt::STATUS_IN_PROGRESS, 'started_at' => now(),
        ]);

        $this->assertSame(5, $this->service()->pairKpis([], ReportPeriod::make('30d'))['total']);
        // Kỳ trước có một người đạt lần đầu → delta có số so sánh
        $this->assertEqualsWithDelta(100.0, $this->service()->pairKpis([], ReportPeriod::make('30d'))['prev_first_pass_rate'], 0.001);
    }

    public function test_bang_xep_het_luot_len_dau_va_loc_theo_don_vi(): void
    {
        $period = ReportPeriod::make('30d');
        $rows = $this->service()->employeeTable([], $period)->get();

        $this->assertSame($this->emp['out']->id, (int) $rows->first()->employee_id);
        $this->assertSame('exhausted', $rows->first()->final_result);
        $this->assertSame(0, (int) $rows->first()->remaining);
        $this->assertSame('retry', $rows[1]->final_result);

        $out = $rows->first();
        $this->assertEquals(45, $out->first_pct);
        $this->assertEquals(60, $out->best_pct);

        $third = $rows->firstWhere('employee_id', $this->emp['third']->id);
        $this->assertSame(3, (int) $third->pass_no);
        $this->assertNotNull($third->passed_at);

        $deptRows = $this->service()->employeeTable(['department_id' => $this->deptB->id], $period)->get();
        $this->assertCount(2, $deptRows);

        $byDept = $this->service()->passRateByDepartment([], $period)->keyBy('department_id');
        $this->assertEqualsWithDelta(200 / 3, $byDept[$this->deptA->id]->rate, 0.01);
        $this->assertEqualsWithDelta(50.0, $byDept[$this->deptB->id]->rate, 0.01);
        // Thấp nhất xếp trên
        $this->assertSame($this->deptB->id, (int) $this->service()->passRateByDepartment([], $period)->first()->department_id);

        $onlyExhausted = $this->service()->employeeTable(['result' => 'exhausted'], $period)->get();
        $this->assertCount(1, $onlyExhausted);
    }

    public function test_cap_them_luot_lam_cho_phep_lam_tiep(): void
    {
        $out = $this->emp['out'];
        $this->assertFalse($this->quiz->canAttempt($out));

        Livewire::test(QuizResultReport::class)
            ->set('selected', [$this->key('out'), $this->key('first'), $this->key('retry')])
            ->call('grantSelected')
            ->assertSet('selected', [])
            ->assertSee('Đã cấp thêm 1 lượt làm cho 1 nhân viên');

        // Chỉ người hết lượt được cấp; người đã đạt / còn lượt bị bỏ qua
        $this->assertSame(1, QuizAttemptGrant::count());
        $this->assertTrue($this->quiz->fresh()->canAttempt($out));
        $this->assertSame(4, $this->quiz->fresh()->allowedAttemptsFor($out));

        // Báo cáo chuyển người đó sang "chưa đạt còn lượt"
        $kpi = $this->service()->pairKpis([], ReportPeriod::make('30d'));
        $this->assertSame(0, $kpi['exhausted']);
        $this->assertSame(2, $kpi['retry']);
    }

    public function test_gui_nhac_chi_nguoi_chua_dat_con_luot(): void
    {
        Livewire::test(QuizResultReport::class)->call('remindAllRetry');

        $this->assertSame(1, Announcement::count());
        $this->assertSame($this->emp['retry']->id, Announcement::first()->employee_id);

        Livewire::test(QuizResultReport::class)
            ->set('selected', [$this->key('out'), $this->key('retry')])
            ->call('remindSelected');

        $this->assertSame(2, Announcement::where('employee_id', $this->emp['retry']->id)->count());
        $this->assertSame(0, Announcement::where('employee_id', $this->emp['out']->id)->count());
    }

    public function test_thieu_quyen_bi_chan(): void
    {
        $role = Role::findOrCreate('quiz_viewer', 'web');
        $role->syncPermissions(['report_quiz.view']);
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->assignRole($role);
        $this->actingAs($viewer);

        $this->get(route('admin.reports.quiz'))->assertOk()->assertDontSee('Gửi nhắc làm lại');

        Livewire::test(QuizResultReport::class)
            ->set('selected', [$this->key('out')])
            ->call('grantSelected')
            ->assertForbidden();
        Livewire::test(QuizResultReport::class)->call('remindAllRetry')->assertForbidden();

        $this->assertSame(0, QuizAttemptGrant::count());
        $this->assertSame(0, Announcement::count());

        // Không có quyền sửa bài kiểm tra → không thấy đáp án đúng
        Livewire::test(QuizResultReport::class)
            ->set('tab', 'questions')
            ->assertSee('cần quyền quizzes.update')
            ->assertDontSee('Đáp án đúng');

        // Vào được site quản trị (có quyền Xem màn khác) nhưng không có report_quiz.view
        $noView = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $noView->assignRole(RoleName::HR->value);
        $this->actingAs($noView)->get(route('admin.reports.quiz'))->assertForbidden();
    }

    public function test_phan_tich_cau_hoi_va_co_chat_luong(): void
    {
        $question = Question::create(['quiz_id' => $this->quiz->id, 'content' => 'Email giả mạo xử lý thế nào?', 'type' => Question::TYPE_SINGLE, 'score' => 1, 'sort_order' => 6]);
        $right = QuestionOption::create(['question_id' => $question->id, 'content' => 'Báo bộ phận ANTT', 'is_correct' => true, 'sort_order' => 0]);
        $wrong = QuestionOption::create(['question_id' => $question->id, 'content' => 'Trả lời email để xác minh', 'is_correct' => false, 'sort_order' => 1]);

        // 25 lượt (đủ mẫu): 5 đúng, 15 chọn đáp án sai phổ biến, 5 bỏ trống → 20% đúng
        for ($i = 0; $i < 25; $i++) {
            $emp = Employee::factory()->create();
            $att = $this->attempt($this->quiz, $emp, 1, 50, now()->subDay());
            $sel = $i < 5 ? [$right->id] : ($i < 20 ? [$wrong->id] : []);
            QuizAttemptAnswer::create(['quiz_attempt_id' => $att->id, 'question_id' => $question->id, 'selected_option_ids' => $sel, 'is_correct' => $i < 5]);
        }

        $period = ReportPeriod::make('30d');
        $rows = $this->service()->enrichQuestions($this->service()->questionTable([], $period)->get(), [], $period);
        $row = $rows->firstWhere('id', $question->id);

        $this->assertSame(25, $row->shown);
        $this->assertEqualsWithDelta(20.0, $row->correct_rate, 0.01);
        $this->assertSame('Trả lời email để xác minh', $row->top_wrong['text']);
        $this->assertEqualsWithDelta(60.0, $row->top_wrong['pct'], 0.01);
        $this->assertSame('Quá khó', $row->flag['label']);
        $this->assertSame('Báo bộ phận ANTT', $row->correct_text);

        // Ngưỡng cờ
        $this->assertSame('Chưa đủ mẫu', QuizResultReportService::qualityFlag(10, 19, 'single_choice')['label']);
        $this->assertSame('Rà soát', QuizResultReportService::qualityFlag(40, 50, 'single_choice')['label']);
        $this->assertSame('Rà soát', QuizResultReportService::qualityFlag(30, 50, 'multiple_choice')['label']);
        $this->assertSame('Quá dễ', QuizResultReportService::qualityFlag(97, 50, 'single_choice')['label']);
        $this->assertNull(QuizResultReportService::qualityFlag(70, 50, 'single_choice'));

        // Lọc lần đầu + không đạt: 5 người lần 1 trượt trong setUp (second/third/retry/out ở lượt 1) + 25 lượt mới
        $kpi = $this->service()->attemptKpis(['attempt' => 'first', 'attempt_result' => 'failed'], $period);
        $this->assertSame(29, $kpi['attempts']);

        // Admin có quizzes.update → thấy cột đáp án đúng
        Livewire::test(QuizResultReport::class)
            ->call('setTab', 'questions')
            ->assertSee('Email giả mạo xử lý thế nào?')
            ->assertSee('Quá khó')
            ->assertSee('Đáp án đúng');
    }

    public function test_cau_nhieu_lua_chon_chon_thieu(): void
    {
        $question = Question::create(['quiz_id' => $this->quiz->id, 'content' => 'Chọn mọi dấu hiệu AML', 'type' => Question::TYPE_MULTIPLE, 'score' => 1, 'sort_order' => 0]);
        $opts = collect(range(1, 4))->map(fn ($i) => QuestionOption::create(['question_id' => $question->id, 'content' => "Dấu hiệu {$i}", 'is_correct' => $i <= 3, 'sort_order' => $i]));

        foreach ([[1, 2], [1, 3], [1, 2, 3, 4]] as $i => $picked) {
            $att = $this->attempt($this->quiz, Employee::factory()->create(), 1, 50, now()->subDay());
            QuizAttemptAnswer::create(['quiz_attempt_id' => $att->id, 'question_id' => $question->id,
                'selected_option_ids' => array_map(fn ($n) => $opts[$n - 1]->id, $picked), 'is_correct' => false]);
        }

        $period = ReportPeriod::make('30d');
        $row = $this->service()->enrichQuestions($this->service()->questionTable([], $period)->get(), [], $period)->firstWhere('id', $question->id);

        $this->assertSame('Chọn thiếu 1 trong 3 đáp án đúng', $row->top_wrong['text']);
    }

    public function test_xuat_file_ca_hai_tab(): void
    {
        Livewire::test(QuizResultReport::class)
            ->call('export', 'csv')
            ->assertFileDownloaded();

        Livewire::test(QuizResultReport::class)
            ->call('setTab', 'questions')
            ->call('export', 'xlsx')
            ->assertFileDownloaded();
    }

    public function test_trang_hien_thi_hai_tab(): void
    {
        $this->get(route('admin.reports.quiz'))
            ->assertOk()
            ->assertSee('Báo cáo kết quả kiểm tra')
            ->assertSee('Hết lượt chưa đạt')
            ->assertSee('Số lượt đến khi đạt');

        $this->get(route('admin.reports.quiz', ['tab' => 'questions']))
            ->assertOk()
            ->assertSee('Phân bố điểm')
            ->assertSee('Tỷ lệ đạt theo bài kiểm tra');
    }
}
