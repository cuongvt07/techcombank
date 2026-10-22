<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Vai trò và quyền hệ thống.
 *
 * ADMIN (toàn quyền), HR (quản lý hồ sơ nhân sự) và EMPLOYEE (chỉ học tập).
 *
 * Tầng permission giữ quyền chi tiết theo từng hành động. Mỗi vai trò khai
 * báo tập quyền riêng ở ROLE_PERMISSIONS.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Quyền lấy từ PermissionCatalog (màn hình × Xem/Thêm/Sửa/Xóa), cộng quyền
     * vào site học tập.
     */
    private static function permissions(): array
    {
        return [...PermissionCatalog::all(), PermissionCatalog::LEARNING];
    }

    private const ROLE_PERMISSIONS = [
        RoleName::HR->value => [
            'dashboard.view',
            'employees.view', 'employees.create', 'employees.update',
        ],
        RoleName::EMPLOYEE->value => [
            PermissionCatalog::LEARNING,
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::permissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Admin nhận toàn quyền qua Gate::before ở AuthServiceProvider.
        // Vẫn gán đầy đủ permission vào vai trò để màn hình Phân quyền hiển thị
        // đúng thực tế thay vì hiện một bảng trống.
        Role::findOrCreate(RoleName::ADMIN->value, 'web')
            ->syncPermissions(self::permissions());

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
