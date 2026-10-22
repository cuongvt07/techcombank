<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Thêm quyền cho nhóm Báo cáo (report_*.view / .update) và cấp cho Quản trị viên
 * để màn Phân quyền hiển thị đúng. Nhân viên không có quyền báo cáo mặc định.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    public function up(): void
    {
        $names = array_values(array_filter(PermissionCatalog::all(), fn ($p) => str_starts_with($p, 'report_')));
        $adminId = DB::table('roles')->where('name', 'admin')->value('id');

        foreach ($names as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $name, 'guard_name' => self::GUARD,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

            if ($adminId) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $adminId, 'permission_id' => $id]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('name', 'like', 'report\_%')->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
