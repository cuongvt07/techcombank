<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\HasSearchFilters;
use App\Livewire\Concerns\ChecksPermissions;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAccessRule;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\JobTitle;
use App\Services\DocumentAccessService;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phân quyền tài liệu theo điều kiện cấu hình (spec 3.3.1).
 *
 * Kèm công cụ "thử quyền": chọn một nhân viên và một tài liệu để xem hệ thống
 * quyết định thế nào. Rule allow/deny chồng nhau rất khó suy luận bằng mắt,
 * nên cho thử trực tiếp an toàn hơn là để admin đoán rồi phát hiện sai sau.
 */
class DocumentAccessManager extends Component
{
    use HasSearchFilters;

    use ChecksPermissions;

    use WithPagination;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public string $scopeFilter = '';

    public bool $showModal = false;
    public ?int $editingId = null;
    public ?string $duplicateError = null;

    // Phạm vi áp dụng: một tài liệu cụ thể hoặc cả danh mục
    public string $scope = 'document';
    public ?int $documentId = null;
    public ?int $categoryId = null;

    // Điều kiện
    /** Chọn nhiều phòng ban. Rỗng = toàn công ty. */
    public array $departmentIds = [];
    public ?int $jobTitleId = null;
    /** Phân quyền tài liệu là nơi duy nhất cho chọn nhiều cấp bậc. Rỗng = mọi cấp bậc. */
    public array $jobGradeIds = [];
    public ?int $minGradeLevel = null;
    public string $employmentStatus = '';
    public bool $includeSubDepartments = true;

    // Quyền
    public bool $canView = true;
    public bool $canDownload = false;
    public bool $canPrint = false;
    public string $effect = DocumentAccessRule::EFFECT_ALLOW;
    public int $priority = 0;

    // Công cụ thử quyền
    public bool $showTester = false;
    public ?int $testEmployeeId = null;
    public ?int $testDocumentId = null;
    public ?array $testResult = null;

    public function render(): View
    {
        return view('livewire.admin.document-access-manager', [
            'rules' => $this->rules(),
            'documents' => Document::orderBy('title')->get(),
            'documentCount' => Document::count(),
            'categories' => DocumentCategory::active()->orderBy('name')->get(),
            'departments' => Department::active()->orderBy('name')->get(),
            'jobTitles' => JobTitle::active()->orderBy('name')->get(),
            'jobGrades' => JobGrade::active()->orderBy('level')->get(),
            'employees' => Employee::active()->orderBy('full_name')->limit(300)->get(),
        ])->layout('layouts.admin', ['title' => 'Phân quyền tài liệu']);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'scopeFilter'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->requirePermission('document_access.create');

        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->requirePermission('document_access.update');

        $rule = DocumentAccessRule::findOrFail($id);

        $this->editingId = $rule->id;
        $this->duplicateError = null;
        $this->scope = $rule->document_id ? 'document' : 'category';
        $this->documentId = $rule->document_id;
        $this->categoryId = $rule->document_category_id;
        $this->departmentIds = $rule->departmentIds();
        $this->jobTitleId = $rule->job_title_id;
        $this->jobGradeIds = $rule->gradeIds();
        $this->minGradeLevel = $rule->min_grade_level;
        $this->employmentStatus = (string) $rule->employment_status;
        $this->includeSubDepartments = (bool) $rule->include_sub_departments;
        $this->canView = (bool) $rule->can_view;
        $this->canDownload = (bool) $rule->can_download;
        $this->canPrint = (bool) $rule->can_print;
        $this->effect = $rule->effect;
        $this->priority = $rule->priority;

        $this->showModal = true;
    }

    public function save(): void
    {
        $this->requirePermission($this->editingId ? 'document_access.update' : 'document_access.create');

        $this->validate([
            'documentId' => [Rule::requiredIf($this->scope === 'document'), 'nullable', Rule::exists('documents', 'id')],
            'categoryId' => [Rule::requiredIf($this->scope === 'category'), 'nullable', Rule::exists('document_categories', 'id')],
            'departmentIds' => ['array'],
            'departmentIds.*' => ['integer', 'distinct', Rule::exists('departments', 'id')],
            'jobTitleId' => ['nullable', Rule::exists('job_titles', 'id')],
            'jobGradeIds' => ['array'],
            'jobGradeIds.*' => ['integer', 'distinct', Rule::exists('job_grades', 'id')],
            'minGradeLevel' => ['nullable', 'integer', 'min:0', 'max:100'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
        ], [
            'documentId.required' => 'Hãy chọn tài liệu áp dụng.',
            'categoryId.required' => 'Hãy chọn danh mục áp dụng.',
        ]);

        $payload = [
            'document_id' => $this->scope === 'document' ? $this->documentId : null,
            'document_category_id' => $this->scope === 'category' ? $this->categoryId : null,
            // Phòng ban lưu ở bảng nối, cột cũ để trống
            'department_id' => null,
            'job_title_id' => $this->jobTitleId,
            // Cấp bậc lưu ở bảng nối, cột cũ để trống
            'job_grade_id' => null,
            'min_grade_level' => $this->minGradeLevel,
            'employment_status' => $this->employmentStatus ?: null,
            'include_sub_departments' => $this->includeSubDepartments,
            // Rule chặn không cần cấp quyền gì, ghi false cho rõ ràng
            'can_view' => $this->effect === DocumentAccessRule::EFFECT_DENY ? false : $this->canView,
            'can_download' => $this->effect === DocumentAccessRule::EFFECT_DENY ? false : $this->canDownload,
            'can_print' => $this->effect === DocumentAccessRule::EFFECT_DENY ? false : $this->canPrint,
            'effect' => $this->effect,
            'priority' => $this->priority,
            'is_active' => true,
        ];

        $gradeIds = array_values(array_unique(array_map('intval', $this->jobGradeIds)));
        $departmentIds = array_values(array_unique(array_map('intval', $this->departmentIds)));

        if ($this->duplicateRuleExists($payload, $departmentIds, $gradeIds)) {
            $this->duplicateError = 'Quy tắc này đã tồn tại. Hãy chỉnh sửa quy tắc hiện có thay vì tạo trùng.';

            return;
        }

        if ($this->editingId) {
            $rule = DocumentAccessRule::findOrFail($this->editingId);
            $rule->update($payload);
            $rule->jobGrades()->sync($gradeIds);
            $rule->departments()->sync($departmentIds);
            session()->flash('status', 'Đã cập nhật quy tắc phân quyền.');
        } else {
            $rule = DocumentAccessRule::create($payload);
            $rule->jobGrades()->sync($gradeIds);
            $rule->departments()->sync($departmentIds);
            session()->flash('status', 'Đã tạo quy tắc phân quyền.');
        }

        $this->showModal = false;
        $this->resetForm();
    }

    /** Chặn lưu trùng cùng phạm vi, điều kiện và hiệu lực; bỏ qua chính rule đang sửa. */
    private function duplicateRuleExists(array $payload, array $departmentIds, array $gradeIds): bool
    {
        $departmentIds = $this->normalizedIds($departmentIds);
        $gradeIds = $this->normalizedIds($gradeIds);

        return DocumentAccessRule::query()
            ->with(['departments', 'jobGrades'])
            ->where('document_id', $payload['document_id'])
            ->where('document_category_id', $payload['document_category_id'])
            ->where('job_title_id', $payload['job_title_id'])
            ->where('min_grade_level', $payload['min_grade_level'])
            ->where('employment_status', $payload['employment_status'])
            ->where('include_sub_departments', $payload['include_sub_departments'])
            ->where('effect', $payload['effect'])
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->get()
            ->contains(fn (DocumentAccessRule $rule) =>
                $this->normalizedIds($rule->departmentIds()) === $departmentIds
                && $this->normalizedIds($rule->gradeIds()) === $gradeIds
            );
    }

    /** @param array<int, int|string> $ids
     *  @return array<int, int>
     */
    private function normalizedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    public function toggleActive(int $id): void
    {
        $this->requirePermission('document_access.update');

        $rule = DocumentAccessRule::findOrFail($id);
        $rule->update(['is_active' => ! $rule->is_active]);
    }

    public function delete(int $id): void
    {
        $this->requirePermission('document_access.delete');

        DocumentAccessRule::findOrFail($id)->delete();
        session()->flash('status', 'Đã xóa quy tắc.');
    }

    // ---- Công cụ thử quyền --------------------------------------------------

    public function openTester(): void
    {
        $this->reset(['testEmployeeId', 'testDocumentId', 'testResult']);
        $this->showTester = true;
    }

    /** Chạy đúng service mà site người dùng dùng, nên kết quả phản ánh thực tế. */
    public function runTest(DocumentAccessService $service): void
    {
        $this->validate([
            'testEmployeeId' => ['required', Rule::exists('employees', 'id')],
            'testDocumentId' => ['required', Rule::exists('documents', 'id')],
        ], [
            'testEmployeeId.required' => 'Hãy chọn nhân viên.',
            'testDocumentId.required' => 'Hãy chọn tài liệu.',
        ]);

        $employee = Employee::findOrFail($this->testEmployeeId);
        $document = Document::findOrFail($this->testDocumentId);

        $resolved = $service->resolve($employee, $document);

        $this->testResult = [
            'employee' => $employee->full_name,
            'department' => $employee->department?->name ?? '—',
            'grade' => $employee->jobGrade?->name ?? '—',
            'document' => $document->title,
            'can_view' => $resolved['can_view'],
            // Cờ allow_download của tài liệu là trần cứng, rule không nới được
            'can_download' => $resolved['can_download'] && $document->allow_download,
            'can_print' => $resolved['can_print'],
            'reason' => $resolved['reason'],
            'download_capped' => $resolved['can_download'] && ! $document->allow_download,
        ];
    }

    private function rules()
    {
        return DocumentAccessRule::query()
            ->with(['document', 'category', 'department', 'departments', 'jobTitle', 'jobGrade', 'jobGrades'])
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';
                $query->whereHas('document', fn ($d) => $d->where('title', 'like', $term))
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $term));
            })
            ->when($this->scopeFilter === 'document', fn ($q) => $q->whereNotNull('document_id'))
            ->when($this->scopeFilter === 'category', fn ($q) => $q->whereNotNull('document_category_id'))
            ->when($this->scopeFilter === 'deny', fn ($q) => $q->where('effect', DocumentAccessRule::EFFECT_DENY))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->paginate(15);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'documentId', 'categoryId', 'departmentIds',
            'jobTitleId', 'jobGradeIds', 'minGradeLevel', 'employmentStatus',
            'duplicateError',
        ]);
        $this->scope = 'document';
        $this->includeSubDepartments = true;
        $this->canView = true;
        $this->canDownload = false;
        $this->canPrint = false;
        $this->effect = DocumentAccessRule::EFFECT_ALLOW;
        $this->priority = 0;
        $this->resetValidation();
    }
}
