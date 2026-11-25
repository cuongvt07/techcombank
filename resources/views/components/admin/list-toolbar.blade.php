@props([
    // Thuộc tính Livewire của ô tìm kiếm; null = thanh lọc không có ô tìm kiếm
    'search' => 'search',
    'placeholder' => 'Tìm kiếm...',
    // Tên trường đọc cho trình đọc màn hình (ô tìm kiếm không có nhãn hiển thị — đã có icon kính lúp)
    'searchLabel' => 'Tìm kiếm',
    'id' => null,
    // admin: thanh bo nhẹ ở site Quản trị · user: card mềm ở site Nhân viên
    'variant' => 'admin',
])

@php
    $searchId = $id ?? 'f-search-' . str_replace('.', '-', (string) $search);
@endphp

{{--
    Thanh lọc tách khỏi bảng dữ liệu (fb7 mục 4): một card riêng phía trên bảng.
      [🔍 Tìm theo ...........] [Trạng thái: Tất cả ▾] [Phòng ban: Tất cả ▾] … [Tìm kiếm] [Đặt lại]

    Mỗi bộ lọc dùng <x-admin.list-filter label="…">: tên trường nằm ngay trong ô chọn
    (yêu cầu "Bổ sung tên trường lọc") mà thanh vẫn gọn một hàng như mẫu image71.
    Nút "Tìm kiếm" / "Đặt lại" (yêu cầu "All thanh bar Search thêm button Đặt lại, Tìm kiếm")
    gọi applySearch / resetFilters của trait HasSearchFilters.
--}}
<div role="search" {{ $attributes->class(['list-toolbar', 'list-toolbar-user' => $variant === 'user']) }}>
    @if ($search)
        <div class="list-toolbar-search">
            @svg('heroicon-o-magnifying-glass', 'list-toolbar-search-icon')
            <input id="{{ $searchId }}" type="search"
                   wire:model="{{ $search }}" wire:keydown.enter="applySearch"
                   class="admin-input"
                   aria-label="{{ $searchLabel }}"
                   placeholder="{{ $placeholder }}">
        </div>
    @endif

    {{ $slot }}

    <div class="list-toolbar-actions">
        <button type="button" wire:click="applySearch" @class([$variant === 'user' ? 'user-btn min-h-[42px]' : 'admin-btn'])>
            @svg('heroicon-o-magnifying-glass', 'h-4 w-4')
            Tìm kiếm
        </button>
        <button type="button" wire:click="resetFilters" @class([$variant === 'user' ? 'user-btn-secondary min-h-[42px]' : 'admin-btn-secondary'])>Đặt lại</button>
    </div>
</div>
