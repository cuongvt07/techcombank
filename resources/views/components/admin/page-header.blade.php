@props([
    'title',
    'subtitle' => null,
])

{{--
    Đầu trang chuẩn cho mọi màn hình quản trị: tiêu đề bên trái, hành động bên phải.
    Dùng chung để mọi màn hình có cùng nhịp thị giác (spec 6.4).
--}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-4">
    <div class="min-w-0">
        <h2 class="text-2xl font-bold">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-1 text-sm text-brand-muted">{{ $subtitle }}</p>
        @endif
    </div>

    @if (isset($actions))
        {{-- Không shrink-0: trên điện thoại các nút phải xuống dòng thay vì tràn ngang --}}
        <div class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
