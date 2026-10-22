@props([
    'label',
    'value',
    // Thay đổi so với kỳ trước, đã định dạng sẵn: '▲ 3,2 pp', '▼ 1,5 pp'
    'delta' => null,
    // 'good' | 'bad' | 'neutral' — màu của delta (tăng chưa chắc là tốt: quá hạn tăng là xấu)
    'deltaTone' => 'neutral',
    // Dòng chú thích dưới cùng, vd '1.284 / 1.486 lượt đến hạn'
    'note' => null,
    'noteTone' => null,
    // Dãy giá trị cho đường xu hướng nhỏ
    'spark' => null,
    // Thẻ cần chú ý: viền + số màu đỏ
    'alert' => false,
    'href' => null,
])

{{-- Thẻ chỉ số (KPI) dùng chung cho Tổng quan và các trang Báo cáo. --}}
@php
    $deltaClass = match ($deltaTone) {
        'good' => 'text-state-success',
        'bad' => 'text-brand-red',
        default => 'text-brand-muted',
    };
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([
        'admin-panel flex min-w-0 flex-col p-4',
        '!border-brand-red ring-1 ring-brand-red' => $alert,
        'transition-colors hover:border-brand-red' => $href,
    ]) }}>
    <span class="truncate text-xs font-semibold text-brand-muted">{{ $label }}</span>
    <strong @class(['mt-2 block text-[26px] font-bold leading-none tabular-nums', 'text-brand-red' => $alert])>{{ $value }}</strong>

    @if ($delta !== null || $spark)
        <div class="mt-2 flex items-end justify-between gap-2">
            <span class="text-xs font-semibold {{ $deltaClass }}">{{ $delta }}</span>
            @if ($spark)
                <x-admin.chart.sparkline :values="$spark" :color="$alert ? '#c00016' : '#141821'" />
            @endif
        </div>
    @endif

    @if ($note)
        <span @class([
            'mt-auto block truncate pt-2 text-xs',
            'border-t border-brand-line mt-2' => $delta !== null || $spark,
            'font-semibold text-brand-red' => $noteTone === 'danger',
            'text-brand-muted' => $noteTone !== 'danger',
        ])>{{ $note }}</span>
    @endif
</{{ $tag }}>
