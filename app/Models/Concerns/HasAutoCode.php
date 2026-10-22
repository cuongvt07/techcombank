<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tự sinh mã nghiệp vụ khi tạo bản ghi, để người dùng không phải tự nghĩ và nhập mã.
 *
 * Model khai báo:
 *   protected const CODE_COLUMN = 'employee_code';
 *   protected const CODE_PREFIX = 'NV';     // phần đứng trước số thứ tự
 *   protected const CODE_PAD = 6;           // số chữ số, đệm 0 bên trái
 *
 * Số thứ tự = số lớn nhất đang có trong các mã cùng tiền tố + 1, tính cả bản
 * ghi đã xóa mềm (cột mã là unique nên mã của bản ghi đã xóa vẫn bị chiếm).
 * Mã nhập tay hoặc import sẵn thì giữ nguyên, chỉ sinh khi còn trống.
 */
trait HasAutoCode
{
    protected static function bootHasAutoCode(): void
    {
        static::creating(function (self $model) {
            $column = static::CODE_COLUMN;

            if (blank($model->{$column})) {
                $model->{$column} = static::nextCode();
            }
        });
    }

    public static function nextCode(): string
    {
        $prefix = static::codePrefix();
        // Tiền tố có năm (vd. HD-2026-) vẫn đánh số liên tục qua các năm
        $family = static::codeFamily();

        $query = in_array(SoftDeletes::class, class_uses_recursive(static::class), true)
            ? static::withTrashed()
            : static::query();

        $max = $query->where(static::CODE_COLUMN, 'like', $family . '%')
            ->pluck(static::CODE_COLUMN)
            ->map(fn ($code) => preg_match('/(\d+)$/', (string) $code, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $prefix . str_pad((string) ($max + 1), static::CODE_PAD, '0', STR_PAD_LEFT);
    }

    /** Tiền tố của mã sắp sinh. Ghi đè nếu tiền tố thay đổi theo thời gian. */
    protected static function codePrefix(): string
    {
        return static::CODE_PREFIX;
    }

    /** Phần cố định dùng để tìm các mã cùng dãy số. */
    protected static function codeFamily(): string
    {
        return static::CODE_PREFIX;
    }
}
