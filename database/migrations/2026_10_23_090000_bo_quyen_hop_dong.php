<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bỏ module Hợp đồng khỏi site quản trị: xóa quyền contracts.* (màn Phân quyền
 * không còn dòng này). Bảng contracts / contract_types giữ nguyên để không mất dữ liệu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('permissions')->where('name', 'like', 'contracts.%')->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $adminId = DB::table('roles')->where('name', 'admin')->value('id');

        foreach (['view', 'create', 'update'] as $action) {
            $id = DB::table('permissions')->insertGetId([
                'name' => "contracts.{$action}", 'guard_name' => 'web',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($adminId) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $adminId, 'permission_id' => $id]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
