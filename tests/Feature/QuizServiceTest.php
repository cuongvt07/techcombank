<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\QuizService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** Kiểm chứng luồng làm bài và chấm điểm ở spec 3.2.3. */
class QuizServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuizService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QuizService::class);
    }

    public function test_cham_diem_dung_va_danh_dau_dat(): void
    {
        $quiz = Quiz::factory()->create(['pass_score' => 70]);
        [$q1, $q2] = $this->makeQuestions($quiz, 2);
        $employee = Employee::factory()->create();

        $attempt = $this->service->start($quiz, $employee);
        $this->service->saveAnswer($attempt, $q1->id, [$this->correctOptionId($q1)]);
        $this->service->saveAnswer($attempt, $q2->id, [$this->correctOptionId($q2)]);
        $result = $this->service->submit($attempt);

        $this->assertEquals(100.00, (float) $result->percentage);
        $this->assertTrue($result->is_passed);
        $this->assertSame(QuizAttempt::STATUS_GRADED, $result->status);
    }

    public function test_khong_dat_diem_san_thi_danh_dau_truot(): void
    {
        $quiz = Quiz::factory()->create(['pass_score' => 70]);
        [$q1, $q2] = $this->makeQuestions($quiz, 2);
        $employee = Employee::factory()->create();

        $attempt = $this->service->start($quiz, $employee);
        $this->service->saveAnswer($attempt, $q1->id, [$this->correctOptionId($q1)]);
        $this->service->saveAnswer($attempt, $q2->id, [$this->wrongOptionId($q2)]);
        $result = $this->service->submit($attempt);

        $this->assertEquals(50.00, (float) $result->percentage);
        $this->assertFalse($result->is_passed);
    }

    public function test_cau_chon_nhieu_dap_an_phai_khop_tron_bo(): void
    {
        $quiz = Quiz::factory()->create(['pass_score' => 100]);
        $question = Question::factory()->multiple()->create(['quiz_id' => $quiz->id]);
        $correctA = QuestionOption::create(['question_id' => $question->id, 'content' => 'A', 'is_correct' => true]);
        $correctB = QuestionOption::create(['question_id' => $question->id, 'content' => 'B', 'is_correct' => true]);
        QuestionOption::create(['question_id' => $question->id, 'content' => 'C', 'is_correct' => false]);

        $employee = Employee::factory()->create();

        // Chọn thiếu một đáp án đúng => không được tính điểm từng phần
        $attempt = $this->service->start($quiz, $employee);
        $this->service->saveAnswer($attempt, $question->id, [$correctA->id]);
        $partial = $this->service->submit($attempt);
        $this->assertEquals(0.00, (float) $partial->percentage);

        // Chọn đủ cả hai => đúng
        $attempt2 = $this->service->start($quiz, $employee);
        $this->service->saveAnswer($attempt2, $question->id, [$correctA->id, $correctB->id]);
        $full = $this->service->submit($attempt2);
        $this->assertEquals(100.00, (float) $full->percentage);
    }

    public function test_chan_khi_het_so_lan_thi_lai(): void
    {
        $quiz = Quiz::factory()->create(['max_attempts' => 2]);
        $this->makeQuestions($quiz, 1);
        $employee = Employee::factory()->create();

        $this->service->submit($this->service->start($quiz, $employee));
        $this->service->submit($this->service->start($quiz, $employee));

        $this->expectException(RuntimeException::class);
        $this->service->start($quiz, $employee);
    }

    public function test_khong_gioi_han_so_lan_khi_max_attempts_null(): void
    {
        $quiz = Quiz::factory()->create(['max_attempts' => null]);
        $this->makeQuestions($quiz, 1);
        $employee = Employee::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->service->submit($this->service->start($quiz, $employee));
        }

        $this->assertSame(4, $quiz->attemptCountFor($employee));
    }

    public function test_luot_thi_qua_gio_duoc_dong_tu_dong(): void
    {
        $quiz = Quiz::factory()->create(['duration_minutes' => 30]);
        $this->makeQuestions($quiz, 1);
        $employee = Employee::factory()->create();

        $attempt = $this->service->start($quiz, $employee);
        // Đẩy mốc hết giờ về quá khứ để mô phỏng lượt thi bị bỏ dở
        $attempt->update(['expires_at' => now()->subMinute()]);

        $closed = $this->service->closeExpiredAttempts();

        $this->assertSame(1, $closed);
        $this->assertSame(QuizAttempt::STATUS_GRADED, $attempt->refresh()->status);
    }

    public function test_de_thi_duoc_dong_bang_khi_bat_dau(): void
    {
        $quiz = Quiz::factory()->create();
        $questions = $this->makeQuestions($quiz, 3);
        $employee = Employee::factory()->create();

        $attempt = $this->service->start($quiz, $employee);

        // Toàn bộ câu của lượt thi được ghi sẵn kèm snapshot nội dung
        $this->assertSame(3, $attempt->answers()->count());
        $this->assertSame(
            $questions[0]->content,
            $attempt->answers()->where('question_id', $questions[0]->id)->value('question_snapshot')
        );
    }

    public function test_dat_bai_kiem_tra_thi_hoan_thanh_bai_hoc_tuong_ung(): void
    {
        $course = \App\Models\Course::factory()->create();
        $quiz = Quiz::factory()->create(['course_id' => $course->id, 'pass_score' => 50]);
        $question = $this->makeQuestions($quiz, 1)[0];
        $lesson = Lesson::factory()->quiz()->create([
            'course_id' => $course->id,
            'quiz_id' => $quiz->id,
        ]);

        $employee = Employee::factory()->create();
        $enrollment = Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => 1,
        ]);

        $attempt = $this->service->start($quiz, $employee, $lesson);
        $this->service->saveAnswer($attempt, $question->id, [$this->correctOptionId($question)]);
        $this->service->submit($attempt);

        $progress = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        $this->assertNotNull($progress);
        $this->assertSame(LessonProgress::STATUS_COMPLETED, $progress->status);
        $this->assertSame(Enrollment::STATUS_COMPLETED, $enrollment->refresh()->status);
    }

    /** @return \App\Models\Question[] */
    private function makeQuestions(Quiz $quiz, int $count): array
    {
        $questions = [];

        for ($i = 0; $i < $count; $i++) {
            $question = Question::factory()->create(['quiz_id' => $quiz->id, 'sort_order' => $i]);
            QuestionOption::create(['question_id' => $question->id, 'content' => 'Đúng', 'is_correct' => true]);
            QuestionOption::create(['question_id' => $question->id, 'content' => 'Sai', 'is_correct' => false]);
            $questions[] = $question;
        }

        return $questions;
    }

    private function correctOptionId(Question $question): int
    {
        return $question->options()->where('is_correct', true)->value('id');
    }

    private function wrongOptionId(Question $question): int
    {
        return $question->options()->where('is_correct', false)->value('id');
    }
}
