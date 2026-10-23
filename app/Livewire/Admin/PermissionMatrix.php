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
            'columns' => PermissionCatalog::COLUMNS,
            'canEdit' => auth()->user()->can('roles.update'),
            'totalPermissions' => count(PermissionCatalog::viewPermissions()),
        ])->layout('layouts.admin', ['title' => 'Phân quyền']);
    }

    /** Đổi vai trò thì bỏ thay đổi chưa lưu của vai trò trước. */
    public function updatedRole(): void
    {
        abort_unless($this->editableRoles()->contains('name', $this->role), 404);

        $this->loadGranted();
    }

    /**
     * Bật/tắt một ô. $column = 'view' (Xem) hoặc 'manage' (Thao tác = mọi Thêm/Sửa/Xóa của màn).
     */
    public function toggle(string $module, string $column): void
    {
        $this->requirePermission('roles.update');

        $view = PermissionCatalog::name($module, PermissionCatalog::VIEW);

        if ($column === PermissionCatalog::VIEW) {
            abort_unless(in_array(PermissionCatalog::VIEW, PermissionCatalog::actionsOf($module), true), 404);
            $on = ! ($this->granted[$view] ?? false);
            $this->granted[$view] = $on;

            if (! $on) {
                // Không xem được màn hình thì cũng không thao tác được gì trên đó
                $this->setManage($module, false);
            }
        } elseif ($column === PermissionCatalog::MANAGE) {
            abort_if(PermissionCatalog::manageActions($module) === [], 404);
            $on = ! $this->hasManage($module);
            $this->setManage($module, $on);

            if ($on) {
                // Thao tác cần vào được màn hình trước
                $this->granted[$view] = true;
            }
        } else {
            abort(404);
        }

        $this->dirty = true;
    }

    /** Màn có đủ mọi quyền Thêm/Sửa/Xóa không (ô "Thao tác" được tick). */
    public function hasManage(string $module): bool
    {
        $actions = PermissionCatalog::manageActions($module);

        return $actions !== [] && collect($actions)->every(fn ($a) => $this->granted[PermissionCatalog::name($module, $a)] ?? false);
    }

    /** Có một phần quyền thao tác (dữ liệu cũ cấp lẻ từng quyền) — hiện ô dạng "một phần". */
    public function hasPartialManage(string $module): bool
    {
        return ! $this->hasManage($module)
            && collect(PermissionCatalog::manageActions($module))->contains(fn ($a) => $this->granted[PermissionCatalog::name($module, $a)] ?? false);
    }

    private function setManage(string $module, bool $on): void
    {
        foreach (PermissionCatalog::manageActions($module) as $action) {
            $this->granted[PermissionCatalog::name($module, $action)] = $on;
        }
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

    /** Số màn vai trò được xem (đã lưu) — hiện cạnh tên vai trò. */
    public function grantedCount(Role $role): int
    {
        return $role->permissions->pluck('name')->intersect(PermissionCatalog::viewPermissions())->count();
    }

    /** Tick / bỏ tick cả cột của một thao tác. */
    public function toggleColumn(string $column): void
    {
        $this->requirePermission('roles.update');
        abort_unless(array_key_exists($column, PermissionCatalog::COLUMNS), 404);

        $modules = collect(PermissionCatalog::MODULES)->flatMap(fn ($group) => array_keys($group));

        if ($column === PermissionCatalog::VIEW) {
            $on = ! $modules->every(fn ($m) => $this->granted[PermissionCatalog::name($m, PermissionCatalog::VIEW)] ?? false);

            foreach ($modules as $m) {
                $this->granted[PermissionCatalog::name($m, PermissionCatalog::VIEW)] = $on;
                if (! $on) {
                    $this->setManage($m, false);
                }
            }
        } else {
            $withManage = $modules->filter(fn ($m) => PermissionCatalog::manageActions($m) !== []);
            $on = ! $withManage->every(fn ($m) => $this->hasManage($m));

            foreach ($withManage as $m) {
                $this->setManage($m, $on);
                if ($on) {
                    $this->granted[PermissionCatalog::name($m, PermissionCatalog::VIEW)] = true;
                }
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
