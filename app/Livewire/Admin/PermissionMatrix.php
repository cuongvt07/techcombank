<?php

namespace App\Livewire\Admin;

use App\Enums\RoleName;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ma trận phân quyền theo vai trò (spec 3.1.4).
 *
 * Bật/tắt trực tiếp trên lưới thay vì mở form riêng cho từng vai trò —
 * nghiệp vụ ở đây là so sánh chéo vai trò × quyền, nên lưới là dạng đọc đúng nhất.
 */
class PermissionMatrix extends Component
{
    /** @var array<string, array<string, bool>> [role_name][permission_name] => bool */
    public array $matrix = [];

    public bool $dirty = false;

    /** Nhóm quyền theo tài nguyên để lưới đọc được, thay vì một danh sách phẳng 20+ dòng. */
    private const GROUP_LABELS = [
        'accounts' => 'Tài khoản',
        'employees' => 'Hồ sơ nhân sự',
        'contracts' => 'Hợp đồng',
        'roles' => 'Phân quyền hệ thống',
        'settings' => 'Cấu hình chung',
        'documents' => 'Tài liệu nội bộ',
        'courses' => 'Bộ tài liệu / khóa học',
        'quizzes' => 'Bài kiểm tra',
        'security' => 'Bảo mật',
        'reports' => 'Báo cáo',
        'support' => 'Hỗ trợ',
        'learning' => 'Học tập',
    ];

    private const ACTION_LABELS = [
        'view' => 'Xem',
        'manage' => 'Quản lý',
        'publish' => 'Xuất bản',
        'download' => 'Tải xuống',
        'manage-access' => 'Cấu hình quyền',
        'access' => 'Truy cập',
    ];

    public function mount(): void
    {
        $this->loadMatrix();
    }

    public function render(): View
    {
        return view('livewire.admin.permission-matrix', [
            'roles' => $this->editableRoles(),
            'groupedPermissions' => $this->groupedPermissions(),
            'adminRole' => RoleName::ADMIN->value,
        ])->layout('layouts.admin', ['title' => 'Phân quyền']);
    }

    public function toggle(string $role, string $permission): void
    {
        $this->matrix[$role][$permission] = ! ($this->matrix[$role][$permission] ?? false);
        $this->dirty = true;
    }

    /** Bật/tắt cả một nhóm quyền cho một vai trò. */
    public function toggleGroup(string $role, string $group): void
    {
        $permissions = $this->groupedPermissions()[$group] ?? collect();

        // Nếu đang bật hết thì tắt hết, ngược lại bật hết
        $allEnabled = $permissions->every(fn ($p) => $this->matrix[$role][$p->name] ?? false);

        foreach ($permissions as $permission) {
            $this->matrix[$role][$permission->name] = ! $allEnabled;
        }

        $this->dirty = true;
    }

    public function save(): void
    {
        foreach ($this->editableRoles() as $role) {
            $granted = collect($this->matrix[$role->name] ?? [])
                ->filter()
                ->keys()
                ->all();

            $role->syncPermissions($granted);
        }

        // Xoá cache quyền, nếu không thay đổi sẽ chỉ có hiệu lực sau khi cache hết hạn
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->dirty = false;
        session()->flash('status', 'Đã lưu ma trận phân quyền.');
    }

    public function resetChanges(): void
    {
        $this->loadMatrix();
        $this->dirty = false;
    }

    public function groupLabel(string $group): string
    {
        return self::GROUP_LABELS[$group] ?? Str::headline($group);
    }

    public function actionLabel(string $permission): string
    {
        $action = Str::after($permission, '.');

        return self::ACTION_LABELS[$action] ?? Str::headline($action);
    }

    private function loadMatrix(): void
    {
        $permissions = Permission::orderBy('name')->get();
        $matrix = [];

        foreach ($this->editableRoles() as $role) {
            $rolePermissions = $role->permissions->pluck('name')->flip();

            foreach ($permissions as $permission) {
                $matrix[$role->name][$permission->name] = $rolePermissions->has($permission->name);
            }
        }

        $this->matrix = $matrix;
    }

    /**
     * Quản trị viên không xuất hiện trong lưới: vai trò này đã được Gate::before
     * cho qua mọi kiểm tra, hiển thị ô tick sẽ gây hiểu nhầm là sửa được.
     *
     * Hệ thống hiện chỉ có hai vai trò nên lưới còn đúng một dòng (Nhân viên).
     * Màn hình vẫn giữ vì tầng permission bên dưới không đổi — thêm vai trò mới
     * là lưới tự có thêm dòng, không phải sửa gì ở đây.
     */
    private function editableRoles()
    {
        return Role::with('permissions')
            ->where('name', '!=', RoleName::ADMIN->value)
            ->orderBy('name')
            ->get();
    }

    private function groupedPermissions()
    {
        return Permission::orderBy('name')
            ->get()
            ->groupBy(fn (Permission $p) => Str::before($p->name, '.'));
    }
}
