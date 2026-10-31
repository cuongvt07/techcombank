<?php

namespace App\Enums;

/**
 * Vai trò hệ thống.
 *
 * Spec mục 5 nêu 4 vai trò nhưng tự ghi chú là "tham khảo, cần đối chiếu với
 * sơ đồ tổ chức thực tế". Hệ thống có bốn vai trò: quản trị viên, nhân sự,
 * quản lý và nhân viên; HR được vào site quản trị với quyền giới hạn ở hồ sơ
 * nhân sự, còn quyền của quản lý được cấu hình tại màn Phân quyền.
 *
 * ADMIN có toàn quyền, bao gồm dữ liệu hợp đồng. HR chỉ được cấp quyền xem và
 * quản lý hồ sơ nhân sự theo RolePermissionSeeder.
 */
enum RoleName: string
{
    case ADMIN = 'admin';
    case HR = 'hr';
    case MANAGER = 'manager';
    case EMPLOYEE = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Quản trị viên',
            self::HR => 'Nhân sự',
            self::MANAGER => 'Quản lý',
            self::EMPLOYEE => 'Nhân viên',
        };
    }

    /** Các vai trò được vào site quản trị. */
    public static function adminRoles(): array
    {
        return [self::ADMIN->value, self::HR->value];
    }
}
