<?php

namespace Database\Seeders;

use App\Enums\RoleName;
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
     * Quyền nhóm theo tài nguyên. Hậu tố thể hiện hành động trong ma trận
     * Xem / Tải / Sửa / Xuất bản / Xóa ở spec 3.1.4.
     */
    private const PERMISSIONS = [
        // Quản lý nhân sự
        'accounts.view', 'accounts.manage',
        'employees.view', 'employees.manage',
        'contracts.view', 'contracts.manage',
        'roles.manage',
        'settings.manage',

        // Tài liệu nội bộ
        'documents.view', 'documents.manage', 'documents.publish', 'documents.download',
        'documents.manage-access',
        'courses.view', 'courses.manage', 'courses.publish',
        'quizzes.view', 'quizzes.manage',

        // Sự kiện & thông báo
        'announcements.view', 'announcements.manage',

        // Bảo mật & vận hành
        'security.view',
        'reports.view',
        'support.manage',

        // Site người dùng
        'learning.access',
    ];

    private const ROLE_PERMISSIONS = [
        RoleName::HR->value => [
            'employees.view',
            'employees.manage',
        ],
        RoleName::EMPLOYEE->value => [
            'learning.access',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Admin nhận toàn quyền qua Gate::before ở AuthServiceProvider.
        // Vẫn gán đầy đủ permission vào vai trò để màn hình Phân quyền hiển thị
        // đúng thực tế thay vì hiện một bảng trống.
        Role::findOrCreate(RoleName::ADMIN->value, 'web')
            ->syncPermissions(self::PERMISSIONS);

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
