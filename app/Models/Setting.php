<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Cấu hình chung dạng key-value (spec 3.1.5).
 * Có cache vì các giá trị này được đọc ở hầu hết request (logo, tên hệ thống, ngưỡng cảnh báo).
 */
class Setting extends Model
{
    private const CACHE_KEY = 'lms.settings';

    protected $fillable = ['key', 'value', 'type', 'group', 'label', 'description'];

    protected static function booted(): void
    {
        // Cache phải bị xoá ở cả hai chiều, kể cả khi admin xoá một khoá cấu hình
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $settings = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::all()->keyBy('key')
        );

        $setting = $settings->get($key);

        return $setting ? $setting->castValue() : $default;
    }

    public static function set(string $key, mixed $value, string $type = 'string', string $group = 'general'): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            [
                'value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
                'type' => $type,
                'group' => $group,
            ]
        );
    }

    public function castValue(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }
}
