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
        ['route' => 'learn.events', 'label' => 'Sự kiện', 'icon' => 'calendar-days'],
        ['route' => 'learn.courses', 'label' => 'Khóa học của tôi', 'icon' => 'academic-cap'],
        ['route' => 'learn.progress', 'label' => 'Tiến độ học tập', 'icon' => 'chart-bar'],
        ['route' => 'learn.documents', 'label' => 'Tài liệu nội bộ', 'icon' => 'document-text'],
        ['route' => 'learn.support', 'label' => 'Hỗ trợ', 'icon' => 'lifebuoy'],
        ['route' => 'learn.profile', 'label' => 'Thông tin cá nhân', 'icon' => 'user-circle'],
    ]));
@endphp

<aside class="sticky top-0 flex h-screen w-[260px] shrink-0 flex-col border-r border-brand-line bg-white px-4 py-6">
    <a href="{{ route('learn.events') }}" wire:navigate class="mb-6 block px-2">
        <x-brand-logo height="h-7" />
    </a>

    <nav class="grid gap-1">
        @foreach ($items as $item)
            @continue(! Route::has($item['route']))
            @php $active = request()->routeIs($item['route'] . '*'); @endphp

            <a href="{{ route($item['route']) }}"
               wire:navigate
               @class([
                   'flex min-h-[44px] items-center gap-3 rounded-user-md px-3.5 text-sm font-bold transition-colors',
                   'bg-brand-red-tint text-brand-red' => $active,
                   'text-brand-ink hover:bg-brand-soft' => ! $active,
               ])
               @if ($active) aria-current="page" @endif>
                @svg('heroicon-' . ($active ? 's' : 'o') . '-' . $item['icon'], 'h-5 w-5 shrink-0')
                <span class="truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="mt-auto rounded-user-md bg-brand-soft p-4">
        <div class="flex items-center gap-2.5">
            <x-user-avatar :employee="auth()->user()?->employee" size="h-9 w-9" text="text-xs" />

            <div class="min-w-0">
                <strong class="block truncate text-sm font-bold">
                    {{ auth()->user()?->employee?->full_name ?? auth()->user()?->name }}
                </strong>
                <span class="block truncate text-xs text-brand-muted">
                    {{ auth()->user()?->employee?->department?->name }}
                </span>
            </div>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button type="submit" class="whitespace-nowrap text-xs font-semibold text-brand-red hover:underline">
                Đăng xuất
            </button>
        </form>
    </div>
</aside>
