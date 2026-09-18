<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Thêm quyền quản lý sự kiện và gán cho vai trò admin.
 *
 * Chạy seeder lại sẽ tạo quyền nhưng không gán cho vai trò đã tồn tại, nên phải
 * gán ở đây — nếu không admin sẽ thấy menu mà bấm vào bị chặn.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['announcements.view', 'announcements.manage'];

    public function up(): void
    {
        $guard = 'web';
        $adminId = DB::table('roles')->where('name', 'admin')->where('guard_name', $guard)->value('id');

        foreach (self::PERMISSIONS as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id');

            if (! $id) {
                $id = DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'guard_name' => $guard,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($adminId) {
                $daCo = DB::table('role_has_permissions')
                    ->where('role_id', $adminId)->where('permission_id', $id)->exists();

                if (! $daCo) {
                    DB::table('role_has_permissions')->insert([
                        'role_id' => $adminId,
                        'permission_id' => $id,
                    ]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
