<?php

namespace App\Enums;

/**
 * Vai trò hệ thống.
 *
 * Spec mục 5 nêu 4 vai trò nhưng tự ghi chú là "tham khảo, cần đối chiếu với
 * sơ đồ tổ chức thực tế". Theo quyết định triển khai, rút còn hai vai trò:
 * người quản trị và người học.
 *
 * Lưu ý vận hành: ADMIN có toàn quyền, bao gồm xem hợp đồng và lương của mọi
 * nhân viên. Vì vậy chỉ cấp vai trò này cho người thực sự được phép tiếp cận
 * dữ liệu nhân sự. Nếu về sau cần tách người dựng khóa học khỏi dữ liệu lương,
 * thêm lại một vai trò riêng ở đây và gán tập quyền hẹp hơn trong
 * RolePermissionSeeder — tầng permission bên dưới vẫn giữ nguyên, không phải
 * sửa gì thêm.
 */
enum RoleName: string
{
    case ADMIN = 'admin';
    case EMPLOYEE = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Quản trị viên',
            self::EMPLOYEE => 'Nhân viên',
        };
    }

    /** Các vai trò được vào site quản trị. */
    public static function adminRoles(): array
    {
        return [self::ADMIN->value];
    }
}
