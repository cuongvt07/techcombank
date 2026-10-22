@props([
    /*
     * Các dòng thanh ngang:
     * [
     *   'label' => 'CN Hải Phòng',
     *   'segments' => [['value' => 61.2, 'color' => '#141821', 'name' => 'Hoàn thành'], ...],
     *   'value' => '61,2%',          // chữ bên phải thanh
     *   'sub' => '97 người',          // chữ phụ bên phải (tùy chọn)
     *   'muted' => false,             // làm mờ (vd. mẫu nhỏ)
     *   'tone' => null,               // 'danger' tô đỏ nhãn + giá trị
     *   'key' => 12,                  // tham số gửi cho clickAction
     * ]
     */
    'rows' => [],
    // Tổng của một thanh đầy. null = lấy dòng lớn nhất làm 100%
    'max' => null,
    // Vạch so sánh dọc (cùng đơn vị với segments), vd. trung bình toàn hàng
    'benchmark' => null,
    'labelWidth' => '9rem',
    'valueWidth' => '3.5rem',
    'subWidth' => '4.5rem',
    'clickAction' => null,
    'activeKey' => null,
    'barHeight' => 'h-3.5',
])

{{--
    Thanh ngang có nhãn — so sánh một đại lượng giữa các mục (đơn vị, khóa học,
    chủ đề). Một thanh có thể chia nhiều đoạn (phân bố trạng thái trong đơn vị).
--}}
@php
    $rows = collect($rows)->values();
    $scale = $max ?? max(1, $rows->max(fn ($r) => collect($r['segments'])->sum('value')));
@endphp

<div {{ $attributes->merge(['class' => 'grid gap-2.5']) }}>
    @foreach ($rows as $row)
        @php
            $isActive = $activeKey !== null && (string) ($row['key'] ?? '') === (string) $activeKey;
            $danger = ($row['tone'] ?? null) === 'danger';
        @endphp
        <div @if ($clickAction && isset($row['key'])) wire:click="{{ $clickAction }}('{{ $row['key'] }}')" role="button" tabindex="0" @endif
             @class([
                 'grid items-center gap-3 text-[13px]',
                 'cursor-pointer rounded-admin-sm hover:bg-brand-soft' => $clickAction,
                 'bg-brand-red-tint' => $isActive,
                 'opacity-50' => ! empty($row['muted']),
             ])
             style="grid-template-columns: {{ $labelWidth }} minmax(0, 1fr) {{ $valueWidth }}{{ isset($row['sub']) ? ' ' . $subWidth : '' }}">
            <span @class(['truncate', 'font-semibold text-brand-red' => $danger]) title="{{ $row['label'] }}">{{ $row['label'] }}</span>

            <div class="relative flex {{ $barHeight }} overflow-hidden rounded-sm bg-[#eef0f4]">
                @foreach ($row['segments'] as $seg)
                    @php $w = $scale > 0 ? max(0, min(100, $seg['value'] / $scale * 100)) : 0; @endphp
                    @if ($w > 0)
                        <span class="h-full" style="width: {{ $w }}%; background: {{ $seg['color'] }}"
                              title="{{ ($seg['name'] ?? '') . ($seg['name'] ?? false ? ': ' : '') . ($seg['text'] ?? $seg['value']) }}"></span>
                    @endif
                @endforeach
                @if ($benchmark !== null && $scale > 0)
                    <span class="absolute inset-y-[-2px] border-l border-dashed border-brand-ink"
                          style="left: {{ min(100, $benchmark / $scale * 100) }}%"></span>
                @endif
            </div>

            <span @class(['text-right font-semibold tabular-nums', 'text-brand-red' => $danger])>{{ $row['value'] ?? '' }}</span>
            @isset($row['sub'])
                <span @class(['text-right text-xs tabular-nums', 'font-semibold text-brand-red' => ($row['subTone'] ?? null) === 'danger', 'text-brand-muted' => ($row['subTone'] ?? null) !== 'danger'])>{{ $row['sub'] }}</span>
            @endisset
        </div>
    @endforeach
</div>
