<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';
        $now = now();

        $roleId = DB::table('roles')
            ->where('name', 'manager')
            ->where('guard_name', $guard)
            ->value('id');

        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'manager',
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionId = DB::table('permissions')
            ->where('name', 'learning.access')
            ->where('guard_name', $guard)
            ->value('id');

        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'learning.access',
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('role_has_permissions')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roleId = DB::table('roles')
            ->where('name', 'manager')
            ->where('guard_name', 'web')
            ->value('id');

        if ($roleId) {
            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
            DB::table('model_has_roles')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
