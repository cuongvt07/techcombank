<?php

namespace App\Enums;

/**
 * Vai trò hệ thống.
 *
 * Spec mục 5 nêu 4 vai trò nhưng tự ghi chú là "tham khảo, cần đối chiếu với
 * sơ đồ tổ chức thực tế". Hệ thống có ba vai trò: quản trị viên, nhân sự và
 * nhân viên; HR được vào site quản trị với quyền giới hạn ở hồ sơ nhân sự.
 *
 * ADMIN có toàn quyền, bao gồm dữ liệu hợp đồng. HR chỉ được cấp quyền xem và
 * quản lý hồ sơ nhân sự theo RolePermissionSeeder.
 */
enum RoleName: string
{
    case ADMIN = 'admin';
    case HR = 'hr';
    case EMPLOYEE = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Quản trị viên',
            self::HR => 'Nhân sự',
            self::EMPLOYEE => 'Nhân viên',
        };
    }

    /** Các vai trò được vào site quản trị. */
    public static function adminRoles(): array
    {
        return [self::ADMIN->value, self::HR->value];
    }
}
