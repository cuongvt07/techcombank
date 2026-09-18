@props([
    'course',
    'height' => 'h-32',
    'showIcon' => true,
])

{{--
    Ảnh bìa khóa học.

    Dùng ảnh upload nếu có; không thì sinh gradient từ mã khóa học. Cùng một khóa
    luôn ra cùng màu (hash cố định), nên danh sách nhìn ổn định chứ không nhảy
    màu mỗi lần tải lại — người học nhận ra khóa quen thuộc qua màu.

    Bảng màu chỉ dùng sắc độ của đỏ thương hiệu và trung tính, tránh kéo màu lạ
    vào làm loãng bộ nhận diện (spec 6.1).
--}}
@php
    // Sáu biến thể xoay quanh đỏ thương hiệu — đủ khác nhau để phân biệt,
    // vẫn cùng một họ màu
    // Hạ độ sáng cả bảng cho khớp đỏ thương hiệu mới (#c00016): mảng gradient
    // to bằng cả thẻ nên tông tươi làm mỏi mắt khi xem danh sách dài
    $palettes = [
        ['#c00016', '#6d0813'],
        ['#9b000c', '#340206'],
        ['#b8202c', '#5e1820'],
        ['#a3171e', '#3c0a0e'],
        ['#8a1216', '#240809'],
        ['#b52d30', '#521013'],
    ];

    $seed = crc32($course->code ?? $course->title ?? 'course');
    [$from, $to] = $palettes[$seed % count($palettes)];

    // Icon theo tính chất khóa học
    $icon = match (true) {
        (bool) ($course->is_onboarding ?? false) => 'heroicon-o-sparkles',
        (bool) ($course->issue_certificate ?? false) => 'heroicon-o-trophy',
        default => 'heroicon-o-academic-cap',
    };
@endphp

@if (! empty($course->cover_image))
    <div {{ $attributes->merge(['class' => $height . ' w-full overflow-hidden']) }}>
        <img src="{{ $course->cover_image }}"
             alt="{{ $course->title }}"
             class="h-full w-full object-cover">
    </div>
@else
    <div {{ $attributes->merge(['class' => $height . ' relative w-full overflow-hidden']) }}
         style="background: linear-gradient(135deg, {{ $from }}, {{ $to }})">

        {{-- Hoạ tiết chấm mờ tạo chiều sâu, không cần file ảnh --}}
        <div class="absolute inset-0 opacity-[0.15]"
             style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 16px 16px;"></div>

        @if ($showIcon)
            <div class="absolute -bottom-4 -right-3 text-white/[.18]">
                @svg($icon, 'h-24 w-24')
            </div>
        @endif

        {{ $slot ?? '' }}
    </div>
@endif
