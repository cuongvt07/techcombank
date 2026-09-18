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
 * Hai vai trò: ADMIN (toàn quyền) và EMPLOYEE (chỉ học tập).
 *
 * Tầng permission vẫn giữ chi tiết từng hành động chứ không gộp thành cờ
 * "là admin". Nhờ vậy khi cần tách vai trò hẹp hơn (ví dụ người dựng khóa học
 * không được xem lương), chỉ việc thêm vai trò mới vào ROLE_PERMISSIONS —
 * không phải sửa lại các màn hình đang kiểm tra quyền.
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
