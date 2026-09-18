<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\User\CoursePlayer;
use App\Livewire\User\QuizPlayer;
use App\Models\Course;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Kiểm chứng luồng học của nhân viên (spec 4.1 + 4.2). */
class LearningFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->user->assignRole(RoleName::EMPLOYEE->value);
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
        $this->actingAs($this->user->refresh());
    }

    // ---- Kiểm soát truy cập -------------------------------------------------

    public function test_khong_vao_duoc_khoa_chua_duoc_giao(): void
    {
        $course = Course::factory()->create();
        Lesson::factory()->create(['course_id' => $course->id]);

        // Trả 404 thay vì 403 để không lộ sự tồn tại của khóa học nội bộ
        $this->get(route('learn.course', $course))->assertNotFound();
    }

    public function test_vao_duoc_khoa_da_duoc_giao(): void
    {
        [$course] = $this->makeCourseWithLessons();
        $this->enroll($course);

        $this->get(route('learn.course', $course))
            ->assertOk()
            ->assertSee($course->title);
    }

    public function test_khoa_da_huy_thi_khong_vao_duoc(): void
    {
        [$course] = $this->makeCourseWithLessons();
        $enrollment = $this->enroll($course);
        $enrollment->update(['status' => Enrollment::STATUS_CANCELLED]);

        $this->get(route('learn.course', $course))->assertNotFound();
    }

    // ---- Học tuần tự --------------------------------------------------------

    public function test_bai_sau_bi_khoa_cho_den_khi_xong_bai_truoc(): void
    {
        [$course, $lessons] = $this->makeCourseWithLessons(3);
        $enrollment = $this->enroll($course);

        $component = Livewire::test(CoursePlayer::class, ['course' => $course]);
        $unlocked = $component->get('unlockedMap') ?? null;

        // Thử mở bài thứ ba khi chưa học bài nào
        $component->call('openLesson', $lessons[2]->id);
        $this->assertNotSame($lessons[2]->id, $component->get('lesson'), 'Bài bị khóa không được mở');
    }

    public function test_hoan_thanh_bai_thi_mo_bai_tiep_theo(): void
    {
        [$course, $lessons] = $this->makeCourseWithLessons(2);
        $enrollment = $this->enroll($course);

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lessons[0]->id)
            ->call('completeLesson');

        $this->assertDatabaseHas('lesson_progresses', [
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lessons[0]->id,
            'status' => LessonProgress::STATUS_COMPLETED,
        ]);

        // Bài 2 giờ đã mở
        $component = Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lessons[1]->id);

        $this->assertSame($lessons[1]->id, $component->get('lesson'));
    }

    public function test_khoa_hoc_tu_do_thi_mo_duoc_bai_bat_ky(): void
    {
        $course = Course::factory()->freeOrder()->create();
        $lessons = collect(range(0, 2))->map(fn ($i) => Lesson::factory()->create([
            'course_id' => $course->id,
            'sort_order' => $i,
            'content_html' => '<p>Nội dung</p>',
        ]));
        $this->enroll($course);

        $component = Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lessons[2]->id);

        $this->assertSame($lessons[2]->id, $component->get('lesson'));
    }

    public function test_mo_khoa_hoc_thi_nhay_toi_bai_chua_hoan_thanh(): void
    {
        [$course, $lessons] = $this->makeCourseWithLessons(3);
        $enrollment = $this->enroll($course);

        app(\App\Services\ProgressService::class)->completeLesson($enrollment, $lessons[0]);

        $component = Livewire::test(CoursePlayer::class, ['course' => $course]);

        $this->assertSame($lessons[1]->id, $component->get('lesson'), 'Phải mở đúng bài học tiếp theo');
    }

    // ---- Video --------------------------------------------------------------

    public function test_video_chua_dat_nguong_thi_chua_hoan_thanh(): void
    {
        $course = Course::factory()->create();
        $document = Document::factory()->create(['kind' => Document::KIND_VIDEO, 'duration_seconds' => 600]);
        $lesson = Lesson::factory()->video(80)->create([
            'course_id' => $course->id,
            'document_id' => $document->id,
            'sort_order' => 0,
        ]);
        $enrollment = $this->enroll($course);

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id)
            ->call('updateVideoProgress', 300, 50.0);

        $progress = LessonProgress::where('enrollment_id', $enrollment->id)->first();
        $this->assertSame(LessonProgress::STATUS_IN_PROGRESS, $progress->status);
    }

    public function test_xem_du_nguong_thi_video_tu_hoan_thanh(): void
    {
        $course = Course::factory()->create();
        $document = Document::factory()->create(['kind' => Document::KIND_VIDEO, 'duration_seconds' => 600]);
        $lesson = Lesson::factory()->video(80)->create([
            'course_id' => $course->id,
            'document_id' => $document->id,
            'sort_order' => 0,
        ]);
        $enrollment = $this->enroll($course);

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id)
            ->call('updateVideoProgress', 540, 90.0);

        $this->assertDatabaseHas('lesson_progresses', [
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => LessonProgress::STATUS_COMPLETED,
        ]);
    }

    public function test_khong_the_bam_tay_hoan_thanh_bai_video(): void
    {
        $course = Course::factory()->create();
        $document = Document::factory()->create(['kind' => Document::KIND_VIDEO]);
        $lesson = Lesson::factory()->video()->create([
            'course_id' => $course->id,
            'document_id' => $document->id,
            'sort_order' => 0,
        ]);
        $enrollment = $this->enroll($course);

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id)
            ->call('completeLesson');

        $progress = LessonProgress::where('enrollment_id', $enrollment->id)->first();
        $this->assertNotSame(LessonProgress::STATUS_COMPLETED, $progress?->status);
    }

    // ---- Ghi log truy cập ---------------------------------------------------

    public function test_mo_bai_co_tai_lieu_thi_ghi_log_truy_cap(): void
    {
        $course = Course::factory()->create();
        $document = Document::factory()->create();
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'content_type' => Lesson::TYPE_DOCUMENT,
            'document_id' => $document->id,
            'content_html' => null,
            'sort_order' => 0,
        ]);
        $this->enroll($course);

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id);

        $this->assertDatabaseHas('document_access_logs', [
            'document_id' => $document->id,
            'employee_id' => $this->employee->id,
            'action' => DocumentAccessLog::ACTION_VIEW,
        ]);
    }

    // ---- Làm bài kiểm tra ---------------------------------------------------

    public function test_lam_bai_kiem_tra_dat_diem_thi_hoan_thanh_bai_hoc(): void
    {
        [$course, $lesson, $quiz, $questions] = $this->makeQuizLesson();
        $enrollment = $this->enroll($course);

        $component = Livewire::test(QuizPlayer::class, ['course' => $course, 'lesson' => $lesson])
            ->call('startAttempt');

        foreach ($questions as $question) {
            $correctId = $question->options()->where('is_correct', true)->value('id');
            $component->call('selectOption', $question->id, $correctId, false);
        }

        $component->call('submit');

        $attempt = QuizAttempt::where('employee_id', $this->employee->id)->first();
        $this->assertTrue($attempt->is_passed);
        $this->assertEquals(100.0, (float) $attempt->percentage);

        // Đạt điểm thì bài học được đánh dấu hoàn thành
        $this->assertDatabaseHas('lesson_progresses', [
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => LessonProgress::STATUS_COMPLETED,
        ]);
    }

    public function test_truot_bai_kiem_tra_thi_bai_hoc_chua_hoan_thanh(): void
    {
        [$course, $lesson, $quiz, $questions] = $this->makeQuizLesson();
        $enrollment = $this->enroll($course);

        $component = Livewire::test(QuizPlayer::class, ['course' => $course, 'lesson' => $lesson])
            ->call('startAttempt');

        // Chọn sai hết
        foreach ($questions as $question) {
            $wrongId = $question->options()->where('is_correct', false)->value('id');
            $component->call('selectOption', $question->id, $wrongId, false);
        }

        $component->call('submit');

        $attempt = QuizAttempt::where('employee_id', $this->employee->id)->first();
        $this->assertFalse($attempt->is_passed);

        $progress = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $lesson->id)
            ->first();
        $this->assertNotSame(LessonProgress::STATUS_COMPLETED, $progress?->status);
    }

    public function test_cau_tra_loi_duoc_luu_ngay_khong_doi_nop_bai(): void
    {
        [$course, $lesson, $quiz, $questions] = $this->makeQuizLesson();
        $this->enroll($course);

        $component = Livewire::test(QuizPlayer::class, ['course' => $course, 'lesson' => $lesson])
            ->call('startAttempt');

        $question = $questions[0];
        $optionId = $question->options()->where('is_correct', true)->value('id');
        $component->call('selectOption', $question->id, $optionId, false);

        // Chưa nộp bài nhưng câu trả lời đã nằm trong CSDL
        $attempt = QuizAttempt::where('employee_id', $this->employee->id)->first();
        $saved = $attempt->answers()->where('question_id', $question->id)->first();

        $this->assertSame([$optionId], $saved->selected_option_ids);
        $this->assertSame(QuizAttempt::STATUS_IN_PROGRESS, $attempt->status);
    }

    public function test_quay_lai_thi_noi_tiep_luot_dang_lam_do(): void
    {
        [$course, $lesson, $quiz, $questions] = $this->makeQuizLesson();
        $this->enroll($course);

        $first = Livewire::test(QuizPlayer::class, ['course' => $course, 'lesson' => $lesson])
            ->call('startAttempt');
        $attemptId = $first->get('attemptId');

        // Mở lại màn hình: phải nối vào lượt cũ, không tạo lượt mới
        $second = Livewire::test(QuizPlayer::class, ['course' => $course, 'lesson' => $lesson]);

        $this->assertSame($attemptId, $second->get('attemptId'));
        $this->assertSame(1, QuizAttempt::where('employee_id', $this->employee->id)->count());
    }

    public function test_het_gio_thi_nop_bai_tu_dong(): void
    {
        [$course, $lesson, $quiz, $questions] = $this->makeQuizLesson();
        $quiz->update(['duration_minutes' => 10]);
        $this->enroll($course);

        $component = Livewire::test(QuizPlayer::class, ['course' => $course, 'lesson' => $lesson])
            ->call('startAttempt');

        $attempt = QuizAttempt::find($component->get('attemptId'));
        $attempt->update(['expires_at' => now()->subMinute()]);

        $component->call('timeUp');

        $this->assertSame(QuizAttempt::STATUS_GRADED, $attempt->refresh()->status);
    }

    public function test_khong_lam_duoc_bai_kiem_tra_cua_khoa_chua_duoc_giao(): void
    {
        [$course, $lesson] = $this->makeQuizLesson();

        $this->get(route('learn.quiz', [$course, $lesson]))->assertNotFound();
    }

    // ---- Hoàn thành khóa ----------------------------------------------------

    public function test_hoan_thanh_het_bai_thi_khoa_hoc_hoan_thanh(): void
    {
        [$course, $lessons] = $this->makeCourseWithLessons(2);
        $enrollment = $this->enroll($course);

        $component = Livewire::test(CoursePlayer::class, ['course' => $course]);

        foreach ($lessons as $lesson) {
            $component->call('openLesson', $lesson->id)->call('completeLesson');
        }

        $enrollment->refresh();
        $this->assertEquals(100.0, (float) $enrollment->progress_percent);
        $this->assertSame(Enrollment::STATUS_COMPLETED, $enrollment->status);
        $this->assertNotNull($enrollment->completed_at);
    }

    // ---- Trợ giúp -----------------------------------------------------------

    /** @return array{0: Course, 1: \Illuminate\Support\Collection<int, Lesson>} */
    // ---- Nút điều hướng cuối bài -------------------------------------------

    public function test_chua_xong_het_thi_hien_nut_bai_sau(): void
    {
        [$course, $lessons] = $this->makeCourseWithLessons(2);
        $this->enroll($course);

        $component = Livewire::actingAs($this->user)
            ->test(CoursePlayer::class, ['course' => $course]);

        $this->assertFalse($component->viewData('allLessonsCompleted'));
        $component->assertSee('Bài sau')->assertDontSee('Thoát khóa học');
    }

    public function test_xong_het_bai_thi_doi_thanh_nut_thoat(): void
    {
        // Còn nút "Bài sau" mờ ở bài cuối là ngõ cụt — người học không biết đi đâu
        [$course, $lessons] = $this->makeCourseWithLessons(2);
        $enrollment = $this->enroll($course);

        $progress = app(\App\Services\ProgressService::class);

        foreach ($lessons as $lesson) {
            $progress->markLessonStarted($enrollment, $lesson);
            $progress->completeLesson($enrollment, $lesson);
        }

        $component = Livewire::actingAs($this->user)
            ->test(CoursePlayer::class, ['course' => $course]);

        $this->assertTrue($component->viewData('allLessonsCompleted'));
        $component->assertSee('Thoát khóa học')->assertDontSee('Bài sau');
    }

    public function test_bam_thoat_thi_ve_danh_sach_khoa_hoc(): void
    {
        [$course, $lessons] = $this->makeCourseWithLessons(1);
        $enrollment = $this->enroll($course);

        $progress = app(\App\Services\ProgressService::class);
        $progress->markLessonStarted($enrollment, $lessons->first());
        $progress->completeLesson($enrollment, $lessons->first());

        Livewire::actingAs($this->user)
            ->test(CoursePlayer::class, ['course' => $course])
            ->call('exitCourse')
            ->assertRedirect(route('learn.courses'));
    }

    public function test_xong_mot_phan_thi_van_hien_bai_sau(): void
    {
        [$course, $lessons] = $this->makeCourseWithLessons(3);
        $enrollment = $this->enroll($course);

        $progress = app(\App\Services\ProgressService::class);
        $progress->markLessonStarted($enrollment, $lessons->first());
        $progress->completeLesson($enrollment, $lessons->first());

        $this->assertFalse(
            Livewire::actingAs($this->user)
                ->test(CoursePlayer::class, ['course' => $course])
                ->viewData('allLessonsCompleted')
        );
    }

    private function makeCourseWithLessons(int $count = 2): array
    {
        $course = Course::factory()->create(['sequential' => true]);

        $lessons = collect(range(0, $count - 1))->map(fn ($i) => Lesson::factory()->create([
            'course_id' => $course->id,
            'sort_order' => $i,
            'content_type' => Lesson::TYPE_TEXT,
            'content_html' => '<p>Nội dung bài ' . ($i + 1) . '</p>',
        ]));

        return [$course, $lessons];
    }

    /** @return array{0: Course, 1: Lesson, 2: Quiz, 3: array<int, Question>} */
    private function makeQuizLesson(): array
    {
        $course = Course::factory()->create();
        $quiz = Quiz::factory()->create(['course_id' => $course->id, 'pass_score' => 70]);

        $questions = [];

        foreach (range(0, 1) as $i) {
            $question = Question::factory()->create(['quiz_id' => $quiz->id, 'sort_order' => $i]);
            QuestionOption::create(['question_id' => $question->id, 'content' => 'Đúng', 'is_correct' => true]);
            QuestionOption::create(['question_id' => $question->id, 'content' => 'Sai', 'is_correct' => false]);
            $questions[] = $question;
        }

        $lesson = Lesson::factory()->quiz()->create([
            'course_id' => $course->id,
            'quiz_id' => $quiz->id,
            'sort_order' => 0,
        ]);

        return [$course, $lesson, $quiz, $questions];
    }

    private function enroll(Course $course): Enrollment
    {
        return Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $this->employee->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => $course->requiredLessonCount(),
        ]);
    }
}
