<?php

namespace App\Livewire\Admin;

use App\Enums\RoleName;
use App\Livewire\Concerns\ChecksPermissions;
use App\Support\PermissionCatalog;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phân quyền theo vai trò (spec 3.1.4): mỗi vai trò một bảng
 * màn hình × Xem / Thêm / Sửa / Xóa.
 *
 * Chỉ cấu hình vai trò Nhân viên (EDITABLE_ROLES). Quản trị viên đã có toàn
 * quyền qua Gate::before; vai trò Nhân sự giữ bộ quyền cố định từ seeder, không
 * chỉnh ở màn này. Muốn mở thêm vai trò thì thêm vào EDITABLE_ROLES.
 */
class PermissionMatrix extends Component
{
    use ChecksPermissions;

    /** Các vai trò được chỉnh quyền ở màn này. */
    private const EDITABLE_ROLES = [RoleName::EMPLOYEE];

    #[Url(as: 'vai-tro')]
    public string $role = '';

    /** @var array<string, bool> [tên quyền] => có/không, của vai trò đang chọn */
    public array $granted = [];

    public bool $dirty = false;

    public function mount(): void
    {
        $roles = $this->editableRoles();

        if (! $roles->contains('name', $this->role)) {
            // Mặc định mở Nhân viên — vai trò hay phải chỉnh nhất
            $this->role = $roles->firstWhere('name', RoleName::EMPLOYEE->value)?->name
                ?? $roles->first()?->name
                ?? '';
        }

        $this->loadGranted();
    }

    public function render(): View
    {
        return view('livewire.admin.permission-matrix', [
            'roles' => $this->editableRoles(),
            'modules' => PermissionCatalog::MODULES,
            'actions' => PermissionCatalog::ACTIONS,
            'canEdit' => auth()->user()->can('roles.update'),
            'totalPermissions' => count(PermissionCatalog::all()),
        ])->layout('layouts.admin', ['title' => 'Phân quyền']);
    }

    /** Đổi vai trò thì bỏ thay đổi chưa lưu của vai trò trước. */
    public function updatedRole(): void
    {
        abort_unless($this->editableRoles()->contains('name', $this->role), 404);

        $this->loadGranted();
    }

    public function toggle(string $module, string $action): void
    {
        $this->requirePermission('roles.update');
        abort_unless(in_array($action, PermissionCatalog::actionsOf($module), true), 404);

        $name = PermissionCatalog::name($module, $action);
        $on = ! ($this->granted[$name] ?? false);
        $this->granted[$name] = $on;

        if ($action === PermissionCatalog::VIEW && ! $on) {
            // Không xem được màn hình thì cũng không thao tác được gì trên đó
            foreach (PermissionCatalog::actionsOf($module) as $other) {
                $this->granted[PermissionCatalog::name($module, $other)] = false;
            }
        } elseif ($action !== PermissionCatalog::VIEW && $on) {
            // Thêm / Sửa / Xóa đều cần vào được màn hình trước
            $this->granted[PermissionCatalog::name($module, PermissionCatalog::VIEW)] = true;
        }

        $this->dirty = true;
    }

    /** Tick / bỏ tick mọi thao tác của một màn hình. */
    public function toggleRow(string $module): void
    {
        $this->requirePermission('roles.update');

        $actions = PermissionCatalog::actionsOf($module);
        abort_if($actions === [], 404);

        $on = ! collect($actions)->every(fn ($a) => $this->granted[PermissionCatalog::name($module, $a)] ?? false);

        foreach ($actions as $action) {
            $this->granted[PermissionCatalog::name($module, $action)] = $on;
        }

        $this->dirty = true;
    }

    /** Số quyền màn hình vai trò đang có (đã lưu) — hiện trên tab vai trò. */
    public function grantedCount(Role $role): int
    {
        return $role->permissions->pluck('name')->intersect(PermissionCatalog::all())->count();
    }

    /** Tick / bỏ tick cả cột của một thao tác. */
    public function toggleColumn(string $action): void
    {
        $this->requirePermission('roles.update');

        $names = collect(PermissionCatalog::all())->filter(fn ($p) => str_ends_with($p, '.' . $action));
        $on = ! $names->every(fn ($p) => $this->granted[$p] ?? false);

        foreach ($names as $name) {
            $this->granted[$name] = $on;

            $module = strstr($name, '.', true);
            if ($action === PermissionCatalog::VIEW && ! $on) {
                foreach (PermissionCatalog::actionsOf($module) as $other) {
                    $this->granted[PermissionCatalog::name($module, $other)] = false;
                }
            } elseif ($on) {
                $this->granted[PermissionCatalog::name($module, PermissionCatalog::VIEW)] = true;
            }
        }

        $this->dirty = true;
    }

    public function save(): void
    {
        $this->requirePermission('roles.update');

        $role = $this->currentRole();
        $catalog = PermissionCatalog::all();

        // Quyền ngoài bảng (vd. learning.access — vào site học tập) giữ nguyên
        $outside = $role->permissions->pluck('name')->reject(fn ($p) => in_array($p, $catalog, true));
        $selected = collect($this->granted)->filter()->keys()->intersect($catalog);

        // Quyền có trong danh mục nhưng chưa có trong bảng permissions (vd. vừa thêm module)
        foreach ($selected as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $role->syncPermissions($outside->merge($selected)->values()->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->dirty = false;
        session()->flash('status', 'Đã lưu phân quyền cho vai trò ' . $this->roleLabel($role->name) . '.');
    }

    public function resetChanges(): void
    {
        $this->loadGranted();
    }

    public function roleLabel(string $name): string
    {
        return RoleName::tryFrom($name)?->label() ?? $name;
    }

    private function loadGranted(): void
    {
        $owned = $this->role !== ''
            ? $this->currentRole()->permissions->pluck('name')->flip()
            : collect();

        $this->granted = collect(PermissionCatalog::all())
            ->mapWithKeys(fn ($p) => [$p => $owned->has($p)])
            ->all();

        $this->dirty = false;
    }

    private function currentRole(): Role
    {
        return $this->editableRoles()->firstWhere('name', $this->role) ?? abort(404);
    }

    private function editableRoles(): Collection
    {
        return Role::with('permissions')
            ->whereIn('name', array_map(fn (RoleName $r) => $r->value, self::EDITABLE_ROLES))
            ->orderBy('name')
            ->get();
    }
}
