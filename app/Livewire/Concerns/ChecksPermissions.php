<?php

namespace App\Livewire\Concerns;

/**
 * Chặn thao tác khi người dùng không có quyền tương ứng.
 *
 * Route chỉ kiểm quyền Xem của màn hình; từng thao tác Thêm / Sửa / Xóa phải
 * tự kiểm ở đây. Ẩn nút trên giao diện là chưa đủ — request Livewire vẫn gọi
 * thẳng được hàm dù nút không hiện.
 */
trait ChecksPermissions
{
    protected function requirePermission(string $permission): void
    {
        abort_unless(
            auth()->user()?->can($permission),
            403,
            'Bạn không có quyền thực hiện thao tác này.'
        );
    }
}
