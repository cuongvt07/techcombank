<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ROLE = 'hr';

    private const PERMISSIONS = ['employees.view', 'employees.manage'];

    public function up(): void
    {
        $guard = 'web';
        $now = now();

        $hrRoleId = DB::table('roles')
            ->where('name', self::ROLE)
            ->where('guard_name', $guard)
            ->value('id');

        if (! $hrRoleId) {
            $hrRoleId = DB::table('roles')->insertGetId([
                'name' => self::ROLE,
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::PERMISSIONS as $name) {
            $permissionId = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', $guard)
                ->value('id');

            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $hasPermission = DB::table('role_has_permissions')
                ->where('role_id', $hrRoleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $hasPermission) {
                DB::table('role_has_permissions')->insert([
                    'role_id' => $hrRoleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        // Tài khoản HR mẫu đã có có tài khoản ADMIN trong các bộ dữ liệu cũ.
        $userId = DB::table('users')->where('email', 'hr@techcombank.local')->value('id');
        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', $guard)
            ->value('id');

        if ($userId && $adminRoleId) {
            DB::table('model_has_roles')
                ->where('role_id', $adminRoleId)
                ->where('model_type', 'App\\Models\\User')
                ->where('model_id', $userId)
                ->delete();
        }

        if ($userId) {
            $alreadyAssigned = DB::table('model_has_roles')
                ->where('role_id', $hrRoleId)
                ->where('model_type', 'App\\Models\\User')
                ->where('model_id', $userId)
                ->exists();

            if (! $alreadyAssigned) {
                DB::table('model_has_roles')->insert([
                    'role_id' => $hrRoleId,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $userId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = 'web';
        $hrRoleId = DB::table('roles')
            ->where('name', self::ROLE)
            ->where('guard_name', $guard)
            ->value('id');
        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', $guard)
            ->value('id');
        $userId = DB::table('users')->where('email', 'hr@techcombank.local')->value('id');

        if ($userId && $hrRoleId) {
            DB::table('model_has_roles')
                ->where('role_id', $hrRoleId)
                ->where('model_type', 'App\\Models\\User')
                ->where('model_id', $userId)
                ->delete();

            if ($adminRoleId) {
                DB::table('model_has_roles')->insert([
                    'role_id' => $adminRoleId,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $userId,
                ]);
            }
        }

        if ($hrRoleId) {
            DB::table('role_has_permissions')->where('role_id', $hrRoleId)->delete();
            DB::table('roles')->where('id', $hrRoleId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
