@props([
    'variant' => 'full',
    'height' => 'h-7',
    'onDark' => false,
    'subtitle' => true,
])

{{--
    Logo thương hiệu Techcombank.

    File lấy từ website chính thức của Techcombank, đặt trong public/brand/.
    Logo gốc rất ngang (406×55, tỉ lệ ~7.3:1) nên chiều cao mặc định để h-7
    (28px → rộng ~205px), vừa trong sidebar 268px mà không tràn.

    variant: full (chữ + biểu tượng) | mark (chỉ hai hình thoi)
    onDark:  true khi đặt trên nền tối, dùng bản chữ trắng
    subtitle: hiện dòng "Hệ thống đào tạo nội bộ" dưới logo
--}}
@php
    // Thứ tự ưu tiên tìm file, dừng ở file đầu tiên tồn tại
    $candidates = match (true) {
        $variant === 'mark' => ['brand/logo-mark.svg', 'brand/logo-mark.png'],
        $onDark => ['brand/logo-white.svg', 'brand/logo-white.png', 'brand/logo.svg', 'brand/logo.png'],
        default => ['brand/logo.svg', 'brand/logo.png'],
    };

    $logoPath = null;

    foreach ($candidates as $candidate) {
        if (is_file(public_path($candidate))) {
            $logoPath = $candidate;
            break;
        }
    }

    $appName = \App\Models\Setting::get('app.display_name', config('app.name'));
@endphp

@if ($logoPath)
    @if ($variant === 'full' && $subtitle)
        <span {{ $attributes->merge(['class' => 'flex flex-col gap-1.5']) }}>
            <img src="{{ asset($logoPath) }}" alt="Techcombank" class="{{ $height }} w-auto">
            <span class="text-[10px] font-medium uppercase tracking-wider {{ $onDark ? 'text-white/50' : 'text-brand-muted' }}">
                Hệ thống đào tạo nội bộ
            </span>
        </span>
    @else
        <img src="{{ asset($logoPath) }}"
             alt="Techcombank"
             {{ $attributes->merge(['class' => $height . ' w-auto']) }}>
    @endif
@else
    {{-- Chưa có file logo: hiện tên hệ thống, không dựng logo giả --}}
    <span {{ $attributes->merge(['class' => 'flex flex-col leading-none']) }}>
        <span class="text-[15px] font-bold {{ $onDark ? 'text-white' : 'text-brand-ink' }}">
            {{ $appName }}
        </span>
        @if ($subtitle)
            <span class="mt-1 text-[10px] font-medium uppercase tracking-wider {{ $onDark ? 'text-white/50' : 'text-brand-muted' }}">
                Hệ thống đào tạo nội bộ
            </span>
        @endif
    </span>
@endif
