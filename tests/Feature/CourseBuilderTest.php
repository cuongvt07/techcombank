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
            ->set('title', 'Khóa học thử nghiệm')
            ->set('duration_days', 30)
            ->call('saveCourse')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('courses', [
            'title' => 'Khóa học thử nghiệm',
            'code' => 'KH-0001',
            'status' => Course::STATUS_DRAFT,
            'sequential' => true,
        ]);
    }

    public function test_ma_khoa_hoc_tu_sinh_va_giu_nguyen_khi_sua(): void
    {
        Course::factory()->create(['code' => 'KH-0041']);

        Livewire::test(CourseBuilder::class)
            ->call('createCourse')
            ->set('code', 'KH-0041')
            ->set('title', 'Khóa mã nối tiếp')
            ->call('saveCourse')
            ->assertHasNoErrors();

        $course = Course::where('title', 'Khóa mã nối tiếp')->firstOrFail();
        $this->assertSame('KH-0042', $course->code);

        // Sửa khóa học không đổi mã, kể cả khi request gửi kèm mã khác
        Livewire::test(CourseBuilder::class)
            ->call('editCourse', $course->id)
            ->set('code', 'MA-KHAC')
            ->set('title', 'Khóa mã nối tiếp (sửa)')
            ->call('saveCourse')
            ->assertHasNoErrors();

        $this->assertSame('KH-0042', $course->fresh()->code);
    }

    public function test_loc_khoa_hoc_theo_phong_ban_duoc_giao_gom_phong_con(): void
    {
        $parent = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $parent->id]);
        $other = Department::factory()->create();

        $childCourse = Course::factory()->create(['title' => 'Khóa Của Phòng Con']);
        $this->makeRule($childCourse, [$child->id]);

        $allCourse = Course::factory()->create(['title' => 'Khóa Toàn Hàng']);
        $this->makeRule($allCourse, []);

        $otherCourse = Course::factory()->create(['title' => 'Khóa Phòng Khác']);
        $this->makeRule($otherCourse, [$other->id]);

        // Điều kiện đã tắt không tính
        $inactiveCourse = Course::factory()->create(['title' => 'Khóa Điều Kiện Tắt']);
        $this->makeRule($inactiveCourse, [$parent->id], ['is_active' => false]);

        // Khoá cũ chỉ có phòng ban ban hành, không có điều kiện giao: không còn lọc theo cột đó
        Course::factory()->create(['title' => 'Khóa Ban Hành Cũ', 'owner_department_id' => $parent->id]);

        Livewire::test(CourseBuilder::class)
            ->set('departmentFilter', (string) $parent->id)
            ->assertSee('Khóa Của Phòng Con')
            ->assertSee('Khóa Toàn Hàng')
            ->assertDontSee('Khóa Phòng Khác')
            ->assertDontSee('Khóa Điều Kiện Tắt')
            ->assertDontSee('Khóa Ban Hành Cũ');
    }

    public function test_loc_phong_con_thay_khoa_giao_phong_cha_gom_phong_con(): void
    {
        $parent = Department::factory()->create();
        $child = Department::factory()->create(['parent_id' => $parent->id]);

        $withSub = Course::factory()->create(['title' => 'Khóa Cả Khối']);
        $this->makeRule($withSub, [$parent->id], ['include_sub_departments' => true]);

        $onlyParent = Course::factory()->create(['title' => 'Khóa Riêng Phòng Cha']);
        $this->makeRule($onlyParent, [$parent->id], ['include_sub_departments' => false]);

        Livewire::test(CourseBuilder::class)
            ->set('departmentFilter', (string) $child->id)
            ->assertSee('Khóa Cả Khối')
            ->assertDontSee('Khóa Riêng Phòng Cha');
    }

    public function test_cot_phong_ban_hien_phong_ban_duoc_giao(): void
    {
        $a = Department::factory()->create(['name' => 'Phòng Alpha']);
        $b = Department::factory()->create(['name' => 'Phòng Beta']);

        $multi = Course::factory()->create(['title' => 'Khóa Hai Phòng']);
        $this->makeRule($multi, [$a->id]);
        $this->makeRule($multi, [$a->id, $b->id]);

        $all = Course::factory()->create(['title' => 'Khóa Cho Tất Cả']);
        $this->makeRule($all, [$a->id]);
        $this->makeRule($all, []);

        $none = Course::factory()->create(['title' => 'Khóa Chưa Giao']);

        $courses = Course::with(['assignmentRules.departments', 'assignmentRules.department'])
            ->get()->keyBy('id');

        $this->assertSame(['Phòng Alpha', 'Phòng Beta'], $courses[$multi->id]->assignedDepartmentNames());
        $this->assertSame(['*'], $courses[$all->id]->assignedDepartmentNames());
        $this->assertNull($courses[$none->id]->assignedDepartmentNames());

        Livewire::test(CourseBuilder::class)
            ->assertSee('Phòng Alpha, Phòng Beta')
            ->assertSee('Tất cả');
    }

    public function test_loc_khoa_hoc_theo_kieu_hoc(): void
    {
        Course::factory()->create(['title' => 'Khóa Tuần Tự', 'sequential' => true]);
        Course::factory()->create(['title' => 'Khóa Tự Do', 'sequential' => false]);
        Course::factory()->create(['title' => 'Khóa Onboarding', 'sequential' => true, 'is_onboarding' => true]);
        Course::factory()->create(['title' => 'Khóa Chứng Nhận', 'sequential' => false, 'issue_certificate' => true]);

        Livewire::test(CourseBuilder::class)
            ->set('learningTypeFilter', 'free')
            ->assertSee('Khóa Tự Do')
            ->assertSee('Khóa Chứng Nhận')
            ->assertDontSee('Khóa Tuần Tự')
            ->set('learningTypeFilter', 'onboarding')
            ->assertSee('Khóa Onboarding')
            ->assertDontSee('Khóa Tự Do')
            ->set('learningTypeFilter', 'certificate')
            ->assertSee('Khóa Chứng Nhận')
            ->assertDontSee('Khóa Onboarding');
    }

    public function test_tim_kiem_khong_pha_bo_loc_trang_thai(): void
    {
        // Lỗi cũ: orWhere không gom nhóm nên khóa khớp mã vẫn lọt qua bộ lọc trạng thái
        Course::factory()->draft()->create(['title' => 'An Toàn Nháp', 'code' => 'KH-ATN']);
        Course::factory()->create(['title' => 'An Toàn Đã Xuất Bản', 'code' => 'KH-ATX', 'status' => Course::STATUS_PUBLISHED]);

        Livewire::test(CourseBuilder::class)
            ->set('search', 'KH-AT')
            ->set('statusFilter', Course::STATUS_DRAFT)
            ->assertSee('An Toàn Nháp')
            ->assertDontSee('An Toàn Đã Xuất Bản');
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
            ->set('ruleDepartmentIds', [$department->id])
            ->set('ruleDueDays', 30)
            ->call('saveRule')
            ->assertHasNoErrors();

        $this->assertSame(3, Enrollment::where('course_id', $course->id)->count());
    }

    public function test_dieu_kien_luu_nhieu_phong_ban_va_giao_dung_nguoi(): void
    {
        $course = Course::factory()->create();
        $a = Department::factory()->create();
        $b = Department::factory()->create();
        $c = Department::factory()->create();
        Employee::factory()->count(2)->create(['department_id' => $a->id]);
        Employee::factory()->count(3)->create(['department_id' => $b->id]);
        Employee::factory()->count(4)->create(['department_id' => $c->id]);

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createRule')
            ->set('ruleDepartmentIds', [(string) $a->id, (string) $b->id])
            ->call('saveRule')
            ->assertHasNoErrors();

        $rule = CourseAssignmentRule::where('course_id', $course->id)->sole();
        $this->assertNull($rule->department_id);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $rule->departments->pluck('id')->all());
        $this->assertSame(5, Enrollment::where('course_id', $course->id)->count());

        // Danh sách điều kiện hiện tên các phòng ban
        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->assertSee($a->name)
            ->assertSee($b->name);
    }

    public function test_dieu_kien_khong_chon_phong_ban_giao_moi_nhan_vien(): void
    {
        $course = Course::factory()->create();
        Employee::factory()->count(5)->create();
        $activeCount = Employee::active()->count();

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createRule')
            ->call('saveRule')
            ->assertHasNoErrors();

        $this->assertSame($activeCount, Enrollment::where('course_id', $course->id)->count());

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->assertSee('Mọi phòng ban');
    }

    public function test_dieu_kien_tu_choi_phong_ban_khong_ton_tai(): void
    {
        $course = Course::factory()->create();

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('createRule')
            ->set('ruleDepartmentIds', [999999])
            ->call('saveRule')
            ->assertHasErrors('ruleDepartmentIds.0');

        $this->assertSame(0, CourseAssignmentRule::count());
    }

    public function test_form_cau_hinh_khoa_hoc_khong_con_phong_ban_ban_hanh(): void
    {
        $course = Course::factory()->create();

        Livewire::test(CourseBuilder::class)
            ->call('editCourse', $course->id)
            ->assertDontSee('Phòng ban ban hành');
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

        app(\App\Services\CourseAssignmentService::class)->applyRule($rule);

        $this->assertSame(1, Enrollment::where('course_id', $course->id)->count());

        Livewire::test(CourseBuilder::class)
            ->set('editingCourseId', $course->id)
            ->call('deleteRule', $rule->id);

        // Lịch sử học tập phải được giữ lại (spec 3.2.4)
        $this->assertSame(1, Enrollment::where('course_id', $course->id)->count());
    }

    private function makeRule(Course $course, array $departmentIds, array $attributes = []): CourseAssignmentRule
    {
        $rule = CourseAssignmentRule::create(array_merge(['course_id' => $course->id], $attributes));
        $rule->departments()->sync($departmentIds);

        return $rule;
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
