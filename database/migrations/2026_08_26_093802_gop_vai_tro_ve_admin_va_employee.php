<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gộp 5 vai trò còn 2: admin và employee.
 *
 * Các vai trò super-admin / training-manager / hr / department-head đều là
 * vai trò quản trị, nay chuyển hết thành admin. Người dùng đang mang vai trò cũ
 * được chuyển sang admin trước khi xoá vai trò cũ — nếu xoá trước, họ mất sạch
 * quyền mà không có thông báo nào.
 *
 * Quyền reports.view-own-department chỉ phục vụ vai trò Trưởng phòng ban, không
 * còn ai dùng nên xoá luôn.
 */
return new class extends Migration
{
    /** Các vai trò quản trị cũ, gộp hết về admin. */
    private const OLD_ADMIN_ROLES = [
        'super-admin',
        'training-manager',
        'hr',
        'department-head',
    ];

    public function up(): void
    {
        $guard = 'web';

        // 1. Bảo đảm vai trò admin tồn tại
        $adminId = DB::table('roles')->where('name', 'admin')->where('guard_name', $guard)->value('id');

        if (! $adminId) {
            $adminId = DB::table('roles')->insertGetId([
                'name' => 'admin',
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $oldIds = DB::table('roles')
            ->whereIn('name', self::OLD_ADMIN_ROLES)
            ->where('guard_name', $guard)
            ->pluck('id');

        if ($oldIds->isNotEmpty()) {
            // 2. Chuyển người dùng sang admin.
            //    Lấy danh sách trước rồi chèn từng dòng, bỏ qua ai đã là admin —
            //    tránh vi phạm khoá chính khi một người mang nhiều vai trò cũ.
            $userIds = DB::table('model_has_roles')
                ->whereIn('role_id', $oldIds)
                ->where('model_type', \App\Models\User::class)
                ->distinct()
                ->pluck('model_id');

            foreach ($userIds as $userId) {
                $daCo = DB::table('model_has_roles')
                    ->where('role_id', $adminId)
                    ->where('model_id', $userId)
                    ->where('model_type', \App\Models\User::class)
                    ->exists();

                if (! $daCo) {
                    DB::table('model_has_roles')->insert([
                        'role_id' => $adminId,
                        'model_id' => $userId,
                        'model_type' => \App\Models\User::class,
                    ]);
                }
            }

            // 3. Gỡ liên kết vai trò cũ rồi xoá vai trò
            DB::table('model_has_roles')->whereIn('role_id', $oldIds)->delete();
            DB::table('role_has_permissions')->whereIn('role_id', $oldIds)->delete();
            DB::table('roles')->whereIn('id', $oldIds)->delete();
        }

        // 4. Admin nhận toàn bộ quyền
        $permissionIds = DB::table('permissions')->where('guard_name', $guard)->pluck('id');

        foreach ($permissionIds as $permissionId) {
            $daCo = DB::table('role_has_permissions')
                ->where('role_id', $adminId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $daCo) {
                DB::table('role_has_permissions')->insert([
                    'role_id' => $adminId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        // 5. Xoá quyền chỉ dành cho Trưởng phòng ban
        $orphan = DB::table('permissions')
            ->where('name', 'reports.view-own-department')
            ->where('guard_name', $guard)
            ->value('id');

        if ($orphan) {
            DB::table('role_has_permissions')->where('permission_id', $orphan)->delete();
            DB::table('model_has_permissions')->where('permission_id', $orphan)->delete();
            DB::table('permissions')->where('id', $orphan)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Dựng lại các vai trò cũ dưới dạng rỗng.
     *
     * Không khôi phục được ai từng mang vai trò nào — thông tin đó đã mất khi
     * gộp. Người dùng vẫn giữ vai trò admin nên không bị mất quyền truy cập.
     */
    public function down(): void
    {
        $guard = 'web';

        foreach (self::OLD_ADMIN_ROLES as $name) {
            $exists = DB::table('roles')->where('name', $name)->where('guard_name', $guard)->exists();

            if (! $exists) {
                DB::table('roles')->insert([
                    'name' => $name,
                    'guard_name' => $guard,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $exists = DB::table('permissions')
            ->where('name', 'reports.view-own-department')
            ->where('guard_name', $guard)
            ->exists();

        if (! $exists) {
            DB::table('permissions')->insert([
                'name' => 'reports.view-own-department',
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
