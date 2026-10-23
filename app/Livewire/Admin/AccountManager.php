<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\HasSearchFilters;
use App\Livewire\Concerns\ChecksPermissions;
use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CourseAssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

/**
 * Quản lý tài khoản nhân viên (spec 3.1.1).
 *
 * Luồng: tạo Hồ sơ nhân sự trước → tạo Tài khoản bằng cách chọn mã nhân viên
 * từ các hồ sơ chưa có tài khoản. Họ tên, phòng ban, chức danh lấy từ hồ sơ;
 * ở đây chỉ nhập thông tin đăng nhập. Tài khoản phải gắn hồ sơ vì mọi
 * enrollment đều tham chiếu employee_id.
 */
class AccountManager extends Component
{
    use HasSearchFilters;

    use ChecksPermissions;

    use WithPagination;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $roleFilter = '';

    #[Url]
    public string $departmentFilter = '';

    public bool $showModal = false;
    public bool $showSaveConfirm = false;
    public bool $showActionConfirm = false;
    public ?int $editingId = null;
    public ?int $pendingUserId = null;
    public string $pendingAction = '';
    public string $pendingActionTitle = '';
    public string $pendingActionMessage = '';

    // Trường form
    /** Hồ sơ nhân sự gắn với tài khoản (ô chọn có tìm kiếm gửi null khi bỏ chọn). */
    public ?int $employee_id = null;
    public string $email = '';
    public string $password = '';
    public string $role = '';
    public string $status = User::STATUS_ACTIVE;
    public bool $must_change_password = true;

    public function render(): View
    {
        return view('livewire.admin.account-manager', [
            'users' => $this->users(),
            'roles' => Role::orderBy('name')->get(),
            'departments' => Department::active()->orderBy('name')->get(),
            'employeeOptions' => $this->showModal ? $this->employeeOptions() : [],
            'selectedEmployee' => $this->employee_id
                ? Employee::with(['department', 'jobTitle', 'jobGrade'])->find($this->employee_id)
                : null,
        ])->layout('layouts.admin', ['title' => 'Quản lý tài khoản']);
    }

    public function updated($property): void
    {
        // Đổi bộ lọc thì quay về trang 1, tránh rơi vào trang trống
        if (in_array($property, ['search', 'statusFilter', 'roleFilter', 'departmentFilter'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->requirePermission('accounts.create');

        $this->resetForm();
        $this->role = RoleName::EMPLOYEE->value;
        $this->showModal = true;
    }

    /** Chọn hồ sơ: hồ sơ đã có email công việc thì điền sẵn làm email đăng nhập. */
    public function updatedEmployeeId(): void
    {
        $employee = $this->employee_id ? Employee::find($this->employee_id) : null;

        if ($employee?->email && blank($this->email)) {
            $this->email = $employee->email;
        }

        $this->resetValidation('employee_id');
    }

    public function edit(int $id): void
    {
        $this->requirePermission('accounts.update');

        $user = User::with('employee')->findOrFail($id);

        $this->editingId = $user->id;
        $this->employee_id = $user->employee?->id;
        $this->email = $user->email;
        $this->role = $user->getRoleNames()->first() ?? RoleName::EMPLOYEE->value;
        $this->status = $user->status;
        $this->password = '';
        $this->must_change_password = (bool) $user->must_change_password;

        $this->showModal = true;
    }

    public function requestSave(): void
    {
        $this->requirePermission($this->editingId ? 'accounts.update' : 'accounts.create');

        $this->validate($this->rules());
        $this->showSaveConfirm = true;
    }

    public function save(): void
    {
        $this->requirePermission($this->editingId ? 'accounts.update' : 'accounts.create');

        if (! $this->showSaveConfirm) {
            return;
        }

        $data = $this->validate($this->rules());
        $editingSelf = $this->editingId === auth()->id();

        DB::transaction(function () use ($data, $editingSelf) {
            $employee = $data['employee_id'] ? Employee::findOrFail($data['employee_id']) : null;

            if ($this->editingId) {
                $user = User::with('employee')->findOrFail($this->editingId);

                // Tài khoản cũ chưa gắn hồ sơ: gắn vào hồ sơ vừa chọn
                if ($employee && ! $user->employee) {
                    $employee->update(['user_id' => $user->id]);
                }

                $changes = [
                    // Tên hiển thị luôn theo hồ sơ nhân sự
                    'name' => ($user->employee ?? $employee)?->full_name ?? $user->name,
                    'email' => $data['email'],
                    'must_change_password' => $this->must_change_password,
                ];

                if (! $editingSelf) {
                    $changes['status'] = $data['status'];
                }

                $user->fill($changes);

                // Chỉ đổi mật khẩu khi admin thực sự nhập giá trị mới
                if (filled($this->password)) {
                    $user->password = $this->password;
                }

                $user->save();
                if (! $editingSelf) {
                    $user->syncRoles([$data['role']]);
                }

                session()->flash('status', 'Đã cập nhật tài khoản.');

                return;
            }

            $user = User::create([
                'name' => $employee->full_name,
                'email' => $data['email'],
                'password' => $this->password,
                'status' => $data['status'],
                'must_change_password' => $this->must_change_password,
                'email_verified_at' => now(),
            ]);

            $user->assignRole($data['role']);

            $employee->update(['user_id' => $user->id]);

            // Có tài khoản là học được: giao ngay lộ trình theo vị trí + onboarding (spec 4.4)
            app(CourseAssignmentService::class)->syncForEmployee($employee->refresh());

            session()->flash('status', "Đã tạo tài khoản cho {$employee->employee_code} · {$employee->full_name}.");
        });

        $this->showSaveConfirm = false;
        $this->showModal = false;
        $this->resetForm();
    }

    public function prepareStatusChange(int $id): void
    {
        $this->requirePermission('accounts.update');

        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'Không thể tự thay đổi trạng thái tài khoản của chính mình.');

            return;
        }

        $locking = $user->status === User::STATUS_ACTIVE;
        $this->pendingUserId = $user->id;
        $this->pendingAction = 'status';
        $this->pendingActionTitle = $locking ? 'Khóa tài khoản?' : 'Mở khóa tài khoản?';
        $this->pendingActionMessage = $locking
            ? 'Tài khoản sẽ không thể đăng nhập cho đến khi được mở khóa.'
            : 'Tài khoản sẽ được phép đăng nhập lại.';
        $this->showActionConfirm = true;
    }

    public function prepareDelete(int $id): void
    {
        $this->requirePermission('accounts.delete');

        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'Không thể xóa tài khoản đang đăng nhập.');

            return;
        }

        $this->pendingUserId = $user->id;
        $this->pendingAction = 'delete';
        $this->pendingActionTitle = 'Xóa tài khoản?';
        $this->pendingActionMessage = 'Tài khoản sẽ bị ẩn khỏi danh sách và không thể đăng nhập.';
        $this->showActionConfirm = true;
    }

    public function confirmPendingAction(): void
    {
        $this->requirePermission($this->pendingAction === 'delete' ? 'accounts.delete' : 'accounts.update');

        if (! $this->pendingUserId || ! in_array($this->pendingAction, ['status', 'delete'], true)) {
            return;
        }

        $user = User::findOrFail($this->pendingUserId);

        if ($user->id === auth()->id()) {
            $this->showActionConfirm = false;
            session()->flash('error', 'Không thể khóa hoặc xóa tài khoản đang đăng nhập.');

            return;
        }

        if ($this->pendingAction === 'status') {
            $user->update([
                'status' => $user->status === User::STATUS_ACTIVE
                    ? User::STATUS_LOCKED
                    : User::STATUS_ACTIVE,
            ]);

            session()->flash('status', 'Đã cập nhật trạng thái tài khoản.');
        } else {
            $user->delete();
            session()->flash('status', 'Đã xóa tài khoản.');
        }

        $this->showActionConfirm = false;
        $this->reset(['pendingUserId', 'pendingAction', 'pendingActionTitle', 'pendingActionMessage']);
    }

    private function users()
    {
        return User::query()
            ->with(['employee.department', 'roles'])
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';

                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhereHas('employee', fn ($e) => $e->where('employee_code', 'like', $term));
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->roleFilter, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $this->roleFilter)))
            ->when($this->departmentFilter, fn ($q) => $q->whereHas(
                'employee',
                fn ($e) => $e->inDepartment((int) $this->departmentFilter)
            ))
            ->orderByDesc('id')
            ->paginate(15);
    }

    private function rules(): array
    {
        return [
            // Tạo mới: bắt buộc chọn hồ sơ chưa có tài khoản. Sửa: chỉ chọn được khi tài khoản chưa gắn hồ sơ.
            'employee_id' => [
                $this->editingId ? 'nullable' : 'required',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at')->where(fn ($q) => $q
                    ->whereNull('user_id')
                    ->when($this->editingId, fn ($q2) => $q2->orWhere('user_id', $this->editingId))),
            ],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            // Tạo mới bắt buộc có mật khẩu; sửa thì để trống nghĩa là giữ nguyên
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::exists('roles', 'name')],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_LOCKED, User::STATUS_DISABLED])],
        ];
    }

    /**
     * Hồ sơ chọn được: chưa có tài khoản (và hồ sơ đang gắn với tài khoản đang sửa).
     *
     * @return array<int, array{value: int, label: string}>
     */
    private function employeeOptions(): array
    {
        return Employee::query()
            ->with('department:id,name')
            ->where(fn ($q) => $q->whereNull('user_id')
                ->when($this->editingId, fn ($q2) => $q2->orWhere('user_id', $this->editingId)))
            ->whereIn('employment_status', [Employee::STATUS_PROBATION, Employee::STATUS_OFFICIAL])
            ->orderByDesc('id')
            ->get(['id', 'employee_code', 'full_name', 'department_id'])
            ->map(fn (Employee $e) => [
                'value' => $e->id,
                'label' => $e->employee_code . ' · ' . $e->full_name . ($e->department ? ' · ' . $e->department->name : ''),
            ])
            ->all();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'employee_id', 'email',
            'password', 'role',
        ]);
        $this->status = User::STATUS_ACTIVE;
        $this->must_change_password = true;
        $this->resetValidation();
    }
}
