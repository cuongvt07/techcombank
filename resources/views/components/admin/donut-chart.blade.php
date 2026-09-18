@props([
    'data' => [],
    'size' => 132,
    'label' => 'Phân bố',
])

{{--
    Biểu đồ tròn (donut) vẽ bằng SVG thuần.

    Dùng stroke-dasharray trên vòng tròn thay vì tính path arc: đơn giản hơn nhiều
    và không có lỗi làm tròn ở các lát rất nhỏ.

    data: mảng [['label' => 'Hoàn thành', 'value' => 12, 'tone' => 'success'], ...]
--}}
@php
    $items = collect($data)->filter(fn ($i) => $i['value'] > 0)->values();
    $total = max(1, $items->sum('value'));

    $radius = 42;
    $circumference = 2 * M_PI * $radius;

    $tones = [
        'success' => '#17a34a',
        'warning' => '#d97706',
        'danger' => '#e60012',
        'muted' => '#c9ced8',
    ];

    // Cộng dồn để biết mỗi lát bắt đầu ở đâu trên vòng tròn
    $offset = 0;
    $segments = [];

    foreach ($items as $item) {
        $ratio = $item['value'] / $total;
        $segments[] = [
            'color' => $tones[$item['tone']] ?? '#c9ced8',
            'dash' => $ratio * $circumference,
            'gap' => $circumference - ($ratio * $circumference),
            'offset' => -$offset,
            'label' => $item['label'],
            'value' => $item['value'],
            'percent' => round($ratio * 100),
        ];
        $offset += $ratio * $circumference;
    }
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-5']) }}>
    @if ($items->isEmpty())
        <p class="text-sm text-brand-muted">Chưa có dữ liệu.</p>
    @else
        <div class="relative shrink-0" style="width: {{ $size }}px; height: {{ $size }}px">
            <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90" role="img" aria-label="{{ $label }}">
                <circle cx="50" cy="50" r="{{ $radius }}" fill="none" stroke="#f1f3f7" stroke-width="14" />

                @foreach ($segments as $segment)
                    <circle cx="50" cy="50" r="{{ $radius }}"
                            fill="none"
                            stroke="{{ $segment['color'] }}"
                            stroke-width="14"
                            stroke-dasharray="{{ $segment['dash'] }} {{ $segment['gap'] }}"
                            stroke-dashoffset="{{ $segment['offset'] }}">
                        <title>{{ $segment['label'] }}: {{ $segment['value'] }} ({{ $segment['percent'] }}%)</title>
                    </circle>
                @endforeach
            </svg>

            {{-- Tổng số đặt giữa vòng tròn — thông tin người xem tìm đầu tiên --}}
            <div class="absolute inset-0 grid place-items-center">
                <div class="text-center">
                    <strong class="block text-xl font-bold leading-none">{{ $total }}</strong>
                    <span class="mt-0.5 block text-[10px] text-brand-muted">lượt giao</span>
                </div>
            </div>
        </div>

        <ul class="grid min-w-0 flex-1 gap-2">
            @foreach ($segments as $segment)
                <li class="flex items-center gap-2.5 text-[13px]">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-sm" style="background: {{ $segment['color'] }}"></span>
                    <span class="min-w-0 flex-1 truncate text-brand-muted">{{ $segment['label'] }}</span>
                    <span class="shrink-0 font-semibold">{{ $segment['value'] }}</span>
                    <span class="w-10 shrink-0 text-right text-brand-muted">{{ $segment['percent'] }}%</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
