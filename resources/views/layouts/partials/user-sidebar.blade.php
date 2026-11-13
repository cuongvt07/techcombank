{{--
    Sidebar chỉ dùng trên desktop (spec 6.5): thao tác chính ở PC là click + đọc dài,
    nên giữ điều hướng cố định thay vì bottom bar.
--}}
@php
    // Màn chào mừng chỉ dành cho nhân viên mới (spec 4.4); qua giai đoạn
    // thử việc thì mục này tự biến mất khỏi menu
    $laNhanVienMoi = (bool) auth()->user()?->employee?->is_new_hire;

    $items = array_values(array_filter([
        $laNhanVienMoi
            ? ['route' => 'learn.onboarding', 'label' => 'Chào mừng', 'icon' => 'hand-raised']
            : null,
        ['route' => 'learn.courses', 'label' => 'Khóa học của tôi', 'icon' => 'academic-cap'],
        ['route' => 'learn.progress', 'label' => 'Tiến độ học tập', 'icon' => 'chart-bar'],
        ['route' => 'learn.documents', 'label' => 'Tài liệu nội bộ', 'icon' => 'document-text'],
        ['route' => 'learn.support', 'label' => 'Hỗ trợ', 'icon' => 'lifebuoy'],
        ['route' => 'learn.profile', 'label' => 'Thông tin cá nhân', 'icon' => 'user-circle'],
    ]));
@endphp

{{--
    Thu gọn / mở rộng (fb6): thu về dạng chỉ icon, nhớ lựa chọn trong localStorage
    giống site quản trị. localStorage có thể bị chặn (chế độ riêng tư, chính sách
    trình duyệt) nên đọc/ghi đều bọc try/catch — lỗi thì coi như đang mở rộng.
    "ready" chỉ bật hiệu ứng co giãn sau lần vẽ đầu, để mỗi lần chuyển trang
    (wire:navigate) thanh bên không chạy hiệu ứng lại.
--}}
<aside x-data="{
           collapsed: (() => { try { return localStorage.getItem('user-sidebar-collapsed') === 'true'; } catch (e) { return false; } })(),
           ready: false,
           toggle() {
               this.collapsed = ! this.collapsed;
               try { localStorage.setItem('user-sidebar-collapsed', String(this.collapsed)); } catch (e) {}
           },
       }"
       x-init="$nextTick(() => ready = true)"
       {{-- Bề rộng/padding khi thu gọn đặt bằng style (đè được class tĩnh) để lần vẽ
            đầu trước khi Alpine chạy vẫn có đúng bề rộng mở rộng, không giật layout --}}
       x-bind:style="collapsed ? 'width: 76px; padding-left: 8px; padding-right: 8px;' : ''"
       x-bind:class="ready ? 'transition-[width] duration-200' : ''"
       class="sticky top-0 flex h-screen w-[260px] shrink-0 flex-col overflow-hidden border-r border-brand-line bg-white px-4 py-6">
    <div class="mb-6 flex items-center justify-between gap-2 px-2"
         x-bind:style="collapsed ? 'flex-direction: column; justify-content: center; padding-left: 0; padding-right: 0;' : ''">
        <a href="{{ route('learn.courses') }}" wire:navigate class="block min-w-0" aria-label="Khóa học của tôi">
            <span x-show="! collapsed">
                <x-brand-logo height="h-7" />
            </span>
            <span x-show="collapsed" x-cloak>
                <x-brand-logo variant="mark" height="h-7" />
            </span>
        </a>

        <button type="button"
                x-on:click="toggle()"
                x-bind:aria-label="collapsed ? 'Mở rộng thanh điều hướng' : 'Thu gọn thanh điều hướng'"
                x-bind:aria-expanded="(! collapsed).toString()"
                x-bind:title="collapsed ? 'Mở rộng menu' : 'Thu gọn menu'"
                aria-label="Thu gọn thanh điều hướng"
                class="grid h-9 w-9 shrink-0 place-items-center rounded-user-pill text-brand-muted transition-colors hover:bg-brand-soft hover:text-brand-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-red">
            <span x-show="! collapsed">@svg('heroicon-o-chevron-double-left', 'h-5 w-5')</span>
            <span x-show="collapsed" x-cloak>@svg('heroicon-o-chevron-double-right', 'h-5 w-5')</span>
        </button>
    </div>

    <nav class="grid gap-1" aria-label="Điều hướng học tập">
        @foreach ($items as $item)
            @continue(! Route::has($item['route']))
            @php $active = request()->routeIs($item['route'] . '*'); @endphp

            <a href="{{ route($item['route']) }}"
               wire:navigate
               x-bind:title="collapsed ? @js($item['label']) : null"
               x-bind:aria-label="collapsed ? @js($item['label']) : null"
               x-bind:style="collapsed ? 'justify-content: center; padding-left: 0; padding-right: 0;' : ''"
               @class([
                   'flex min-h-[44px] items-center gap-3 rounded-user-md px-3.5 text-sm font-bold transition-colors',
                   'bg-brand-red-tint text-brand-red' => $active,
                   'text-brand-ink hover:bg-brand-soft' => ! $active,
               ])
               @if ($active) aria-current="page" @endif>
                @svg('heroicon-' . ($active ? 's' : 'o') . '-' . $item['icon'], 'h-5 w-5 shrink-0')
                <span x-show="! collapsed" class="truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="mt-auto rounded-user-md bg-brand-soft p-4"
         x-bind:style="collapsed ? 'padding: 8px;' : ''">
        <div class="flex items-center gap-2.5"
             x-bind:style="collapsed ? 'justify-content: center;' : ''">
            <x-user-avatar :employee="auth()->user()?->employee" size="h-9 w-9" text="text-xs" />

            <div class="min-w-0" x-show="! collapsed">
                <strong class="block truncate text-sm font-bold">
                    {{ auth()->user()?->employee?->full_name ?? auth()->user()?->name }}
                </strong>
                <span class="block truncate text-xs text-brand-muted">
                    {{ auth()->user()?->employee?->department?->name }}
                </span>
            </div>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="mt-3"
              x-bind:style="collapsed ? 'display: flex; justify-content: center;' : ''">
            @csrf
            <button type="submit"
                    x-bind:title="collapsed ? 'Đăng xuất' : null"
                    x-bind:aria-label="collapsed ? 'Đăng xuất' : null"
                    class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold text-brand-red hover:underline">
                <span x-show="collapsed" x-cloak>@svg('heroicon-o-arrow-right-on-rectangle', 'h-5 w-5')</span>
                <span x-show="! collapsed">Đăng xuất</span>
            </button>
        </form>
    </div>
</aside>
