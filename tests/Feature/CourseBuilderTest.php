<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\CourseBuilder;
use App\Models\Course;
use App\Models\CourseAssignmentRule;
use App\Models\Department;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\User;
use App\Services\ProgressService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Kiểm chứng dựng khóa học và bài giảng (spec 3.2.4 + 3.2.5). */
class CourseBuilderTest extends TestCase
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

    // ---- Khóa học -----------------------------------------------------------

    public function test_tao_khoa_hoc_moi(): void
    {
        Livewire::test(CourseBuilder::class)
            ->call('createCourse')
            ->set('code', 'C-TEST')
            ->set('title', 'Khóa học thử nghiệm')
            ->set('duration_days', 30)
            ->call('saveCourse')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('courses', [
            'code' => 'C-TEST',
            'status' => Course::STATUS_DRAFT,
            'sequential' => true,
        ]);
    }

    public function test_ma_khoa_hoc_khong_duoc_trung(): void
    {
        Course::factory()->create(['code' => 'C-DUP']);

        Livewire::test(CourseBuilder::class)
            ->call('createCourse')
            ->set('code', 'C-DUP')
            ->set('title', 'Khóa trùng mã')
            ->call('saveCourse')
            ->assertHasErrors(['code' => 'unique']);
    }

    public function test_khong_xuat_ban_duoc_khoa_chua_co_bai_hoc(): void
    {
        $course = Course::factory()->draft()->create();

        Livewire::test(CourseBuilder::class)->call('publishCourse', $course->id);

        $this->assertSame(Course::STATUS_DRAFT, $course->refresh()->status);
    }

    public function test_khong_xuat_ban_duoc_khi_bai_hoc_chua_co_noi_dung(): void
    {
        $course = Course::factory()->draft()->create();
        // Bài dạng văn bản nhưng bỏ trống nội dung => người học mở ra thấy trang trắng
        Lesson::factory()->create([
            'course_id' => $course->id,
            'content_type' => Lesson::TYPE_TEXT,
            'content_html' => null,
        ]);

        Livewire::test(CourseBuilder::class)->call('publishCourse', $course->id);

        $this->assertSame(Course::STATUS_DRAFT, $course->refresh()->status);
    }

    public function test_khong_xuat_ban_duoc_khi_khong_co_bai_bat_buoc(): void
    {
        $course = Course::factory()->draft()->create();
        Lesson::factory()->optional()->create(['course_id' => $course->id]);

        Livewire::test(CourseBuilder::class)->call('publishCourse', $course->id);

        // Không có bài bắt buộc thì mẫu số tính tiến độ bằng 0
        $this->assertSame(Course::STATUS_DRAFT, $course->refresh()->status);
    }

    public function test_xuat_ban_khoa_hoc_day_du(): void
    {
        $course = Course::factory()->draft()->create();
        Lesson::factory()->create([
            'course_id' => $course->id,
            'content_type' => Lesson::TYPE_TEXT,
            'content_html' => '<p>Nội dung</p>',
        ]);

        Livewire::test(CourseBuilder::class)->call('publishCourse', $course->id);

        $course->refresh();
        $this->assertSame(Course::STATUS_PUBLISHED, $course->status);
        $this->assertNotNull($course->published_at);
    }

    // ---- Bài học ------------------------------------------------------------

    public function test_them_bai_hoc_dang_van_ban(): void
    {
        $course = Course::factory()->create();

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createLesson')
            ->set('lessonTitle', 'Bài 1: Giới thiệu')
            ->set('contentType', Lesson::TYPE_TEXT)
            ->set('contentHtml', '<p>Nội dung bài học</p>')
            ->call('saveLesson')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('lessons', [
            'course_id' => $course->id,
            'title' => 'Bài 1: Giới thiệu',
            'content_type' => Lesson::TYPE_TEXT,
        ]);
    }

    public function test_bai_van_ban_bat_buoc_co_noi_dung(): void
    {
        $course = Course::factory()->create();

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createLesson')
            ->set('lessonTitle', 'Bài thiếu nội dung')
            ->set('contentType', Lesson::TYPE_TEXT)
            ->set('contentHtml', '')
            ->call('saveLesson')
            ->assertHasErrors('contentHtml');
    }

    public function test_bai_quiz_bat_buoc_chon_bai_kiem_tra(): void
    {
        $course = Course::factory()->create();

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createLesson')
            ->set('lessonTitle', 'Bài kiểm tra cuối khóa')
            ->set('contentType', Lesson::TYPE_QUIZ)
            ->call('saveLesson')
            ->assertHasErrors('quizId');
    }

    public function test_doi_dang_noi_dung_thi_xoa_nguon_cua_dang_cu(): void
    {
        $course = Course::factory()->create();
        $document = Document::factory()->create();
        $quiz = Quiz::factory()->create();

        // Tạo bài gắn tài liệu
        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createLesson')
            ->set('lessonTitle', 'Bài đổi dạng')
            ->set('contentType', Lesson::TYPE_DOCUMENT)
            ->set('documentId', $document->id)
            ->call('saveLesson');

        $lesson = $course->lessons()->first();
        $this->assertSame($document->id, $lesson->document_id);

        // Đổi sang quiz: document_id phải bị xoá, tránh bài trỏ tới hai nguồn
        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('editLesson', $lesson->id)
            ->set('contentType', Lesson::TYPE_QUIZ)
            ->set('quizId', $quiz->id)
            ->call('saveLesson')
            ->assertHasNoErrors();

        $lesson->refresh();
        $this->assertNull($lesson->document_id);
        $this->assertSame($quiz->id, $lesson->quiz_id);
    }

    public function test_bai_hoc_moi_xep_cuoi_danh_sach(): void
    {
        $course = Course::factory()->create();
        Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 0]);
        Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createLesson')
            ->set('lessonTitle', 'Bài cuối')
            ->set('contentHtml', '<p>x</p>')
            ->call('saveLesson');

        $last = $course->lessons()->orderByDesc('sort_order')->first();
        $this->assertSame('Bài cuối', $last->title);
        $this->assertSame(2, $last->sort_order);
    }

    public function test_doi_thu_tu_bai_hoc(): void
    {
        $course = Course::factory()->create();
        $first = Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 0, 'title' => 'Bài A']);
        $second = Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 1, 'title' => 'Bài B']);

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('moveLesson', $second->id, -1);

        $this->assertSame(0, $second->refresh()->sort_order);
        $this->assertSame(1, $first->refresh()->sort_order);
    }

    // ---- Tiến độ phải nhất quán khi cấu trúc khóa đổi -----------------------

    public function test_them_bai_bat_buoc_thi_tinh_lai_tien_do_nguoi_dang_hoc(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 0]);

        $employee = Employee::factory()->create();
        $enrollment = Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => 1,
        ]);

        // Học xong bài duy nhất => 100%
        app(ProgressService::class)->completeLesson($enrollment, $lesson);
        $this->assertEquals(100.0, (float) $enrollment->refresh()->progress_percent);

        // Admin thêm bài bắt buộc thứ hai => tiến độ phải tụt về 50%
        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createLesson')
            ->set('lessonTitle', 'Bài bổ sung')
            ->set('contentHtml', '<p>x</p>')
            ->call('saveLesson');

        $enrollment->refresh();
        $this->assertEquals(50.0, (float) $enrollment->progress_percent);
        $this->assertSame(Enrollment::STATUS_IN_PROGRESS, $enrollment->status);
    }

    public function test_xoa_bai_hoc_thi_tinh_lai_tien_do(): void
    {
        $course = Course::factory()->create();
        $lessonA = Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 0]);
        $lessonB = Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);

        $employee = Employee::factory()->create();
        $enrollment = Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => 2,
        ]);

        app(ProgressService::class)->completeLesson($enrollment, $lessonA);
        $this->assertEquals(50.0, (float) $enrollment->refresh()->progress_percent);

        // Xoá bài chưa học => người học hoàn thành khóa
        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('deleteLesson', $lessonB->id);

        $enrollment->refresh();
        $this->assertEquals(100.0, (float) $enrollment->progress_percent);
        $this->assertSame(Enrollment::STATUS_COMPLETED, $enrollment->status);
    }

    public function test_bo_bat_buoc_mot_bai_thi_tinh_lai_mau_so(): void
    {
        $course = Course::factory()->create();
        $lessonA = Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 0]);
        $lessonB = Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);

        $employee = Employee::factory()->create();
        $enrollment = Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => 2,
        ]);

        app(ProgressService::class)->completeLesson($enrollment, $lessonA);

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('toggleLessonRequired', $lessonB->id);

        $enrollment->refresh();
        $this->assertSame(1, $enrollment->total_lessons);
        $this->assertEquals(100.0, (float) $enrollment->progress_percent);
    }

    // ---- Điều kiện gán ------------------------------------------------------

    public function test_tao_dieu_kien_gan_va_giao_ngay_cho_nhan_vien_khop(): void
    {
        $course = Course::factory()->create();
        $department = Department::factory()->create();
        Employee::factory()->count(3)->create(['department_id' => $department->id]);
        Employee::factory()->count(2)->create();

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createRule')
            ->set('ruleDepartmentId', $department->id)
            ->set('ruleDueDays', 30)
            ->call('saveRule')
            ->assertHasNoErrors();

        $this->assertSame(3, Enrollment::where('course_id', $course->id)->count());
    }

    public function test_chan_dieu_kien_trong_de_khong_gan_cho_toan_cong_ty(): void
    {
        $course = Course::factory()->create();
        Employee::factory()->count(5)->create();

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createRule')
            ->call('saveRule')
            ->assertHasErrors('ruleDepartmentId');

        $this->assertSame(0, Enrollment::where('course_id', $course->id)->count());
    }

    public function test_xoa_dieu_kien_gan_khong_thu_hoi_khoa_da_giao(): void
    {
        $course = Course::factory()->create();
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);

        $rule = CourseAssignmentRule::create([
            'course_id' => $course->id,
            'department_id' => $department->id,
        ]);

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('applyRuleNow', $rule->id);

        $this->assertSame(1, Enrollment::where('course_id', $course->id)->count());

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('deleteRule', $rule->id);

        // Lịch sử học tập phải được giữ lại (spec 3.2.4)
        $this->assertSame(1, Enrollment::where('course_id', $course->id)->count());
    }

    // ---- Phân quyền ---------------------------------------------------------

    public function test_nhan_vien_khong_vao_duoc_man_hinh_khoa_hoc(): void
    {
        $employee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $employee->assignRole(RoleName::EMPLOYEE->value);
        Employee::factory()->create(['user_id' => $employee->id]);

        $this->actingAs($employee->refresh())
            ->get(route('admin.courses'))
            ->assertRedirect(route('learn.events'));
    }

    public function test_quan_tri_vien_vao_duoc_man_hinh_khoa_hoc(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin->refresh())
            ->get(route('admin.courses'))
            ->assertOk();
    }
}
