<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bỏ màn "Sự kiện & thông báo" (fb6): xóa quyền announcements.* (màn Phân quyền
 * không còn dòng này). Bảng announcements / announcement_reads giữ nguyên — lời
 * nhắc học gửi riêng nhân viên vẫn dùng.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('permissions')->where('name', 'like', 'announcements.%')->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $adminId = DB::table('roles')->where('name', 'admin')->value('id');

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $id = DB::table('permissions')->insertGetId([
                'name' => "announcements.{$action}", 'guard_name' => 'web',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($adminId) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $adminId, 'permission_id' => $id]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
