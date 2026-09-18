<?php

namespace App\Livewire\Admin;

use App\Enums\RoleName;
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
    use WithPagination;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $roleFilter = '';

    public bool $showModal = false;
    public ?int $editingId = null;

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
        ])->layout('layouts.admin', ['title' => 'Quản lý tài khoản']);
    }

    public function updated($property): void
    {
        // Đổi bộ lọc thì quay về trang 1, tránh rơi vào trang trống
        if (in_array($property, ['search', 'statusFilter', 'roleFilter'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->role = RoleName::EMPLOYEE->value;
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
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

    public function save(): void
    {
        $data = $this->validate($this->rules());

        DB::transaction(function () use ($data) {
            if ($this->editingId) {
                $user = User::findOrFail($this->editingId);
                $user->fill([
                    'name' => $data['full_name'],
                    'email' => $data['email'],
                    'status' => $data['status'],
                    'must_change_password' => $this->must_change_password,
                ]);

                // Chỉ đổi mật khẩu khi admin thực sự nhập giá trị mới
                if (filled($this->password)) {
                    $user->password = $this->password;
                }

                $user->save();
                $user->syncRoles([$data['role']]);

                $user->employee?->update([
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'employee_code' => $data['employee_code'],
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
                'employee_code' => $data['employee_code'],
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

        $this->showModal = false;
        $this->resetForm();
    }

    /** Khoá/mở khoá nhanh từ bảng, không cần mở form (spec 3.1.1). */
    public function toggleStatus(int $id): void
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'Không thể tự khóa tài khoản của chính mình.');

            return;
        }

        $user->update([
            'status' => $user->status === User::STATUS_ACTIVE
                ? User::STATUS_LOCKED
                : User::STATUS_ACTIVE,
        ]);

        session()->flash('status', 'Đã đổi trạng thái tài khoản.');
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
            ->orderByDesc('id')
            ->paginate(15);
    }

    private function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'employee_code' => [
                'required', 'string', 'max:50',
                Rule::unique('employees', 'employee_code')
                    ->ignore($this->editingId, 'user_id')
                    ->whereNull('deleted_at'),
            ],
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
