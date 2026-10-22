@props([
    // [['key' => 'completed', 'label' => 'Hoàn thành', 'value' => 1842, 'color' => '#141821', 'danger' => false], ...]
    'data' => [],
    'size' => 132,
    'centerLabel' => '',
    'clickAction' => null,
    'activeKey' => null,
    'label' => 'Phân bố',
])

{{--
    Biểu đồ tròn + chú giải có số và %. Mỗi lát bấm được (clickAction) để lọc
    bảng bên dưới theo nhóm đó.
--}}
@php
    $items = collect($data)->values();
    $total = $items->sum('value');
    $radius = 40;
    $circ = 2 * M_PI * $radius;
    $offset = 0;
    $segments = $items->map(function ($item) use ($total, $circ, &$offset) {
        $ratio = $total > 0 ? $item['value'] / $total : 0;
        $seg = $item + [
            'dash' => $ratio * $circ,
            'gap' => $circ - $ratio * $circ,
            'offset' => -$offset,
            'percent' => $ratio * 100,
        ];
        $offset += $ratio * $circ;

        return $seg;
    });
    $fmtPct = fn ($p) => number_format($p, 1, ',', '.') . '%';
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-6']) }}>
    <div class="relative shrink-0" style="width: {{ $size }}px; height: {{ $size }}px">
        <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90" role="img" aria-label="{{ $label }}">
            <circle cx="50" cy="50" r="{{ $radius }}" fill="none" stroke="#eef0f4" stroke-width="16" />
            @foreach ($segments as $s)
                @if ($s['dash'] > 0)
                    <circle cx="50" cy="50" r="{{ $radius }}" fill="none" stroke="{{ $s['color'] }}" stroke-width="16"
                            stroke-dasharray="{{ $s['dash'] }} {{ $s['gap'] }}" stroke-dashoffset="{{ $s['offset'] }}"
                            opacity="{{ $activeKey && $activeKey !== ($s['key'] ?? null) ? 0.3 : 1 }}"
                            @if ($clickAction && isset($s['key'])) wire:click="{{ $clickAction }}('{{ $s['key'] }}')" style="cursor: pointer" @endif>
                        <title>{{ $s['label'] }}: {{ number_format($s['value'], 0, ',', '.') }} ({{ $fmtPct($s['percent']) }})</title>
                    </circle>
                @endif
            @endforeach
        </svg>
        <div class="pointer-events-none absolute inset-0 grid place-items-center text-center">
            <div>
                <strong class="block text-xl font-bold leading-none">{{ number_format($total, 0, ',', '.') }}</strong>
                @if ($centerLabel)
                    <span class="mt-0.5 block text-[10px] text-brand-muted">{{ $centerLabel }}</span>
                @endif
            </div>
        </div>
    </div>

    <ul class="grid min-w-[200px] flex-1 gap-2">
        @foreach ($segments as $s)
            @php $danger = ! empty($s['danger']); @endphp
            <li @if ($clickAction && isset($s['key'])) wire:click="{{ $clickAction }}('{{ $s['key'] }}')" role="button" tabindex="0" @endif
                @class([
                    'flex items-center gap-2.5 rounded-admin-sm px-1 py-0.5 text-[13px]',
                    'cursor-pointer hover:bg-brand-soft' => $clickAction,
                    'bg-brand-red-tint' => $activeKey && $activeKey === ($s['key'] ?? null),
                ])>
                <span class="h-2.5 w-2.5 shrink-0" style="background: {{ $s['color'] }}"></span>
                <span @class(['min-w-0 flex-1 truncate', 'font-semibold text-brand-red' => $danger])>{{ $s['label'] }}</span>
                <span @class(['shrink-0 font-semibold tabular-nums', 'text-brand-red' => $danger])>{{ number_format($s['value'], 0, ',', '.') }}</span>
                <span @class(['w-12 shrink-0 text-right tabular-nums', 'text-brand-red' => $danger, 'text-brand-muted' => ! $danger])>{{ $fmtPct($s['percent']) }}</span>
            </li>
        @endforeach
    </ul>
</div>
