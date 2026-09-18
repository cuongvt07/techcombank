@props([
    'data' => [],
    'height' => 140,
    'label' => 'Biểu đồ',
])

{{--
    Biểu đồ cột vẽ bằng SVG thuần — không dùng thư viện JS ngoài.

    Lý do: hệ thống nội bộ ngân hàng thường chặn CDN, và một biểu đồ cột đơn giản
    không đáng để kéo thêm 200KB thư viện. SVG cũng in ra giấy sạch hơn canvas.

    data: mảng [['label' => '12/08', 'value' => 5], ...]
--}}
@php
    $items = collect($data);
    $max = max(1, $items->max('value') ?? 1);
    $count = max(1, $items->count());

    // Cột chiếm 70% ô, còn lại là khoảng cách — đủ thoáng mà không loãng
    $slotWidth = 100 / $count;
    $barWidth = $slotWidth * 0.7;
@endphp

<div {{ $attributes }}>
    @if ($items->isEmpty() || $items->sum('value') === 0)
        <div class="grid place-items-center rounded-admin border border-dashed border-brand-line text-sm text-brand-muted"
             style="height: {{ $height }}px">
            Chưa có dữ liệu trong khoảng thời gian này
        </div>
    @else
        <svg viewBox="0 0 100 {{ $height }}"
             preserveAspectRatio="none"
             class="w-full"
             style="height: {{ $height }}px"
             role="img"
             aria-label="{{ $label }}">

            {{-- Đường lưới ngang, giúp đọc giá trị mà không cần trục số dày đặc --}}
            @foreach ([0.25, 0.5, 0.75, 1] as $ratio)
                <line x1="0" y1="{{ $height - ($height - 16) * $ratio }}"
                      x2="100" y2="{{ $height - ($height - 16) * $ratio }}"
                      stroke="#eef1f5" stroke-width="0.5"
                      vector-effect="non-scaling-stroke" />
            @endforeach

            @foreach ($items as $index => $item)
                @php
                    $barHeight = $item['value'] > 0
                        ? max(2, ($item['value'] / $max) * ($height - 16))
                        : 0;
                    $x = $index * $slotWidth + ($slotWidth - $barWidth) / 2;
                @endphp

                @if ($barHeight > 0)
                    <rect x="{{ $x }}" y="{{ $height - $barHeight }}"
                          width="{{ $barWidth }}" height="{{ $barHeight }}"
                          fill="#e60012" rx="0.5">
                        <title>{{ $item['label'] }}: {{ $item['value'] }}</title>
                    </rect>
                @endif
            @endforeach
        </svg>

        {{-- Nhãn trục X: chỉ hiện đầu/giữa/cuối khi nhiều cột, tránh chồng chữ --}}
        <div class="mt-1.5 flex justify-between text-[10px] text-brand-muted">
            @foreach ($items as $index => $item)
                @if ($count <= 8 || $index === 0 || $index === intdiv($count, 2) || $index === $count - 1)
                    <span>{{ $item['label'] }}</span>
                @endif
            @endforeach
        </div>
    @endif
</div>
