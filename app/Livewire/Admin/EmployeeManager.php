<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ChecksPermissions;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\JobTitle;
use App\Services\CourseAssignmentService;
use App\Services\EmployeeService;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Hồ sơ nhân sự (spec 3.1.2): thông tin cá nhân, vị trí trong tổ chức,
 * quản lý trực tiếp và lịch sử thay đổi vị trí.
 */
class EmployeeManager extends Component
{
    use ChecksPermissions;

    use WithPagination;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public string $departmentFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $jobTitleFilter = '';

    #[Url]
    public string $jobGradeFilter = '';

    #[Url]
    public string $joinedYearFilter = '';

    /** @var array<int, string> Id nhan vien dang duoc chon de thao tac hang loat */
    public array $selected = [];

    public bool $selectPage = false;

    public bool $showModal = false;
    public bool $showHistory = false;
    public bool $showBulkAssign = false;
    public ?int $bulkCourseId = null;
    public ?int $bulkDueDays = null;
    public ?int $editingId = null;
    public ?int $historyEmployeeId = null;

    // Trường form
    public string $full_name = '';
    public string $employee_code = '';
    public string $email = '';
    public string $phone = '';
    public ?string $date_of_birth = null;
    public string $gender = '';
    public ?int $department_id = null;
    public ?int $job_title_id = null;
    /** @var array<int, int|string> */
    /** Hồ sơ nhân sự chỉ một cấp bậc; chọn nhiều cấp bậc chỉ có ở phân quyền tài liệu. */
    public ?int $job_grade_id = null;
    public ?int $manager_id = null;
    public string $employment_status = Employee::STATUS_PROBATION;
    public ?string $joined_at = null;
    public string $change_reason = '';

    public function render(): View
    {
        return view('livewire.admin.employee-manager', [
            'employees' => $this->employees(),
            'departments' => Department::active()->orderBy('name')->get(),
            'jobTitles' => JobTitle::active()->orderBy('name')->get(),
            'jobGrades' => JobGrade::active()->orderBy('level')->get(),
            'joinedYears' => $this->joinedYears(),
            'managers' => Employee::active()->orderBy('full_name')->limit(200)->get(),
            'histories' => $this->histories(),
            // Nạp ở đây thay vì query trong view: view không nên chạm CSDL
            'publishedCourses' => $this->showBulkAssign
                ? Course::published()->orderBy('title')->get()
                : collect(),
        ])->layout('layouts.admin', ['title' => 'Hồ sơ nhân sự']);
    }

    public function updated($property): void
    {
        $filters = ['search', 'departmentFilter', 'statusFilter', 'jobTitleFilter', 'jobGradeFilter', 'joinedYearFilter'];

        if (in_array($property, $filters, true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->requirePermission('employees.create');

        $this->resetForm();
        $this->joined_at = now()->toDateString();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->requirePermission('employees.update');

        $employee = Employee::findOrFail($id);

        $this->editingId = $employee->id;
        $this->full_name = $employee->full_name;
        $this->employee_code = $employee->employee_code;
        $this->email = (string) $employee->email;
        $this->phone = (string) $employee->phone;
        $this->date_of_birth = $employee->date_of_birth?->toDateString();
        $this->gender = (string) $employee->gender;
        $this->department_id = $employee->department_id;
        $this->job_title_id = $employee->job_title_id;
        // Hồ sơ cũ có thể còn nhiều cấp bậc trong bảng nối: lấy cấp cao nhất
        $this->job_grade_id = $employee->job_grade_id
            ?? $employee->jobGrades()->reorder()->orderByDesc('level')->value('job_grades.id');
        $this->manager_id = $employee->manager_id;
        $this->employment_status = $employee->employment_status;
        $this->joined_at = $employee->joined_at?->toDateString();
        $this->change_reason = '';

        $this->showModal = true;
    }

    public function save(EmployeeService $service): void
    {
        $this->requirePermission($this->editingId ? 'employees.update' : 'employees.create');

        $data = $this->validate($this->rules());
        $primaryGradeId = $data['job_grade_id'] ?: null;
        // Bảng nối vẫn được đối chiếu ở phân quyền tài liệu, giữ nó khớp đúng một cấp bậc
        $gradeIds = $primaryGradeId ? [$primaryGradeId] : [];

        if ($this->editingId) {
            $employee = Employee::findOrFail($this->editingId);

            // Thông tin cá nhân cập nhật trực tiếp; các trường ảnh hưởng tới quyền
            // đi qua service để ghi audit trail và gán lại khoá học (spec 3.1.2 + 3.3.1).
            $employee->update([
                'full_name' => $data['full_name'],
                'email' => $data['email'] ?: null,
                'phone' => $data['phone'] ?: null,
                'date_of_birth' => $data['date_of_birth'] ?: null,
                'gender' => $data['gender'] ?: null,
                'manager_id' => $data['manager_id'],
                'joined_at' => $data['joined_at'] ?: null,
            ]);

            $assigned = $service->reassign(
                $employee,
                [
                    'department_id' => $data['department_id'],
                    'job_title_id' => $data['job_title_id'],
                    'job_grade_id' => $primaryGradeId,
                    'employment_status' => $data['employment_status'],
                ],
                $this->change_reason ?: null,
                auth()->id(),
            );
            $employee->jobGrades()->sync($gradeIds);

            session()->flash('status', $assigned > 0
                ? "Đã cập nhật hồ sơ và gán thêm {$assigned} khóa học theo vị trí mới."
                : 'Đã cập nhật hồ sơ nhân sự.');
        } else {
            $employee = Employee::create([
                'full_name' => $data['full_name'],
                'email' => $data['email'] ?: null,
                'phone' => $data['phone'] ?: null,
                'date_of_birth' => $data['date_of_birth'] ?: null,
                'gender' => $data['gender'] ?: null,
                'department_id' => $data['department_id'],
                'job_title_id' => $data['job_title_id'],
                'job_grade_id' => $primaryGradeId,
                'manager_id' => $data['manager_id'],
                'employment_status' => $data['employment_status'],
                'joined_at' => $data['joined_at'] ?: null,
                'is_new_hire' => $data['employment_status'] === Employee::STATUS_PROBATION,
            ]);

            $employee->jobGrades()->sync($gradeIds);

            $service->recordInitialAssignment($employee, auth()->id());

            session()->flash('status', 'Đã tạo hồ sơ nhân sự.');
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function resign(int $id, EmployeeService $service): void
    {
        $this->requirePermission('employees.update');

        $employee = Employee::findOrFail($id);
        $service->resign($employee, 'Nghỉ việc', auth()->id());

        session()->flash('status', 'Đã ghi nhận nghỉ việc và khóa tài khoản truy cập.');
    }

    public function viewHistory(int $id): void
    {
        $this->historyEmployeeId = $id;
        $this->showHistory = true;
    }

    // ---- Thao tác hàng loạt (spec 6.4: bulk-action đi kèm bảng) --------------

    /** Chọn/bỏ chọn toàn bộ dòng đang hiển thị trên trang hiện tại. */
    public function updatedSelectPage(bool $value): void
    {
        $this->selected = $value
            ? $this->employees()->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectPage = false;
    }

    public function openBulkAssign(): void
    {
        $this->requirePermission('employees.update');

        $this->reset(['bulkCourseId', 'bulkDueDays']);
        $this->showBulkAssign = true;
    }

    /** Giao một khoá học cho toàn bộ nhân viên đang chọn. */
    public function bulkAssignCourse(CourseAssignmentService $service): void
    {
        $this->requirePermission('employees.update');

        $this->validate([
            'bulkCourseId' => ['required', 'exists:courses,id'],
            'bulkDueDays' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [
            'bulkCourseId.required' => 'Hãy chọn khóa học cần giao.',
        ]);

        $course = Course::findOrFail($this->bulkCourseId);
        $assigned = 0;

        foreach (Employee::whereIn('id', $this->selected)->get() as $employee) {
            // Nhân viên đã nghỉ việc không nhận thêm khoá mới
            if (! $employee->isActive()) {
                continue;
            }

            $service->assignManually($employee, $course, dueDays: $this->bulkDueDays, assignedBy: auth()->id());
            $assigned++;
        }

        session()->flash('status', "Đã giao khóa học cho {$assigned} nhân viên.");

        $this->showBulkAssign = false;
        $this->clearSelection();
    }

    /** Chuyển hàng loạt nhân viên thử việc sang chính thức. */
    public function bulkMakeOfficial(EmployeeService $service): void
    {
        $this->requirePermission('employees.update');

        $count = 0;

        foreach (Employee::whereIn('id', $this->selected)->get() as $employee) {
            if ($employee->employment_status === Employee::STATUS_PROBATION) {
                $service->makeOfficial($employee, auth()->id());
                $count++;
            }
        }

        session()->flash('status', "Đã chuyển {$count} nhân viên sang chính thức.");
        $this->clearSelection();
    }

    private function employees()
    {
        return Employee::query()
            ->with(['department', 'jobTitle', 'jobGrade', 'jobGrades', 'manager', 'user'])
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';

                $query->where(fn ($q) => $q
                    ->where('full_name', 'like', $term)
                    ->orWhere('employee_code', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($this->departmentFilter, fn ($q) => $q->inDepartment((int) $this->departmentFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('employment_status', $this->statusFilter))
            ->when($this->jobTitleFilter, fn ($q) => $q->where('job_title_id', (int) $this->jobTitleFilter))
            // Cấp bậc lưu ở cột job_grade_id và bảng nối — khớp một trong hai
            ->when($this->jobGradeFilter, fn ($q) => $q->where(fn ($g) => $g
                ->where('job_grade_id', (int) $this->jobGradeFilter)
                ->orWhereHas('jobGrades', fn ($j) => $j->whereKey((int) $this->jobGradeFilter))))
            ->when($this->joinedYearFilter, fn ($q) => $q->whereYear('joined_at', (int) $this->joinedYearFilter))
            ->orderBy('full_name')
            ->paginate(15);
    }

    /** Các năm có nhân viên vào làm, mới nhất trước — để lọc theo năm vào làm. */
    private function joinedYears()
    {
        return Employee::query()
            ->whereNotNull('joined_at')
            ->pluck('joined_at')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();
    }

    private function histories()
    {
        if (! $this->historyEmployeeId) {
            return collect();
        }

        return Employee::find($this->historyEmployeeId)
            ?->assignmentHistories()
            ->with(['department', 'jobTitle', 'jobGrade', 'changedBy'])
            ->get() ?? collect();
    }

    private function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'department_id' => ['nullable', Rule::exists('departments', 'id')],
            'job_title_id' => ['nullable', Rule::exists('job_titles', 'id')],
            'job_grade_id' => ['nullable', 'integer', Rule::exists('job_grades', 'id')],
            // Không cho chọn chính mình làm quản lý trực tiếp
            'manager_id' => ['nullable', Rule::exists('employees', 'id'), Rule::notIn([$this->editingId])],
            'employment_status' => ['required', Rule::in([
                Employee::STATUS_PROBATION,
                Employee::STATUS_OFFICIAL,
                Employee::STATUS_RESIGNED,
                Employee::STATUS_SUSPENDED,
            ])],
            'joined_at' => ['nullable', 'date'],
        ];
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'full_name', 'employee_code', 'email', 'phone',
            'date_of_birth', 'gender', 'department_id', 'job_title_id',
            'job_grade_id', 'manager_id', 'joined_at', 'change_reason',
        ]);
        $this->employment_status = Employee::STATUS_PROBATION;
        $this->resetValidation();
    }
}
