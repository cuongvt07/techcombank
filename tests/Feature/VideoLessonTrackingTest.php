<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\User\CoursePlayer;
use App\Models\Course;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Services\ProgressService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiểm chứng theo dõi bài học video nhúng: biết nhân viên đã vào bài,
 * ở lại bao lâu, và hoàn thành khi họ xác nhận (spec 3.2.4).
 */
class VideoLessonTrackingTest extends TestCase
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

    // ---- Ghi nhận vào bài ---------------------------------------------------

    public function test_mo_bai_video_thi_ghi_nhan_da_vao(): void
    {
        [$course, $lesson, $enrollment] = $this->makeEmbeddedVideoLesson();

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id);

        $progress = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        $this->assertNotNull($progress, 'Phải ghi nhận nhân viên đã vào bài');
        $this->assertSame(LessonProgress::STATUS_IN_PROGRESS, $progress->status);
        $this->assertNotNull($progress->first_accessed_at);
    }

    public function test_vao_bai_thi_khoa_hoc_chuyen_sang_dang_hoc(): void
    {
        [$course, $lesson, $enrollment] = $this->makeEmbeddedVideoLesson();

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id);

        $enrollment->refresh();
        $this->assertNotNull($enrollment->started_at);
        $this->assertSame(Enrollment::STATUS_IN_PROGRESS, $enrollment->status);
    }

    public function test_mo_lai_bai_khong_ghi_de_lan_vao_dau_tien(): void
    {
        [$course, $lesson, $enrollment] = $this->makeEmbeddedVideoLesson();

        $component = Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id);

        $firstAccess = LessonProgress::where('lesson_id', $lesson->id)->value('first_accessed_at');

        $component->call('openLesson', $lesson->id);

        $this->assertEquals(
            $firstAccess,
            LessonProgress::where('lesson_id', $lesson->id)->value('first_accessed_at'),
            'Mốc vào bài đầu tiên phải giữ nguyên',
        );
    }

    // ---- Thời gian ở lại bài ------------------------------------------------

    public function test_ghi_nhan_thoi_gian_o_lai_bai(): void
    {
        [$course, $lesson, $enrollment] = $this->makeEmbeddedVideoLesson();

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id)
            ->call('recordTimeSpent', 60);

        $progress = LessonProgress::where('lesson_id', $lesson->id)->first();
        $this->assertSame(60, $progress->time_spent_seconds);
    }

    public function test_thoi_gian_duoc_cong_don_qua_nhieu_lan_gui(): void
    {
        [$course, $lesson] = $this->makeEmbeddedVideoLesson();

        $component = Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id);

        $component->call('recordTimeSpent', 60);
        $component->call('recordTimeSpent', 45);

        $this->assertSame(105, LessonProgress::where('lesson_id', $lesson->id)->value('time_spent_seconds'));
    }

    public function test_chan_gia_tri_thoi_gian_vo_ly(): void
    {
        [$course, $lesson, $enrollment] = $this->makeEmbeddedVideoLesson();

        // Tab để mở qua đêm không được tính là 8 tiếng học
        app(ProgressService::class)->addTimeSpent($enrollment, $lesson, 8 * 3600);

        $seconds = LessonProgress::where('lesson_id', $lesson->id)->value('time_spent_seconds');
        $this->assertSame(30 * 60, $seconds, 'Phải bị giới hạn ở 30 phút mỗi lần ghi');
    }

    public function test_bo_qua_thoi_gian_bang_khong(): void
    {
        [$course, $lesson, $enrollment] = $this->makeEmbeddedVideoLesson();

        app(ProgressService::class)->addTimeSpent($enrollment, $lesson, 0);
        app(ProgressService::class)->addTimeSpent($enrollment, $lesson, -10);

        $progress = LessonProgress::where('lesson_id', $lesson->id)->first();
        $this->assertSame(0, $progress?->time_spent_seconds ?? 0);
    }

    public function test_ghi_thoi_gian_cap_nhat_moc_truy_cap_gan_nhat(): void
    {
        [$course, $lesson, $enrollment] = $this->makeEmbeddedVideoLesson();

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id)
            ->call('recordTimeSpent', 30);

        $this->assertNotNull($enrollment->refresh()->last_accessed_at);
    }

    // ---- Hoàn thành video nhúng ---------------------------------------------

    public function test_video_nhung_hoan_thanh_duoc_bang_xac_nhan(): void
    {
        [$course, $lesson, $enrollment] = $this->makeEmbeddedVideoLesson();

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id)
            ->call('completeLesson');

        $this->assertDatabaseHas('lesson_progresses', [
            'lesson_id' => $lesson->id,
            'status' => LessonProgress::STATUS_COMPLETED,
        ]);

        $enrollment->refresh();
        $this->assertEquals(100.0, (float) $enrollment->progress_percent);
    }

    public function test_video_phat_truc_tiep_van_khong_cho_bam_tay(): void
    {
        [$course, $lesson] = $this->makeDirectVideoLesson();

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id)
            ->call('completeLesson');

        // Loại này đo được % xem nên phải theo ngưỡng, không cho bỏ qua
        $progress = LessonProgress::where('lesson_id', $lesson->id)->first();
        $this->assertNotSame(LessonProgress::STATUS_COMPLETED, $progress?->status);
    }

    public function test_video_phat_truc_tiep_hoan_thanh_theo_nguong_xem(): void
    {
        [$course, $lesson] = $this->makeDirectVideoLesson();

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id)
            ->call('updateVideoProgress', 540, 90.0);

        $this->assertDatabaseHas('lesson_progresses', [
            'lesson_id' => $lesson->id,
            'status' => LessonProgress::STATUS_COMPLETED,
        ]);
    }

    public function test_bai_kiem_tra_van_khong_cho_bam_tay(): void
    {
        $course = Course::factory()->create();
        $quiz = \App\Models\Quiz::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create([
            'course_id' => $course->id,
            'quiz_id' => $quiz->id,
            'sort_order' => 0,
        ]);
        $this->enroll($course);

        Livewire::test(CoursePlayer::class, ['course' => $course])
            ->call('openLesson', $lesson->id)
            ->call('completeLesson');

        $progress = LessonProgress::where('lesson_id', $lesson->id)->first();
        $this->assertNotSame(LessonProgress::STATUS_COMPLETED, $progress?->status);
    }

    // ---- Trợ giúp -----------------------------------------------------------

    /** @return array{0: Course, 1: Lesson, 2: Enrollment} */
    private function makeEmbeddedVideoLesson(): array
    {
        $course = Course::factory()->create();

        $document = Document::factory()->video()->create();

        $lesson = Lesson::factory()->video()->create([
            'course_id' => $course->id,
            'document_id' => $document->id,
            'sort_order' => 0,
        ]);

        return [$course, $lesson, $this->enroll($course)];
    }

    /** Video trên hạ tầng riêng — đo được tiến độ nên không cho bấm tay. */
    private function makeDirectVideoLesson(): array
    {
        $course = Course::factory()->create();

        $document = Document::factory()->create([
            'kind' => Document::KIND_VIDEO,
            'video_url' => 'https://cdn.noi-bo.local/bai-giang.mp4',
            'video_provider' => 'direct',
            'video_embed_id' => null,
            'duration_seconds' => 600,
        ]);

        $lesson = Lesson::factory()->video(80)->create([
            'course_id' => $course->id,
            'document_id' => $document->id,
            'sort_order' => 0,
        ]);

        return [$course, $lesson, $this->enroll($course)];
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
