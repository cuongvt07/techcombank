<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\User\LearningProgress;
use App\Livewire\User\QuizPlayer;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\QuizService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Xem lại đáp án theo cấu hình, kết quả chỉ lấy lượt cao nhất, trang chứng nhận
 * chỉ chủ sở hữu xem được, ẩn trợ lý AI ở trang làm bài.
 */
class QuizReviewAndCertificateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        [$this->user, $this->employee] = $this->makeEmployee();
        $this->actingAs($this->user);
    }

    // ---- Xem lại đáp án -----------------------------------------------------

    public function test_bat_xem_lai_dap_an_thi_man_ket_qua_co_dap_an_dung(): void
    {
        [$course, $lesson, $quiz] = $this->makeQuizLesson(showAnswers: true);
        $this->enroll($course);

        $component = $this->answerAllWrongAndSubmit($course, $lesson, $quiz);

        $component->assertSee('Xem lại bài làm')
            ->assertSee('Đáp án ĐÚNG số 1')
            ->assertSee('(bạn chọn)')
            ->assertSee('Giải thích cho câu 1');

        $review = app(QuizService::class)->review(QuizAttempt::first());
        $this->assertNotNull($review);
        $this->assertFalse($review[0]['is_correct']);
        $this->assertTrue(collect($review[0]['options'])->firstWhere('correct', true)['content'] === 'Đáp án ĐÚNG số 1');
    }

    public function test_tat_xem_lai_dap_an_thi_khong_lo_dap_an(): void
    {
        [$course, $lesson, $quiz] = $this->makeQuizLesson(showAnswers: false);
        $this->enroll($course);

        $component = $this->answerAllWrongAndSubmit($course, $lesson, $quiz);

        $component->assertDontSee('Xem lại bài làm')
            ->assertDontSee('Đáp án ĐÚNG số 1')
            ->assertDontSee('Giải thích cho câu 1')
            ->assertSee('không được công bố');

        // Không có đường nào lấy được dữ liệu đáp án khi cấu hình tắt
        $this->assertNull(app(QuizService::class)->review(QuizAttempt::first()));
    }

    public function test_dang_lam_bai_thi_khong_co_du_lieu_xem_lai(): void
    {
        [$course, $lesson, $quiz] = $this->makeQuizLesson(showAnswers: true);
        $this->enroll($course);

        Livewire::test(QuizPlayer::class, ['course' => $course, 'lesson' => $lesson])
            ->call('startAttempt')
            ->assertDontSee('Giải thích cho câu 1');

        $this->assertNull(app(QuizService::class)->review(QuizAttempt::first()));
    }

    public function test_roi_tab_khi_dang_lam_bai_duoc_ghi_nhan(): void
    {
        [$course, $lesson, $quiz] = $this->makeQuizLesson(showAnswers: false);
        $this->enroll($course);

        Livewire::test(QuizPlayer::class, ['course' => $course, 'lesson' => $lesson])
            ->call('startAttempt')
            ->call('recordTabLeave')
            ->call('recordTabLeave');

        $this->assertSame(2, QuizAttempt::first()->tab_leave_count);
    }

    // ---- Kết quả bài kiểm tra: chỉ lượt cao nhất -----------------------------

    public function test_ket_qua_chi_hien_luot_diem_cao_nhat_moi_bai(): void
    {
        [$course, , $quiz] = $this->makeQuizLesson(showAnswers: false);
        $this->enroll($course);

        foreach ([[1, 40], [2, 85], [3, 60]] as [$no, $percent]) {
            QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'employee_id' => $this->employee->id,
                'attempt_no' => $no,
                'status' => QuizAttempt::STATUS_GRADED,
                'score' => $percent / 10,
                'max_score' => 10,
                'percentage' => $percent,
                'is_passed' => $percent >= 70,
                'started_at' => now()->subHours(4 - $no),
                'submitted_at' => now()->subHours(4 - $no)->addMinutes(10),
            ]);
        }

        $component = Livewire::test(LearningProgress::class)
            ->assertSee('85%')
            ->assertSee('Lần 2')
            ->assertSee('3 lượt đã làm')
            ->assertDontSee('40%')
            ->assertDontSee('60%');

        $results = $component->viewData('quizResults');
        $this->assertCount(1, $results);
        $this->assertSame(2, $results[0]['attempt']->attempt_no);
    }

    // ---- Trang chứng nhận ---------------------------------------------------

    public function test_chu_so_huu_xem_duoc_trang_chung_nhan(): void
    {
        $certificate = $this->makeCertificate($this->employee);

        $this->get(route('learn.certificate', $certificate))
            ->assertOk()
            ->assertSee('Chứng Nhận Hoàn Thành')
            ->assertSee($this->employee->full_name)
            ->assertSee($certificate->certificate_no)
            ->assertSee($certificate->verification_code)
            ->assertSee('92,5 điểm')
            ->assertSee('In / Lưu PDF');
    }

    public function test_nguoi_khac_khong_xem_duoc_chung_nhan(): void
    {
        [, $other] = $this->makeEmployee();
        $certificate = $this->makeCertificate($other);

        $this->get(route('learn.certificate', $certificate))->assertForbidden();
    }

    public function test_danh_sach_chung_nhan_mo_trang_rieng(): void
    {
        $certificate = $this->makeCertificate($this->employee);

        Livewire::test(LearningProgress::class)
            ->assertSee(route('learn.certificate', $certificate), false);
    }

    // ---- Trợ lý AI ----------------------------------------------------------

    public function test_tro_ly_ai_khong_hien_o_trang_lam_bai(): void
    {
        [$course, $lesson] = $this->makeQuizLesson(showAnswers: false);
        $this->enroll($course);

        $this->get(route('learn.quiz', [$course, $lesson]))
            ->assertOk()
            ->assertDontSee('Mở trợ lý AI');

        // Trang khác vẫn có trợ lý
        $this->get(route('learn.progress'))
            ->assertOk()
            ->assertSee('Mở trợ lý AI');
    }

    // ---- Trợ giúp -----------------------------------------------------------

    /** @return array{0: User, 1: Employee} */
    private function makeEmployee(): array
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole(RoleName::EMPLOYEE->value);
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        return [$user->refresh(), $employee];
    }

    private function makeQuizLesson(bool $showAnswers): array
    {
        $course = Course::factory()->create();
        $quiz = Quiz::factory()->create([
            'course_id' => $course->id,
            'pass_score' => 70,
            'show_correct_answers' => $showAnswers,
            'shuffle_questions' => false,
        ]);

        foreach (range(1, 2) as $i) {
            $question = Question::factory()->create([
                'quiz_id' => $quiz->id,
                'sort_order' => $i,
                'explanation' => "Giải thích cho câu {$i}",
            ]);
            QuestionOption::create(['question_id' => $question->id, 'content' => "Đáp án ĐÚNG số {$i}", 'is_correct' => true]);
            QuestionOption::create(['question_id' => $question->id, 'content' => "Đáp án sai số {$i}", 'is_correct' => false]);
        }

        $lesson = Lesson::factory()->quiz()->create([
            'course_id' => $course->id,
            'quiz_id' => $quiz->id,
            'sort_order' => 0,
        ]);

        return [$course, $lesson, $quiz];
    }

    private function answerAllWrongAndSubmit(Course $course, Lesson $lesson, Quiz $quiz)
    {
        $component = Livewire::test(QuizPlayer::class, ['course' => $course, 'lesson' => $lesson])
            ->call('startAttempt');

        foreach ($quiz->questions as $question) {
            $wrongId = $question->options()->where('is_correct', false)->value('id');
            $component->call('selectOption', $question->id, $wrongId, false);
        }

        return $component->call('submit');
    }

    private function enroll(Course $course, ?Employee $employee = null): Enrollment
    {
        return Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => ($employee ?? $this->employee)->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => $course->requiredLessonCount(),
        ]);
    }

    private function makeCertificate(Employee $owner): Certificate
    {
        $course = Course::factory()->withCertificate()->create(['title' => 'Khóa Có Chứng Nhận FB6']);
        $enrollment = $this->enroll($course, $owner);
        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);

        return Certificate::create([
            'certificate_no' => 'CERT-FB6-' . str_pad((string) $owner->id, 3, '0', STR_PAD_LEFT),
            'enrollment_id' => $enrollment->id,
            'employee_id' => $owner->id,
            'course_id' => $course->id,
            'score' => 92.5,
            'issued_at' => now(),
            'verification_code' => 'verifyfb6code' . $owner->id,
        ]);
    }
}
