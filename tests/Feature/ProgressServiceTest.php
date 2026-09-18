<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kiểm chứng cách tính % tiến độ ở spec 3.2.4. */
class ProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProgressService::class);
    }

    public function test_tien_do_chi_tinh_tren_bai_hoc_bat_buoc(): void
    {
        $course = Course::factory()->create();
        // 2 bài bắt buộc + 1 bài tự chọn: mẫu số phải là 2, không phải 3
        $required = Lesson::factory()->count(2)->sequence(
            ['sort_order' => 0],
            ['sort_order' => 1],
        )->create(['course_id' => $course->id]);
        Lesson::factory()->optional()->create(['course_id' => $course->id, 'sort_order' => 2]);

        $enrollment = $this->enroll($course);

        $this->service->completeLesson($enrollment, $required[0]);
        $enrollment->refresh();

        $this->assertSame(2, $enrollment->total_lessons);
        $this->assertEquals(50.00, (float) $enrollment->progress_percent);
        $this->assertSame(Enrollment::STATUS_IN_PROGRESS, $enrollment->status);

        $this->service->completeLesson($enrollment, $required[1]);
        $enrollment->refresh();

        $this->assertEquals(100.00, (float) $enrollment->progress_percent);
        $this->assertSame(Enrollment::STATUS_COMPLETED, $enrollment->status);
        $this->assertNotNull($enrollment->completed_at);
    }

    public function test_hoan_thanh_bai_tu_chon_khong_lam_tang_phan_tram(): void
    {
        $course = Course::factory()->create();
        Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 0]);
        $optional = Lesson::factory()->optional()->create(['course_id' => $course->id, 'sort_order' => 1]);

        $enrollment = $this->enroll($course);
        $this->service->completeLesson($enrollment, $optional);
        $enrollment->refresh();

        $this->assertEquals(0.00, (float) $enrollment->progress_percent);
        $this->assertSame(0, $enrollment->completed_lessons);
    }

    public function test_video_chi_hoan_thanh_khi_dat_nguong_xem(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->video(80)->create(['course_id' => $course->id]);
        $enrollment = $this->enroll($course);

        $progress = $this->service->updateVideoProgress($enrollment, $lesson, 300, 50.0);
        $this->assertSame(LessonProgress::STATUS_IN_PROGRESS, $progress->status);

        $progress = $this->service->updateVideoProgress($enrollment, $lesson, 500, 85.0);
        $this->assertSame(LessonProgress::STATUS_COMPLETED, $progress->status);
    }

    public function test_tua_nguoc_video_khong_lam_giam_tien_do_da_dat(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->video(90)->create(['course_id' => $course->id]);
        $enrollment = $this->enroll($course);

        $this->service->updateVideoProgress($enrollment, $lesson, 500, 70.0);
        $progress = $this->service->updateVideoProgress($enrollment, $lesson, 100, 20.0);

        $this->assertEquals(70.0, (float) $progress->watch_percent);
        $this->assertSame(100, $progress->last_position_second);
    }

    public function test_khoa_hoc_cau_hinh_cap_chung_nhan_thi_phat_hanh_khi_hoan_thanh(): void
    {
        $course = Course::factory()->withCertificate()->create();
        $lesson = Lesson::factory()->create(['course_id' => $course->id]);
        $enrollment = $this->enroll($course);

        $this->service->completeLesson($enrollment, $lesson);

        $certificate = $enrollment->refresh()->certificate;
        $this->assertNotNull($certificate);
        $this->assertNotEmpty($certificate->verification_code);
    }

    public function test_khong_phat_hanh_chung_nhan_trung_lap(): void
    {
        $course = Course::factory()->withCertificate()->create();
        $lesson = Lesson::factory()->create(['course_id' => $course->id]);
        $enrollment = $this->enroll($course);

        $this->service->completeLesson($enrollment, $lesson);
        // Tính lại lần nữa: không được sinh thêm chứng nhận thứ hai
        $this->service->recalculate($enrollment->refresh());

        $this->assertSame(1, $enrollment->certificate()->count());
    }

    public function test_qua_han_chua_xong_thi_chuyen_trang_thai_overdue(): void
    {
        $course = Course::factory()->create();
        Lesson::factory()->count(2)->create(['course_id' => $course->id]);
        $enrollment = $this->enroll($course, ['due_date' => now()->subDay()->toDateString()]);

        $this->service->recalculate($enrollment);

        $this->assertSame(Enrollment::STATUS_OVERDUE, $enrollment->refresh()->status);
    }

    public function test_hoan_thanh_muon_van_tinh_la_completed(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->create(['course_id' => $course->id]);
        $enrollment = $this->enroll($course, ['due_date' => now()->subWeek()->toDateString()]);

        $this->service->completeLesson($enrollment, $lesson);

        $this->assertSame(Enrollment::STATUS_COMPLETED, $enrollment->refresh()->status);
    }

    private function enroll(Course $course, array $attributes = []): Enrollment
    {
        $employee = Employee::factory()->create();

        return Enrollment::create(array_merge([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => $course->requiredLessonCount(),
        ], $attributes));
    }
}
