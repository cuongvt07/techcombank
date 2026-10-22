@props([
    // Method Livewire nhận định dạng: export('xlsx') | export('csv')
    'action' => 'export',
    'label' => 'Xuất dữ liệu',
])

{{-- Nút "Xuất dữ liệu" có menu chọn định dạng. Xuất đúng các dòng khớp bộ lọc hiện tại. --}}
<div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
    <button type="button" x-on:click="open = ! open" class="admin-btn" aria-haspopup="menu" x-bind:aria-expanded="open">
        @svg('heroicon-o-arrow-down-tray', 'h-4 w-4')
        {{ $label }}
        @svg('heroicon-m-chevron-down', 'h-4 w-4')
    </button>
    <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity
         class="absolute right-0 z-30 mt-1 w-56 rounded-admin border border-brand-line bg-white py-1 shadow-user" role="menu">
        <button type="button" role="menuitem" wire:click="{{ $action }}('xlsx')" x-on:click="open = false"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-brand-soft">
            @svg('heroicon-o-table-cells', 'h-4 w-4 text-brand-muted') Excel (.xlsx)
        </button>
        <button type="button" role="menuitem" wire:click="{{ $action }}('csv')" x-on:click="open = false"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-brand-soft">
            @svg('heroicon-o-document-text', 'h-4 w-4 text-brand-muted') CSV
        </button>
        <p class="border-t border-brand-line px-3 py-2 text-[11px] text-brand-muted">Xuất các dòng khớp bộ lọc hiện tại.</p>
    </div>
</div>
