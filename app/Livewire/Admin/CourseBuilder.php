<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\HasSearchFilters;
use App\Livewire\Concerns\ChecksPermissions;
use App\Models\Course;
use App\Models\CourseAssignmentRule;
use App\Models\Department;
use App\Models\Document;
use App\Models\JobGrade;
use App\Models\JobTitle;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Services\CourseAssignmentService;
use App\Services\CourseBuilderService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Bộ tài liệu ban hành (spec 3.2.4) + bài giảng bên trong (spec 3.2.5).
 *
 * Một khoá học chứa nhiều bài học xếp theo trình tự. Màn hình có hai mức:
 * danh sách khoá → vào một khoá thì thấy danh sách bài học của khoá đó,
 * kèm điều kiện gán tự động theo phòng ban/vị trí.
 *
 * Khoá không thuộc riêng phòng ban nào: phạm vi phòng ban do điều kiện giao
 * quyết định (một điều kiện chọn được nhiều phòng, không chọn = mọi phòng).
 * Cột owner_department_id cũ vẫn còn trong DB nhưng màn này thôi nhập/hiển thị.
 */
class CourseBuilder extends Component
{
    use HasSearchFilters;

    use ChecksPermissions;

    use WithPagination;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    /** Lọc khoá có điều kiện giao áp cho phòng ban này (gồm phòng con) hoặc mọi phòng ban. */
    #[Url]
    public string $departmentFilter = '';

    /** Lọc theo kiểu học: một trong các khóa của LEARNING_TYPES. */
    #[Url]
    public string $learningTypeFilter = '';

    /**
     * Các kiểu học — ba thuộc tính độc lập, một khóa có thể mang nhiều kiểu
     * (vd. onboarding vừa học tuần tự vừa cấp chứng nhận). "Tự do" là khi tắt tuần tự.
     *
     * @var array<string, array{0: string, 1: string}> khóa => [nhãn, mô tả]
     */
    public const LEARNING_TYPES = [
        'sequential' => ['Học tuần tự', 'Phải hoàn thành bài trước mới mở được bài tiếp theo.'],
        'free' => ['Học tự do', 'Người học chọn bài bất kỳ, không cần theo thứ tự.'],
        'onboarding' => ['Lộ trình onboarding', 'Tự động giao cho mọi nhân viên mới ngay khi tạo tài khoản.'],
        'certificate' => ['Cấp chứng nhận', 'Cấp chứng nhận cho người học khi hoàn thành khóa.'],
    ];

    /** Khoá đang mở. NULL = đang ở danh sách khoá. */
    #[Url]
    public ?int $editingCourseId = null;

    // --- Form khoá học ---
    public bool $showCourseModal = false;
    public ?int $courseFormId = null;
    public string $code = '';
    public string $title = '';
    public string $description = '';
    public bool $sequential = true;
    public bool $is_onboarding = false;
    public bool $issue_certificate = false;
    public ?int $duration_days = null;
    public ?int $pass_score = null;

    // --- Form bài học ---
    public bool $showLessonModal = false;
    public ?int $lessonId = null;
    public string $lessonTitle = '';
    public string $lessonSummary = '';
    public string $contentType = Lesson::TYPE_TEXT;
    public string $contentHtml = '';
    public ?int $documentId = null;
    public ?int $quizId = null;
    public bool $lessonRequired = true;
    public ?int $estimatedMinutes = null;
    public ?int $minWatchPercent = null;

    // --- Form điều kiện gán ---
    public bool $showRuleModal = false;
    public ?int $ruleId = null;
    /** Không chọn phòng nào = giao cho mọi phòng ban. @var array<int, int|string> */
    public array $ruleDepartmentIds = [];
    public ?int $ruleJobTitleId = null;
    public ?int $ruleJobGradeId = null;
    public string $ruleEmploymentStatus = '';
    public bool $ruleIncludeSub = true;
    public bool $ruleMandatory = true;
    public ?int $ruleDueDays = null;

    public function render(): View
    {
        $course = $this->editingCourse();

        return view('livewire.admin.course-builder', [
            'courses' => $this->courses(),
            'editingCourse' => $course,
            'lessons' => $this->lessons(),
            'rules' => $this->rules(),
            'departments' => Department::active()->orderBy('name')->get(),
            'jobTitles' => JobTitle::active()->orderBy('name')->get(),
            'jobGrades' => JobGrade::active()->orderBy('level')->get(),
            'documents' => Document::published()->orderBy('title')->get(),
            'quizzes' => Quiz::published()->orderBy('title')->get(),
            'enrolledCount' => $course ? $course->enrollments()->count() : 0,
        ])->layout('layouts.admin', ['title' => 'Bộ tài liệu ban hành']);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'departmentFilter', 'learningTypeFilter'], true)) {
            $this->resetPage();
        }
    }

    // ---- Khoá học -----------------------------------------------------------

    public function createCourse(): void
    {
        $this->requirePermission('courses.create');

        $this->resetCourseForm();
        $this->showCourseModal = true;
    }

    public function editCourse(int $id): void
    {
        $this->requirePermission('courses.update');

        $course = Course::findOrFail($id);

        $this->courseFormId = $course->id;
        $this->code = $course->code;
        $this->title = $course->title;
        $this->description = (string) $course->description;
        $this->sequential = (bool) $course->sequential;
        $this->is_onboarding = (bool) $course->is_onboarding;
        $this->issue_certificate = (bool) $course->issue_certificate;
        $this->duration_days = $course->duration_days;
        $this->pass_score = $course->pass_score;

        $this->showCourseModal = true;
    }

    public function saveCourse(): void
    {
        $this->requirePermission($this->courseFormId ? 'courses.update' : 'courses.create');

        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'pass_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $payload = array_merge($data, [
            'sequential' => $this->sequential,
            'is_onboarding' => $this->is_onboarding,
            'issue_certificate' => $this->issue_certificate,
        ]);

        if ($this->courseFormId) {
            Course::findOrFail($this->courseFormId)->update($payload);
            session()->flash('status', 'Đã cập nhật khóa học.');
        } else {
            $payload['slug'] = $this->uniqueSlug($data['title']);
            $payload['status'] = Course::STATUS_DRAFT;
            $payload['created_by'] = auth()->id();
            $course = Course::create($payload);
            $this->editingCourseId = $course->id;
            session()->flash('status', 'Đã tạo khóa học. Hãy thêm bài học.');
        }

        $this->showCourseModal = false;
        $this->resetCourseForm();
    }

    public function publishCourse(int $id, CourseBuilderService $service): void
    {
        $this->requirePermission('courses.update');

        $result = $service->publish(Course::findOrFail($id));

        session()->flash($result['ok'] ? 'status' : 'error', $result['message']);
    }

    public function unpublishCourse(int $id): void
    {
        $this->requirePermission('courses.update');

        Course::findOrFail($id)->update(['status' => Course::STATUS_ARCHIVED]);
        session()->flash('status', 'Đã lưu trữ khóa học.');
    }

    public function selectCourse(int $id): void
    {
        $this->editingCourseId = $id;
    }

    public function backToList(): void
    {
        $this->editingCourseId = null;
    }

    // ---- Bài học ------------------------------------------------------------

    public function createLesson(): void
    {
        $this->requirePermission('courses.update');

        $this->resetLessonForm();
        $this->showLessonModal = true;
    }

    public function editLesson(int $id): void
    {
        $this->requirePermission('courses.update');

        $lesson = Lesson::findOrFail($id);

        $this->lessonId = $lesson->id;
        $this->lessonTitle = $lesson->title;
        $this->lessonSummary = (string) $lesson->summary;
        $this->contentType = $lesson->content_type;
        $this->contentHtml = (string) $lesson->content_html;
        $this->documentId = $lesson->document_id;
        $this->quizId = $lesson->quiz_id;
        $this->lessonRequired = (bool) $lesson->is_required;
        $this->estimatedMinutes = $lesson->estimated_minutes;
        $this->minWatchPercent = $lesson->min_watch_percent;

        $this->showLessonModal = true;
    }

    public function saveLesson(CourseBuilderService $service): void
    {
        $this->requirePermission('courses.update');

        $course = $this->editingCourse();

        $this->validate([
            'lessonTitle' => ['required', 'string', 'max:255'],
            'lessonSummary' => ['nullable', 'string', 'max:1000'],
            'contentType' => ['required', Rule::in([
                Lesson::TYPE_TEXT, Lesson::TYPE_DOCUMENT, Lesson::TYPE_VIDEO, Lesson::TYPE_QUIZ,
            ])],
            // Mỗi dạng nội dung có một nguồn bắt buộc riêng
            'contentHtml' => [Rule::requiredIf($this->contentType === Lesson::TYPE_TEXT), 'nullable', 'string'],
            'documentId' => [
                Rule::requiredIf(in_array($this->contentType, [Lesson::TYPE_DOCUMENT, Lesson::TYPE_VIDEO], true)),
                'nullable', Rule::exists('documents', 'id'),
            ],
            'quizId' => [
                Rule::requiredIf($this->contentType === Lesson::TYPE_QUIZ),
                'nullable', Rule::exists('quizzes', 'id'),
            ],
            'estimatedMinutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'minWatchPercent' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'contentHtml.required' => 'Bài dạng văn bản cần có nội dung.',
            'documentId.required' => 'Hãy chọn tài liệu/video từ thư viện.',
            'quizId.required' => 'Hãy chọn bài kiểm tra.',
        ]);

        $payload = [
            'title' => $this->lessonTitle,
            'summary' => $this->lessonSummary ?: null,
            'content_type' => $this->contentType,
            // Xoá nguồn nội dung của các dạng khác, tránh bài học trỏ tới hai nơi
            'content_html' => $this->contentType === Lesson::TYPE_TEXT ? $this->contentHtml : null,
            'document_id' => in_array($this->contentType, [Lesson::TYPE_DOCUMENT, Lesson::TYPE_VIDEO], true)
                ? $this->documentId
                : null,
            'quiz_id' => $this->contentType === Lesson::TYPE_QUIZ ? $this->quizId : null,
            'is_required' => $this->lessonRequired,
            'estimated_minutes' => $this->estimatedMinutes,
            'min_watch_percent' => $this->contentType === Lesson::TYPE_VIDEO ? $this->minWatchPercent : null,
        ];

        if ($this->lessonId) {
            Lesson::findOrFail($this->lessonId)->update($payload);
            session()->flash('status', 'Đã cập nhật bài học.');
        } else {
            $payload['course_id'] = $course->id;
            $payload['sort_order'] = (int) $course->lessons()->max('sort_order') + 1;
            Lesson::create($payload);
            session()->flash('status', 'Đã thêm bài học.');
        }

        // Mẫu số tính tiến độ đổi khi thêm bài hoặc đổi cờ bắt buộc
        $service->refreshEnrollmentProgress($course);

        $this->showLessonModal = false;
        $this->resetLessonForm();
    }

    public function deleteLesson(int $id, CourseBuilderService $service): void
    {
        $this->requirePermission('courses.delete');

        $lesson = Lesson::findOrFail($id);
        $course = $lesson->course;
        $lesson->delete();

        $service->refreshEnrollmentProgress($course);

        session()->flash('status', 'Đã xóa bài học.');
    }

    public function moveLesson(int $id, int $direction, CourseBuilderService $service): void
    {
        $this->requirePermission('courses.update');

        $service->moveLesson(Lesson::findOrFail($id), $direction);
    }

    public function toggleLessonRequired(int $id, CourseBuilderService $service): void
    {
        $this->requirePermission('courses.update');

        $lesson = Lesson::findOrFail($id);
        $lesson->update(['is_required' => ! $lesson->is_required]);

        $service->refreshEnrollmentProgress($lesson->course);
    }

    // ---- Điều kiện gán tự động ----------------------------------------------

    public function createRule(): void
    {
        $this->requirePermission('courses.update');

        $this->resetRuleForm();
        $this->showRuleModal = true;
    }

    public function saveRule(CourseAssignmentService $service): void
    {
        $this->requirePermission('courses.update');

        $this->validate([
            'ruleDepartmentIds' => ['array'],
            'ruleDepartmentIds.*' => ['integer', Rule::exists('departments', 'id')],
            'ruleJobTitleId' => ['nullable', Rule::exists('job_titles', 'id')],
            'ruleJobGradeId' => ['nullable', Rule::exists('job_grades', 'id')],
            'ruleDueDays' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        // Không chọn phòng ban (và không ràng buộc gì khác) là chủ đích: khoá cấp
        // cho toàn bộ nhân viên — modal đã nói rõ điều này nên không chặn nữa.
        $departmentIds = collect($this->ruleDepartmentIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        $rule = CourseAssignmentRule::create([
            'course_id' => $this->editingCourseId,
            'department_id' => null,
            'job_title_id' => $this->ruleJobTitleId,
            'job_grade_id' => $this->ruleJobGradeId,
            'employment_status' => $this->ruleEmploymentStatus ?: null,
            'include_sub_departments' => $this->ruleIncludeSub,
            'is_mandatory' => $this->ruleMandatory,
            'due_days' => $this->ruleDueDays,
            'is_active' => true,
        ]);
        $rule->departments()->sync($departmentIds);

        $assigned = $service->applyRule($rule);

        session()->flash('status', "Đã tạo điều kiện gán và giao khóa học cho {$assigned} nhân viên.");

        $this->showRuleModal = false;
        $this->resetRuleForm();
    }

    public function deleteRule(int $id): void
    {
        $this->requirePermission('courses.delete');

        CourseAssignmentRule::findOrFail($id)->delete();
        session()->flash('status', 'Đã xóa điều kiện gán. Các nhân viên đã được giao vẫn giữ nguyên khóa học.');
    }

    // ---- Truy vấn -----------------------------------------------------------

    private function courses()
    {
        return Course::query()
            // Cột "Phòng ban" gộp từ các điều kiện giao đang bật
            ->with(['assignmentRules' => fn ($q) => $q->active()->with(['departments', 'department'])])
            ->withCount('lessons')
            // Gom điều kiện tìm kiếm trong ngoặc, nếu không orWhere sẽ phá các bộ lọc phía sau
            ->when($this->search, fn ($q) => $q->where(fn ($s) => $s
                ->where('title', 'like', '%' . $this->search . '%')
                ->orWhere('code', 'like', '%' . $this->search . '%')))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->departmentFilter, function ($q) {
                // Khoá được giao cho phòng này/phòng con, hoặc giao cho mọi phòng ban
                $departmentId = (int) $this->departmentFilter;

                $q->whereHas('assignmentRules', fn ($r) => $r->active()->where(fn ($w) => $w
                    ->forAllDepartments()
                    ->orWhere(fn ($t) => $t->targetingDepartment($departmentId))));
            })
            ->when($this->learningTypeFilter, fn ($q) => match ($this->learningTypeFilter) {
                'sequential' => $q->where('sequential', true),
                'free' => $q->where('sequential', false),
                'onboarding' => $q->where('is_onboarding', true),
                'certificate' => $q->where('issue_certificate', true),
                default => $q,
            })
            ->orderByDesc('id')
            ->paginate(12);
    }

    private function editingCourse(): ?Course
    {
        return $this->editingCourseId ? Course::find($this->editingCourseId) : null;
    }

    private function lessons()
    {
        if (! $this->editingCourseId) {
            return collect();
        }

        return Lesson::with(['document', 'quiz'])
            ->where('course_id', $this->editingCourseId)
            ->orderBy('sort_order')
            ->get();
    }

    private function rules()
    {
        if (! $this->editingCourseId) {
            return collect();
        }

        return CourseAssignmentRule::with(['department', 'departments', 'jobTitle', 'jobGrade'])
            ->where('course_id', $this->editingCourseId)
            ->get();
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'khoa-hoc';
        $slug = $base;
        $i = 1;

        while (Course::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }

        return $slug;
    }

    private function resetCourseForm(): void
    {
        $this->reset([
            'courseFormId', 'code', 'title', 'description',
            'duration_days', 'pass_score',
        ]);
        $this->sequential = true;
        $this->is_onboarding = false;
        $this->issue_certificate = false;
        $this->resetValidation();
    }

    private function resetLessonForm(): void
    {
        $this->reset([
            'lessonId', 'lessonTitle', 'lessonSummary', 'contentHtml',
            'documentId', 'quizId', 'estimatedMinutes', 'minWatchPercent',
        ]);
        $this->contentType = Lesson::TYPE_TEXT;
        $this->lessonRequired = true;
        $this->resetValidation();
    }

    private function resetRuleForm(): void
    {
        $this->reset([
            'ruleId', 'ruleDepartmentIds', 'ruleJobTitleId',
            'ruleJobGradeId', 'ruleEmploymentStatus', 'ruleDueDays',
        ]);
        $this->ruleIncludeSub = true;
        $this->ruleMandatory = true;
        $this->resetValidation();
    }
}
