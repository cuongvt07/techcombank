{{--
    Nút "Xuất báo cáo (mẫu)" ▾ trên các màn báo cáo: mở bản in A4 theo khuôn (In / Lưu PDF)
    hoặc tải Excel theo mẫu, giữ nguyên bộ lọc đang xem. Khác "Xuất dữ liệu" (dữ liệu thô).
    Tham số: $url — link trang in (đã kèm bộ lọc), do method templateUrl() của component dựng.
--}}
@php
    $excelUrl = $url . (str_contains($url, '?') ? '&' : '?') . 'dinh-dang=xlsx';
@endphp
<div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
    <button type="button" x-on:click="open = ! open" class="admin-btn-secondary" aria-haspopup="menu" x-bind:aria-expanded="open">
        @svg('heroicon-o-printer', 'h-4 w-4')
        Xuất báo cáo (mẫu)
        @svg('heroicon-m-chevron-down', 'h-4 w-4')
    </button>
    <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity
         class="absolute right-0 z-30 mt-1 w-56 rounded-admin border border-brand-line bg-white py-1 shadow-user" role="menu">
        <a href="{{ $url }}" target="_blank" rel="noopener" role="menuitem" x-on:click="open = false"
           class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-brand-soft">
            @svg('heroicon-o-printer', 'h-4 w-4 text-brand-muted') In / Lưu PDF theo mẫu
        </a>
        <a href="{{ $excelUrl }}" role="menuitem" x-on:click="open = false"
           class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-brand-soft">
            @svg('heroicon-o-table-cells', 'h-4 w-4 text-brand-muted') Excel theo mẫu (.xlsx)
        </a>
        <p class="border-t border-brand-line px-3 py-2 text-[11px] text-brand-muted">Báo cáo có đầu trang, bảng tổng hợp và chân ký — theo bộ lọc hiện tại.</p>
    </div>
</div>
