<?php

namespace App\Livewire\Admin;

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
 * Tạo tài khoản đồng thời tạo hồ sơ nhân sự tối thiểu, vì hai bảng này
 * luôn đi cặp trong nghiệp vụ — tài khoản không gắn nhân sự sẽ không nhận
 * được khoá học nào (mọi enrollment đều tham chiếu employee_id).
 */
class AccountManager extends Component
{
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
    public string $full_name = '';
    public string $email = '';
    public string $employee_code = '';
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

    public function edit(int $id): void
    {
        $this->requirePermission('accounts.update');

        $user = User::with('employee')->findOrFail($id);

        $this->editingId = $user->id;
        $this->full_name = $user->employee?->full_name ?? $user->name;
        $this->email = $user->email;
        $this->employee_code = $user->employee?->employee_code ?? '';
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
            if ($this->editingId) {
                $user = User::findOrFail($this->editingId);
                $changes = [
                    'name' => $data['full_name'],
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

                $user->employee?->update([
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                ]);

                session()->flash('status', 'Đã cập nhật tài khoản.');

                return;
            }

            $user = User::create([
                'name' => $data['full_name'],
                'email' => $data['email'],
                'password' => $this->password,
                'status' => $data['status'],
                'must_change_password' => $this->must_change_password,
                'email_verified_at' => now(),
            ]);

            $user->assignRole($data['role']);

            $employee = Employee::create([
                'user_id' => $user->id,
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'employment_status' => Employee::STATUS_PROBATION,
                'joined_at' => now(),
                'is_new_hire' => true,
            ]);

            // Nhân viên mới nhận ngay lộ trình onboarding (spec 4.4)
            app(CourseAssignmentService::class)->syncForEmployee($employee);

            session()->flash('status', 'Đã tạo tài khoản và gán lộ trình onboarding.');
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
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            // Tạo mới bắt buộc có mật khẩu; sửa thì để trống nghĩa là giữ nguyên
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::exists('roles', 'name')],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_LOCKED, User::STATUS_DISABLED])],
        ];
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'full_name', 'email', 'employee_code',
            'password', 'role',
        ]);
        $this->status = User::STATUS_ACTIVE;
        $this->must_change_password = true;
        $this->resetValidation();
    }
}
