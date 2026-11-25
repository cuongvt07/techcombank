@props([
    // admin | user — chọn route Thông tin cá nhân tương ứng
    'site' => 'user',
    // dark: thẻ trên sidebar nền tối (site Quản trị) · light: sidebar/header nền trắng
    'tone' => 'light',
    // sidebar: thẻ tài khoản cuối sidebar, menu bật lên phía trên
    // header: chỉ avatar ở góc phải header (mobile), menu thả xuống dưới
    'placement' => 'sidebar',
    // true khi nằm trong sidebar thu gọn được: đọc biến "collapsed" của x-data cha
    'collapsible' => false,
    'subtitle' => null,
])

{{--
    Menu tài khoản (fb7 mục 5 + 4): bấm thẻ người dùng mở menu
    Thông tin cá nhân / Đổi mật khẩu / Đăng xuất — thay cho nút Đăng xuất riêng
    ở header (trước đây trùng hai chỗ).

    Menu dùng position: fixed, toạ độ tính từ nút bấm lúc mở, vì sidebar có
    overflow-hidden (để thu gọn) — menu absolute sẽ bị cắt khi sidebar chỉ rộng 76px.
    Đóng khi bấm ra ngoài, nhấn Esc (trả focus về nút), đổi kích thước cửa sổ.
--}}
@php
    $user = auth()->user();
    $employee = $user?->employee;
    $name = $employee?->full_name ?? $user?->name;

    $profileRoute = $site === 'admin' ? 'admin.profile' : 'learn.profile';
    $profileUrl = Route::has($profileRoute) ? route($profileRoute) : null;

    $dark = $tone === 'dark';
@endphp

<div x-data="{
         menuOpen: false,
         menuStyle: '',
         toggleMenu() { this.menuOpen ? this.closeMenu() : this.openMenu(); },
         openMenu() {
             const r = this.$refs.trigger.getBoundingClientRect();
             if (@js($placement) === 'header') {
                 this.menuStyle = `top:${r.bottom + 8}px; right:${Math.max(8, window.innerWidth - r.right)}px;`;
             } else if (r.width < 120) {
                 // Sidebar thu gọn: menu mở sang phải thẻ
                 this.menuStyle = `left:${r.right + 8}px; bottom:${Math.max(8, window.innerHeight - r.bottom)}px;`;
             } else {
                 this.menuStyle = `left:${r.left}px; bottom:${window.innerHeight - r.top + 8}px; width:${r.width}px;`;
             }
             this.menuOpen = true;
             this.$nextTick(() => this.$refs.menu.querySelector('[role=menuitem]')?.focus());
         },
         closeMenu(focusTrigger = false) {
             this.menuOpen = false;
             if (focusTrigger) this.$refs.trigger.focus();
         },
         moveFocus(step) {
             const items = [...this.$refs.menu.querySelectorAll('[role=menuitem]')];
             const i = items.indexOf(document.activeElement);
             items[(i + step + items.length) % items.length]?.focus();
         },
     }"
     x-on:click.outside="menuOpen && closeMenu()"
     x-on:keydown.escape.window="menuOpen && closeMenu(true)"
     x-on:resize.window="menuOpen && closeMenu()"
     {{ $attributes->class(['relative']) }}>

    <button type="button"
            x-ref="trigger"
            x-on:click="toggleMenu()"
            aria-haspopup="menu"
            x-bind:aria-expanded="menuOpen.toString()"
            aria-label="Menu tài khoản: {{ $name }}"
            @if ($collapsible)
                x-bind:title="collapsed ? @js($name) : null"
            @endif
            @class([
                'flex w-full items-center text-left transition-colors focus:outline-none focus-visible:ring-2',
                // Thẻ cuối sidebar
                'gap-2.5 p-3' => $placement === 'sidebar',
                'rounded-admin-lg border border-white/[.16] bg-white/[.06] hover:bg-white/10 focus-visible:ring-white' => $placement === 'sidebar' && $dark,
                'rounded-user-md bg-brand-soft hover:bg-[#eceff3] focus-visible:ring-brand-red' => $placement === 'sidebar' && ! $dark,
                // Avatar trên header mobile
                'gap-1 rounded-user-pill p-1 hover:bg-brand-soft focus-visible:ring-brand-red' => $placement === 'header',
            ])
            @if ($collapsible)
                x-bind:style="collapsed ? 'justify-content: center; padding-left: 6px; padding-right: 6px;' : ''"
            @endif>
        <x-user-avatar :employee="$employee" :size="$placement === 'header' ? 'h-8 w-8' : 'h-9 w-9'" text="text-xs" />

        @if ($placement === 'sidebar')
            <span class="min-w-0 flex-1" @if ($collapsible) x-show="! collapsed" @endif>
                <strong @class(['block truncate text-sm font-bold', 'text-white' => $dark])>{{ $name }}</strong>
                @if ($subtitle)
                    <span @class(['block truncate text-xs', 'text-white/70' => $dark, 'text-brand-muted' => ! $dark])>{{ $subtitle }}</span>
                @endif
            </span>
        @endif

        {{-- Mũi tên ▾ báo hiệu có menu --}}
        <span @if ($collapsible) x-show="! collapsed" @endif
              @class(['shrink-0 transition-transform', 'text-white/70' => $dark, 'text-brand-muted' => ! $dark])
              x-bind:class="menuOpen ? 'rotate-180' : ''">
            @svg($placement === 'sidebar' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down', 'h-4 w-4')
        </span>
    </button>

    <div x-ref="menu"
         x-show="menuOpen"
         x-cloak
         x-transition.opacity.duration.100ms
         x-bind:style="menuStyle"
         x-on:keydown.arrow-down.prevent="moveFocus(1)"
         x-on:keydown.arrow-up.prevent="moveFocus(-1)"
         role="menu"
         aria-label="Tài khoản"
         class="fixed z-50 min-w-[220px] overflow-hidden rounded-admin border border-brand-line bg-white py-1.5 text-brand-ink shadow-user">
        {{-- Tên người dùng trong menu: khi sidebar thu gọn chỉ còn avatar --}}
        <div class="border-b border-brand-line px-3.5 pb-2 pt-1">
            <div class="truncate text-sm font-bold">{{ $name }}</div>
            @if ($subtitle)
                <div class="truncate text-xs text-brand-muted">{{ $subtitle }}</div>
            @endif
        </div>

        @if ($profileUrl)
            <a href="{{ $profileUrl }}" role="menuitem" @if ($site === 'user') wire:navigate @endif
               class="mt-1 flex min-h-[40px] items-center gap-2.5 px-3.5 text-sm hover:bg-brand-soft focus:bg-brand-soft focus:outline-none">
                @svg('heroicon-o-user-circle', 'h-5 w-5 shrink-0 text-brand-muted')
                Thông tin cá nhân
            </a>
            {{-- Đổi mật khẩu nằm trong trang Thông tin cá nhân (chưa có route riêng) --}}
            <a href="{{ $profileUrl }}#doi-mat-khau" role="menuitem"
               class="flex min-h-[40px] items-center gap-2.5 px-3.5 text-sm hover:bg-brand-soft focus:bg-brand-soft focus:outline-none">
                @svg('heroicon-o-key', 'h-5 w-5 shrink-0 text-brand-muted')
                Đổi mật khẩu
            </a>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-brand-line pt-1">
            @csrf
            <button type="submit" role="menuitem"
                    class="flex min-h-[40px] w-full items-center gap-2.5 px-3.5 text-left text-sm font-semibold text-brand-red hover:bg-brand-red-tint focus:bg-brand-red-tint focus:outline-none">
                @svg('heroicon-o-arrow-right-on-rectangle', 'h-5 w-5 shrink-0')
                Đăng xuất
            </button>
        </form>
    </div>
</div>
