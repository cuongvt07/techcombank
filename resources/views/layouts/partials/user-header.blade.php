{{--
    Header sticky tối giản trên mobile: nút back — tiêu đề — tiến độ (spec 6.5).
    Không lặp lại toàn bộ menu như desktop; điều hướng chính nằm ở bottom tab bar.
--}}
<header class="sticky top-0 z-30 border-b border-brand-line bg-white/95 px-4 backdrop-blur sm:px-6 lg:px-8">
    <div class="flex min-h-[60px] items-center gap-3">
        @hasSection('back-url')
            <a href="@yield('back-url')"
               wire:navigate
               class="-ml-2 grid h-11 w-11 shrink-0 place-items-center rounded-user-pill text-brand-ink transition-colors hover:bg-brand-soft lg:hidden"
               aria-label="Quay lại">
                @svg('heroicon-o-chevron-left', 'h-5 w-5')
            </a>
        @else
            {{-- Mobile không có sidebar nên chưa thấy thương hiệu ở đâu.
                 Biểu tượng hai hình thoi đủ nhận diện mà không chiếm chỗ tiêu đề. --}}
            <img src="{{ asset('brand/logo-mark.svg') }}"
                 alt="Techcombank"
                 class="h-5 w-auto shrink-0 lg:hidden">
        @endif

        <div class="min-w-0 flex-1">
            <h1 class="truncate text-base font-bold sm:text-lg">@yield('title', 'Học tập')</h1>
            @hasSection('subtitle')
                <p class="truncate text-xs text-brand-muted">@yield('subtitle')</p>
            @endif
        </div>

        @hasSection('header-meta')
            <div class="shrink-0">@yield('header-meta')</div>
        @endif
    </div>
</header>
