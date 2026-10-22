@props([
    // Nhãn hàng, vd ['T2', 'T3', ..., 'CN']
    'rows' => [],
    // Nhãn cột, vd ['00', '01', ..., '23']
    'columns' => [],
    // Ma trận giá trị [hàng][cột]
    'values' => [],
    // Ô được viền đỏ: [[hàng, cột], ...] — vd. truy cập ngoài giờ vượt ngưỡng
    'flagged' => [],
    'clickAction' => null,
    'label' => 'Bản đồ nhiệt',
])

{{--
    Bản đồ nhiệt hàng × cột (thứ trong tuần × giờ trong ngày). Đậm = nhiều.
    Bốn mức xám để đọc được cả khi in đen trắng.
--}}
@php
    $max = max(1, collect($values)->flatten()->max() ?? 1);
    $shades = ['#f5f6f8', '#d6d9df', '#9aa0aa', '#565c66', '#141821'];
    $shade = function ($v) use ($max, $shades) {
        if ($v <= 0) return $shades[0];
        return $shades[min(4, (int) ceil($v / $max * 4))];
    };
    $flagSet = collect($flagged)->map(fn ($c) => $c[0] . '-' . $c[1])->flip();
@endphp

<div {{ $attributes->merge(['class' => 'overflow-x-auto']) }} role="img" aria-label="{{ $label }}">
    <table class="border-separate" style="border-spacing: 3px">
        <thead>
            <tr>
                <th></th>
                @foreach ($columns as $c)
                    <th class="w-6 text-center text-[10px] font-normal text-brand-muted">{{ $c }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r => $rowLabel)
                <tr>
                    <th class="pr-2 text-right text-[11px] font-semibold text-brand-muted">{{ $rowLabel }}</th>
                    @foreach ($columns as $c => $colLabel)
                        @php
                            $v = (int) ($values[$r][$c] ?? 0);
                            $flag = $flagSet->has($r . '-' . $c);
                        @endphp
                        <td @if ($clickAction) wire:click="{{ $clickAction }}({{ $r }}, {{ $c }})" @endif
                            class="h-6 w-6 rounded-sm {{ $clickAction ? 'cursor-pointer hover:opacity-80' : '' }}"
                            style="background: {{ $shade($v) }}; {{ $flag ? 'box-shadow: inset 0 0 0 2px #c00016;' : '' }}"
                            title="{{ $rowLabel }} {{ $colLabel }}h: {{ $v }} lượt"></td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
