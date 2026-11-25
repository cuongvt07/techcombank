@props([
    // Dãy giá trị theo thời gian, cũ → mới
    'values' => [],
    'color' => '#141821',
    'width' => 64,
    'height' => 20,
    'label' => 'Xu hướng',
    // Vùng tô nhạt (gradient) dưới đường
    'fill' => false,
    // Chấm ở điểm cuối (giá trị mới nhất)
    'dot' => false,
    'strokeWidth' => 1.5,
])

{{--
    Đường xu hướng nhỏ cho thẻ chỉ số. Chỉ cần thấy hình dạng (lên / xuống /
    đi ngang), nên không có trục, không có nhãn.
--}}
@php
    $vals = array_values(array_map('floatval', is_array($values) ? $values : collect($values)->all()));
    $n = count($vals);
    $min = $n ? min($vals) : 0;
    $max = $n ? max($vals) : 0;
    $span = $max - $min ?: 1;
    // Chừa lề cho chấm cuối và nét vẽ không bị cắt ở mép
    $pad = $dot ? 3.5 : 2;
    $coords = collect($vals)->map(function ($v, $i) use ($n, $min, $span, $width, $height, $pad) {
        $x = $n > 1 ? $pad + $i / ($n - 1) * ($width - 2 * $pad) : $width / 2;
        $y = $height - $pad - (($v - $min) / $span) * ($height - 2 * $pad);

        return [round($x, 2), round($y, 2)];
    });
    $points = $coords->map(fn ($p) => $p[0] . ',' . $p[1])->implode(' ');
    $last = $coords->last();
    $gradientId = 'spark-' . substr(md5($points . $color), 0, 10);
@endphp

@if ($n > 1)
    <svg {{ $attributes->merge(['class' => 'shrink-0']) }} width="{{ $width }}" height="{{ $height }}"
         viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $label }}">
        @if ($fill)
            <defs>
                <linearGradient id="{{ $gradientId }}" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="{{ $color }}" stop-opacity="0.16" />
                    <stop offset="100%" stop-color="{{ $color }}" stop-opacity="0" />
                </linearGradient>
            </defs>
            <polygon points="{{ $coords->first()[0] }},{{ $height }} {{ $points }} {{ $last[0] }},{{ $height }}" fill="url(#{{ $gradientId }})" />
        @endif
        <polyline points="{{ $points }}" fill="none" stroke="{{ $color }}" stroke-width="{{ $strokeWidth }}"
                  stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        @if ($dot)
            <circle cx="{{ $last[0] }}" cy="{{ $last[1] }}" r="2.6" fill="{{ $color }}" stroke="#fff" stroke-width="1.2" />
        @endif
    </svg>
@endif
