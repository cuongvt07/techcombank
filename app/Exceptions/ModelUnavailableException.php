<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Một model AI cụ thể không dùng được — nên thử model dự phòng.
 *
 * Tách riêng khỏi RuntimeException để phân biệt hai loại hỏng:
 *  - Hỏng ở model  (bị khóa, bị gỡ, quá tải, trả rỗng) → đổi model là xong
 *  - Hỏng ở tài khoản (sai khóa API, chạm hạn mức)     → đổi model vô ích
 *
 * Chỉ loại đầu mới đáng để thử lại; loại sau chỉ làm người dùng chờ thêm.
 */
class ModelUnavailableException extends RuntimeException
{
}
