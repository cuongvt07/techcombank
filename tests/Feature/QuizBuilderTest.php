<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\QuizBuilder;
use App\Models\Employee;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Kiểm chứng màn hình soạn bài kiểm tra (spec 3.2.3). */
class QuizBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin->refresh());
    }

    public function test_tao_bai_kiem_tra_moi(): void
    {
        Livewire::test(QuizBuilder::class)
            ->call('createQuiz')
            ->set('title', 'Kiểm tra An toàn thông tin')
            ->set('pass_score', 80)
            ->set('duration_minutes', 30)
            ->call('saveQuiz')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quizzes', [
            'title' => 'Kiểm tra An toàn thông tin',
            'pass_score' => 80,
            'status' => Quiz::STATUS_DRAFT,
        ]);
    }

    public function test_them_cau_hoi_chon_mot_dap_an(): void
    {
        $quiz = Quiz::factory()->create();

        Livewire::test(QuizBuilder::class, ['editingQuizId' => $quiz->id])
            ->set('editingQuizId', $quiz->id)
            ->call('createQuestion')
            ->set('questionContent', 'Thủ đô Việt Nam?')
            ->set('options.0.content', 'Hà Nội')
            ->set('options.1.content', 'Huế')
            ->call('markCorrect', 0)
            ->call('saveQuestion')
            ->assertHasNoErrors();

        $question = $quiz->questions()->first();

        $this->assertSame('Thủ đô Việt Nam?', $question->content);
        $this->assertSame(2, $question->options()->count());
        $this->assertSame('Hà Nội', $question->options()->where('is_correct', true)->value('content'));
    }

    public function test_chon_dap_an_dung_moi_thi_bo_dap_an_cu_o_cau_chon_mot(): void
    {
        $quiz = Quiz::factory()->create();

        $component = Livewire::test(QuizBuilder::class)
            ->set('editingQuizId', $quiz->id)
            ->call('createQuestion')
            ->call('markCorrect', 0)
            ->call('markCorrect', 1);

        $options = $component->get('options');

        // Câu chọn 1 đáp án: chỉ được đúng một ô là đáp án đúng
        $this->assertFalse($options[0]['is_correct']);
        $this->assertTrue($options[1]['is_correct']);
    }

    public function test_cau_chon_nhieu_giu_duoc_nhieu_dap_an_dung(): void
    {
        $quiz = Quiz::factory()->create();

        $component = Livewire::test(QuizBuilder::class)
            ->set('editingQuizId', $quiz->id)
            ->call('createQuestion')
            ->set('questionType', Question::TYPE_MULTIPLE)
            ->call('markCorrect', 0)
            ->call('markCorrect', 1);

        $options = $component->get('options');

        $this->assertTrue($options[0]['is_correct']);
        $this->assertTrue($options[1]['is_correct']);
    }

    public function test_bao_loi_khi_khong_chon_dap_an_dung(): void
    {
        $quiz = Quiz::factory()->create();

        Livewire::test(QuizBuilder::class)
            ->set('editingQuizId', $quiz->id)
            ->call('createQuestion')
            ->set('questionContent', 'Câu hỏi không có đáp án đúng')
            ->set('options.0.content', 'A')
            ->set('options.0.is_correct', false)
            ->set('options.1.content', 'B')
            ->set('options.1.is_correct', false)
            ->call('saveQuestion')
            ->assertHasErrors('options');

        $this->assertSame(0, $quiz->questions()->count());
    }

    public function test_bao_loi_khi_dap_an_de_trong(): void
    {
        $quiz = Quiz::factory()->create();

        Livewire::test(QuizBuilder::class)
            ->set('editingQuizId', $quiz->id)
            ->call('createQuestion')
            ->set('questionContent', 'Câu hỏi thiếu nội dung đáp án')
            ->set('options.0.content', '')
            ->set('options.1.content', 'B')
            ->call('saveQuestion')
            ->assertHasErrors('options.0.content');
    }

    public function test_doi_sang_loai_dung_sai_thi_tu_dien_hai_dap_an(): void
    {
        $quiz = Quiz::factory()->create();

        $component = Livewire::test(QuizBuilder::class)
            ->set('editingQuizId', $quiz->id)
            ->call('createQuestion')
            ->set('questionType', Question::TYPE_TRUE_FALSE);

        $options = $component->get('options');

        $this->assertCount(2, $options);
        $this->assertSame('Đúng', $options[0]['content']);
        $this->assertSame('Sai', $options[1]['content']);
    }

    public function test_doi_tu_chon_nhieu_ve_chon_mot_thi_chi_giu_mot_dap_an_dung(): void
    {
        $quiz = Quiz::factory()->create();

        $component = Livewire::test(QuizBuilder::class)
            ->set('editingQuizId', $quiz->id)
            ->call('createQuestion')
            ->set('questionType', Question::TYPE_MULTIPLE)
            ->call('markCorrect', 0)
            ->call('markCorrect', 1)
            ->set('questionType', Question::TYPE_SINGLE);

        $correct = collect($component->get('options'))->where('is_correct', true);

        $this->assertCount(1, $correct, 'Đổi loại phải đồng bộ lại đáp án đúng');
    }

    public function test_sua_cau_hoi_ghi_lai_toan_bo_dap_an(): void
    {
        $quiz = Quiz::factory()->create();
        $question = Question::factory()->create(['quiz_id' => $quiz->id]);
        QuestionOption::create(['question_id' => $question->id, 'content' => 'Cũ A', 'is_correct' => true]);
        QuestionOption::create(['question_id' => $question->id, 'content' => 'Cũ B', 'is_correct' => false]);

        Livewire::test(QuizBuilder::class)
            ->set('editingQuizId', $quiz->id)
            ->call('editQuestion', $question->id)
            ->set('options.0.content', 'Mới A')
            ->set('options.1.content', 'Mới B')
            ->call('saveQuestion')
            ->assertHasNoErrors();

        $contents = $question->refresh()->options->pluck('content')->all();

        $this->assertSame(['Mới A', 'Mới B'], $contents);
        $this->assertSame(2, $question->options()->count(), 'Không được để lại đáp án mồ côi');
    }

    public function test_khong_xuat_ban_duoc_bai_chua_co_cau_hoi(): void
    {
        $quiz = Quiz::factory()->create(['status' => Quiz::STATUS_DRAFT]);

        Livewire::test(QuizBuilder::class)->call('publishQuiz', $quiz->id);

        $this->assertSame(Quiz::STATUS_DRAFT, $quiz->refresh()->status);
    }

    public function test_khong_xuat_ban_duoc_khi_rut_nhieu_cau_hon_ngan_hang(): void
    {
        $quiz = Quiz::factory()->create([
            'status' => Quiz::STATUS_DRAFT,
            'questions_per_attempt' => 10,
        ]);
        Question::factory()->count(3)->create(['quiz_id' => $quiz->id]);

        Livewire::test(QuizBuilder::class)->call('publishQuiz', $quiz->id);

        // Rút 10 câu từ ngân hàng 3 câu là cấu hình không chạy được
        $this->assertSame(Quiz::STATUS_DRAFT, $quiz->refresh()->status);
    }

    public function test_xuat_ban_khi_du_cau_hoi(): void
    {
        $quiz = Quiz::factory()->create(['status' => Quiz::STATUS_DRAFT]);
        Question::factory()->count(5)->create(['quiz_id' => $quiz->id]);

        Livewire::test(QuizBuilder::class)->call('publishQuiz', $quiz->id);

        $this->assertSame(Quiz::STATUS_PUBLISHED, $quiz->refresh()->status);
    }

    public function test_tat_cau_hoi_thi_khong_tinh_vao_ngan_hang(): void
    {
        $quiz = Quiz::factory()->create();
        $question = Question::factory()->create(['quiz_id' => $quiz->id, 'is_active' => true]);

        Livewire::test(QuizBuilder::class)
            ->set('editingQuizId', $quiz->id)
            ->call('toggleQuestionActive', $question->id);

        $this->assertFalse($question->refresh()->is_active);
        $this->assertSame(0, $quiz->activeQuestions()->count());
    }

    public function test_xoa_cau_hoi(): void
    {
        $quiz = Quiz::factory()->create();
        $question = Question::factory()->create(['quiz_id' => $quiz->id]);

        Livewire::test(QuizBuilder::class)
            ->set('editingQuizId', $quiz->id)
            ->call('deleteQuestion', $question->id);

        $this->assertSoftDeleted('questions', ['id' => $question->id]);
    }

    public function test_quan_tri_vien_vao_duoc_man_hinh_bai_kiem_tra(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin->refresh())
            ->get(route('admin.quizzes'))
            ->assertOk();
    }

    public function test_nhan_vien_khong_vao_duoc_man_hinh_bai_kiem_tra(): void
    {
        $employee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $employee->assignRole(RoleName::EMPLOYEE->value);
        Employee::factory()->create(['user_id' => $employee->id]);

        // Bị chuyển về site học tập, không phải 403 (xem EnsureUserIsAdmin)
        $this->actingAs($employee->refresh())
            ->get(route('admin.quizzes'))
            ->assertRedirect(route('learn.events'));
    }
}
