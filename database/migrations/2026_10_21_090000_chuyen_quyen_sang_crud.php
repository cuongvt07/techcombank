<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Chuyển quyền từ dạng "xem / quản lý" sang Xem / Thêm / Sửa / Xóa theo màn hình.
 *
 * Quyền cũ "x.manage" được tách thành x.create + x.update + x.delete, nên vai
 * trò nào đang làm được gì thì sau khi chuyển vẫn làm được đúng như vậy — chỉ
 * khác là giờ bỏ được từng thao tác riêng.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    /** Quyền cũ => các quyền mới tương ứng. */
    private const MAP = [
        'accounts.view' => ['accounts.view'],
        'accounts.manage' => ['accounts.create', 'accounts.update', 'accounts.delete'],
        'employees.view' => ['employees.view'],
        'employees.manage' => ['employees.create', 'employees.update'],
        'contracts.view' => ['contracts.view'],
        'contracts.manage' => ['contracts.create', 'contracts.update'],
        'roles.manage' => ['roles.view', 'roles.update'],
        'settings.manage' => ['settings.view', 'settings.create', 'settings.update'],
        // Kho tài nguyên trước đây dùng chung quyền tài liệu
        'documents.view' => ['documents.view', 'files.view'],
        'documents.manage' => ['documents.create', 'documents.update', 'files.create', 'files.update', 'files.delete'],
        'documents.publish' => ['documents.update'],
        'documents.download' => [],
        'documents.manage-access' => ['document_access.view', 'document_access.create', 'document_access.update', 'document_access.delete'],
        'courses.view' => ['courses.view'],
        'courses.manage' => ['courses.create', 'courses.update', 'courses.delete'],
        'courses.publish' => ['courses.update'],
        'quizzes.view' => ['quizzes.view'],
        'quizzes.manage' => ['quizzes.create', 'quizzes.update', 'quizzes.delete'],
        'announcements.view' => ['announcements.view'],
        'announcements.manage' => ['announcements.create', 'announcements.update', 'announcements.delete'],
        'security.view' => ['security.view', 'security.update'],
        'reports.view' => ['dashboard.view'],
        'support.manage' => ['support.view', 'support.update'],
    ];

    public function up(): void
    {
        $permissionId = [];

        foreach (PermissionCatalog::all() as $name) {
            $permissionId[$name] = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $name, 'guard_name' => self::GUARD,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
        }

        $oldIds = DB::table('permissions')
            ->whereIn('name', array_keys(self::MAP))
            ->where('guard_name', self::GUARD)
            ->pluck('id', 'name');

        // Cấp quyền mới cho vai trò theo quyền cũ đang có
        $grants = [];

        foreach (DB::table('role_has_permissions')->whereIn('permission_id', $oldIds->values())->get() as $row) {
            $oldName = $oldIds->search($row->permission_id);

            foreach (self::MAP[$oldName] ?? [] as $newName) {
                $grants[$row->role_id . ':' . $permissionId[$newName]] = [
                    'role_id' => $row->role_id,
                    'permission_id' => $permissionId[$newName],
                ];
            }
        }

        // Trước đây mọi vai trò vào được site quản trị đều xem được Tổng quan
        // mà không cần quyền riêng — giữ nguyên điều đó
        $adminSiteRoles = DB::table('roles')->whereIn('name', ['admin', 'hr'])->pluck('id');

        foreach ($adminSiteRoles as $roleId) {
            $grants[$roleId . ':' . $permissionId['dashboard.view']] = [
                'role_id' => $roleId,
                'permission_id' => $permissionId['dashboard.view'],
            ];
        }

        foreach ($grants as $grant) {
            DB::table('role_has_permissions')->insertOrIgnore($grant);
        }

        // Quyền cấp thẳng cho tài khoản (nếu có) cũng chuyển theo
        foreach (DB::table('model_has_permissions')->whereIn('permission_id', $oldIds->values())->get() as $row) {
            $oldName = $oldIds->search($row->permission_id);

            foreach (self::MAP[$oldName] ?? [] as $newName) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId[$newName],
                    'model_type' => $row->model_type,
                    'model_id' => $row->model_id,
                ]);
            }
        }

        // Quyền cũ không còn dùng — trừ những quyền giữ nguyên tên (vd. accounts.view)
        $obsolete = $oldIds->except(PermissionCatalog::all())->values();

        DB::table('role_has_permissions')->whereIn('permission_id', $obsolete)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $obsolete)->delete();
        DB::table('permissions')->whereIn('id', $obsolete)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Gộp ngược: có bất kỳ quyền Thêm/Sửa/Xóa nào thì coi như có quyền "quản lý"
        $newIds = DB::table('permissions')->whereIn('name', PermissionCatalog::all())->pluck('id', 'name');

        foreach (self::MAP as $oldName => $newNames) {
            $oldId = DB::table('permissions')->where('name', $oldName)->where('guard_name', self::GUARD)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $oldName, 'guard_name' => self::GUARD,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

            $ids = collect($newNames)->map(fn ($n) => $newIds[$n] ?? null)->filter();

            foreach (DB::table('role_has_permissions')->whereIn('permission_id', $ids)->distinct()->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $oldId]);
            }
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $newIds->values())->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $newIds->values())->delete();
        DB::table('permissions')->whereIn('id', $newIds->values())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
