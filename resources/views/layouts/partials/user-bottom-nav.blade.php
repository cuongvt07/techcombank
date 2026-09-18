{{--
    Bottom navigation bar — khác biệt lớn nhất so với web thường, vốn dồn hết vào
    menu hamburger (spec 6.5). Chỉ hiện trên mobile/tablet; desktop dùng sidebar.

    Icon dùng Heroicons (SVG inline, không gọi CDN ngoài): biến thể solid cho mục
    đang chọn, outline cho mục còn lại — cách phân biệt quen thuộc của app di động.
--}}
@php
    $tabs = [
        ['route' => 'learn.events', 'label' => 'Sự kiện', 'icon' => 'calendar-days'],
        ['route' => 'learn.courses', 'label' => 'Khóa học', 'icon' => 'academic-cap'],
        ['route' => 'learn.progress', 'label' => 'Tiến độ', 'icon' => 'chart-bar'],
        ['route' => 'learn.documents', 'label' => 'Tài liệu', 'icon' => 'document-text'],
        ['route' => 'learn.profile', 'label' => 'Cá nhân', 'icon' => 'user-circle'],
    ];
@endphp

<nav class="fixed inset-x-0 bottom-0 z-40 border-t border-brand-line bg-white/95 backdrop-blur lg:hidden"
     style="padding-bottom: env(safe-area-inset-bottom);"
     aria-label="Điều hướng chính">
    <ul class="mx-auto flex max-w-lg items-stretch justify-around">
        @foreach ($tabs as $tab)
            @continue(! Route::has($tab['route']))
            @php $active = request()->routeIs($tab['route'] . '*'); @endphp

            <li class="flex-1">
                <a href="{{ route($tab['route']) }}"
                   wire:navigate
                   @class([
                       'flex min-h-[56px] flex-col items-center justify-center gap-1 px-1 py-2 text-[11px] font-bold transition-colors',
                       'text-brand-red' => $active,
                       'text-brand-muted hover:text-brand-ink' => ! $active,
                   ])
                   @if ($active) aria-current="page" @endif>
                    @svg('heroicon-' . ($active ? 's' : 'o') . '-' . $tab['icon'], 'h-6 w-6')
                    {{ $tab['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
