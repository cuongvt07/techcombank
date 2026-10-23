<?php

namespace App\Support;

/**
 * Danh mục quyền theo màn hình × thao tác (CRUD).
 *
 * Mỗi màn hình quản trị là một module, quyền đặt tên "{module}.{thao tác}",
 * ví dụ courses.create. Đây là nguồn duy nhất: seeder, migration, màn Phân
 * quyền, sidebar và kiểm tra quyền trong component đều đọc từ đây.
 *
 * Chỉ khai báo thao tác thực sự có ở màn đó — màn không có nút Thêm thì không
 * có cột Thêm, tránh tick một quyền không làm gì.
 */
final class PermissionCatalog
{
    public const VIEW = 'view';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    public const ACTIONS = [
        self::VIEW => 'Xem',
        self::CREATE => 'Thêm',
        self::UPDATE => 'Sửa',
        self::DELETE => 'Xóa',
    ];

    private const CRUD = [self::VIEW, self::CREATE, self::UPDATE, self::DELETE];

    /**
     * Màn Phân quyền chỉ có hai cột: Xem và Thao tác. "Thao tác" gom mọi quyền
     * Thêm / Sửa / Xóa có ở màn đó — tick là được làm tất cả, không chia lẻ.
     * Quyền chi tiết vẫn giữ bên dưới để từng thao tác tự kiểm (requirePermission).
     */
    public const MANAGE = 'manage';

    public const COLUMNS = [
        self::VIEW => 'Xem',
        self::MANAGE => 'Thao tác',
    ];

    /**
     * Các quyền chi tiết mà cột "Thao tác" của một màn bao gồm.
     *
     * @return array<int, string>
     */
    public static function manageActions(string $module): array
    {
        return array_values(array_filter(self::actionsOf($module), fn ($a) => $a !== self::VIEW));
    }

    /**
     * Nhóm => [module => [nhãn, các thao tác có]].
     *
     * @var array<string, array<string, array{0: string, 1: array<int, string>}>>
     */
    public const MODULES = [
        'Quản lý nhân sự' => [
            'dashboard' => ['Tổng quan', [self::VIEW]],
            'accounts' => ['Tài khoản', self::CRUD],
            'employees' => ['Hồ sơ nhân sự', [self::VIEW, self::CREATE, self::UPDATE]],
            'roles' => ['Phân quyền', [self::VIEW, self::UPDATE]],
        ],
        'Tài liệu nội bộ' => [
            'files' => ['Kho tài nguyên', self::CRUD],
            'documents' => ['Thư viện tài liệu', [self::VIEW, self::CREATE, self::UPDATE]],
            'document_access' => ['Phân quyền tài liệu', self::CRUD],
            'courses' => ['Bộ tài liệu ban hành (khóa học)', self::CRUD],
            'quizzes' => ['Bài kiểm tra', self::CRUD],
        ],
        'Sự kiện' => [
            'announcements' => ['Sự kiện & thông báo', self::CRUD],
        ],
        // Báo cáo chỉ đọc; "Sửa" = thao tác ngay trên báo cáo (gửi nhắc, cấp thêm lượt, phân công)
        'Báo cáo' => [
            'report_learning' => ['Báo cáo tiến độ học tập', [self::VIEW, self::UPDATE]],
            'report_quiz' => ['Báo cáo kết quả kiểm tra', [self::VIEW, self::UPDATE]],
            'report_documents' => ['Báo cáo truy cập tài liệu', [self::VIEW]],
            'report_security' => ['Báo cáo bảo mật & đăng nhập', [self::VIEW]],
            'report_support' => ['Báo cáo yêu cầu hỗ trợ', [self::VIEW, self::UPDATE]],
        ],
        'Bảo mật & vận hành' => [
            'security' => ['Trung tâm bảo mật', [self::VIEW, self::UPDATE]],
            'support' => ['Phiếu hỗ trợ', [self::VIEW, self::UPDATE]],
            'settings' => ['Cấu hình chung', [self::VIEW, self::CREATE, self::UPDATE]],
        ],
    ];

    /** Quyền vào site học tập — không thuộc màn quản trị nên không nằm trong bảng. */
    public const LEARNING = 'learning.access';

    public static function name(string $module, string $action): string
    {
        return "{$module}.{$action}";
    }

    /**
     * Mọi tên quyền của các màn quản trị.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $names = [];

        foreach (self::MODULES as $modules) {
            foreach ($modules as $module => [, $actions]) {
                foreach ($actions as $action) {
                    $names[] = self::name($module, $action);
                }
            }
        }

        return $names;
    }

    /**
     * Quyền "Xem" của mọi màn — có ít nhất một quyền này là vào được site quản trị.
     *
     * @return array<int, string>
     */
    public static function viewPermissions(): array
    {
        return array_values(array_filter(self::all(), fn ($p) => str_ends_with($p, '.' . self::VIEW)));
    }

    /** Route màn quản trị => module, để biết cần quyền Xem nào khi dẫn link tới màn đó. */
    public const ROUTE_MODULES = [
        'admin.dashboard' => 'dashboard',
        'admin.accounts' => 'accounts',
        'admin.employees' => 'employees',
        'admin.employees.show' => 'employees',
        'admin.roles' => 'roles',
        'admin.files' => 'files',
        'admin.documents' => 'documents',
        'admin.access-rules' => 'document_access',
        'admin.courses' => 'courses',
        'admin.quizzes' => 'quizzes',
        'admin.announcements' => 'announcements',
        'admin.security' => 'security',
        'admin.support' => 'support',
        'admin.settings' => 'settings',
        'admin.reports.learning' => 'report_learning',
        'admin.reports.quiz' => 'report_quiz',
        'admin.reports.documents' => 'report_documents',
        'admin.reports.security' => 'report_security',
        'admin.reports.support' => 'report_support',
    ];

    public static function viewPermissionForRoute(string $route): string
    {
        return self::name(self::ROUTE_MODULES[$route] ?? 'dashboard', self::VIEW);
    }

    /** @return array<int, string> */
    public static function actionsOf(string $module): array
    {
        foreach (self::MODULES as $modules) {
            if (isset($modules[$module])) {
                return $modules[$module][1];
            }
        }

        return [];
    }
}
