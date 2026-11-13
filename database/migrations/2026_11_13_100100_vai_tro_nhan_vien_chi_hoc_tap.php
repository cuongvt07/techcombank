<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Vai trò Nhân viên (employee) chỉ dùng để học tập (fb6): màn Phân quyền trước
 * đây cấu hình vai trò này nên có thể đã gắn quyền màn quản trị vào nó — ảnh
 * hưởng mọi tài khoản học tập. Đưa về đúng bộ quyền cố định của seeder:
 * chỉ learning.access. Người cần quyền quản trị dùng vai trò Nhân sự / Quản lý.
 *
 * Không có down(): không biết bộ quyền cũ là gì để khôi phục.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'employee')->where('guard_name', 'web')->value('id');

        if (! $roleId) {
            return;
        }

        $learningId = DB::table('permissions')->where('name', 'learning.access')->where('guard_name', 'web')->value('id');

        DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->when($learningId, fn ($q) => $q->where('permission_id', '!=', $learningId))
            ->delete();

        if ($learningId) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $learningId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
