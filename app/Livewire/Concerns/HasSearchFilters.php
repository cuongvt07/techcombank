<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;
use ReflectionObject;
use ReflectionProperty;

/**
 * Nút "Tìm kiếm" và "Đặt lại" cho thanh lọc.
 *
 * Ô tìm kiếm chỉ áp dụng khi bấm "Tìm kiếm" hoặc Enter (wire:model không .live),
 * tránh truy vấn mỗi lần gõ phím. "Đặt lại" đưa mọi bộ lọc nằm trên URL (#[Url])
 * về giá trị mặc định — trừ các thuộc tính điều hướng như tab đang mở.
 *
 * Component có thể tự viết resetFilters() riêng; method của lớp sẽ thắng method của trait.
 */
trait HasSearchFilters
{
    /** Thuộc tính #[Url] không phải bộ lọc — giữ nguyên khi "Đặt lại". */
    protected function filterResetExcept(): array
    {
        return ['tab', 'alertTab', 'editingCourseId', 'editingQuizId', 'role', 'folder'];
    }

    /** Áp dụng ô tìm kiếm: giá trị đã gửi kèm request, chỉ cần về trang 1. */
    public function applySearch(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $names = collect((new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC))
            ->filter(fn (ReflectionProperty $p) => $p->getAttributes(Url::class) !== [])
            ->map(fn (ReflectionProperty $p) => $p->getName())
            ->reject(fn (string $name) => in_array($name, $this->filterResetExcept(), true))
            ->values()
            ->all();

        $this->reset($names);

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
