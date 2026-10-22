@props([
    // Dãy giá trị theo thời gian, cũ → mới
    'values' => [],
    'color' => '#141821',
    'width' => 64,
    'height' => 20,
    'label' => 'Xu hướng',
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
    $points = collect($vals)->map(function ($v, $i) use ($n, $min, $span, $width, $height) {
        $x = $n > 1 ? $i / ($n - 1) * $width : $width / 2;
        $y = $height - 2 - (($v - $min) / $span) * ($height - 4);

        return round($x, 2) . ',' . round($y, 2);
    })->implode(' ');
@endphp

@if ($n > 1)
    <svg {{ $attributes->merge(['class' => 'shrink-0']) }} width="{{ $width }}" height="{{ $height }}"
         viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $label }}">
        <polyline points="{{ $points }}" fill="none" stroke="{{ $color }}" stroke-width="1.5"
                  stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
    </svg>
@endif
