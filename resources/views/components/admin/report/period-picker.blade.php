@props([
    'preset',
    'from' => null,
    'to' => null,
    'label' => 'Kỳ báo cáo',
    // Chỉ hiện một số mốc, vd ['7d', '30d', 'custom']
    'presets' => null,
])

{{-- Bộ chọn kỳ: nút nhanh + ô ngày khi chọn "Tùy chọn". Dùng với trait HasReportPeriod. --}}
@php
    $options = collect(\App\Support\Reports\ReportPeriod::PRESETS)
        ->when($presets, fn ($c) => $c->only($presets));
@endphp

<div {{ $attributes }}>
    <span class="admin-label">{{ $label }}</span>
    <div class="flex flex-wrap items-center gap-2">
        <div class="inline-flex max-w-full flex-wrap overflow-hidden rounded-admin border border-brand-line" role="group" aria-label="{{ $label }}">
            @foreach ($options as $key => $text)
                <button type="button" wire:click="setPreset('{{ $key }}')"
                        aria-pressed="{{ $preset === $key ? 'true' : 'false' }}"
                        @class([
                            'h-10 whitespace-nowrap border-l border-brand-line px-3 text-[13px] first:border-l-0 transition-colors',
                            'bg-brand-ink font-semibold text-white' => $preset === $key,
                            'bg-white text-brand-ink hover:bg-brand-soft' => $preset !== $key,
                        ])>{{ $text }}</button>
            @endforeach
        </div>

        @if ($preset === 'custom')
            <div class="flex items-center gap-1.5">
                <label class="sr-only" for="period-from">Từ ngày</label>
                <input id="period-from" type="date" wire:model.live="from" value="{{ $from }}" class="admin-input w-auto">
                <span class="text-brand-muted">–</span>
                <label class="sr-only" for="period-to">Đến ngày</label>
                <input id="period-to" type="date" wire:model.live="to" value="{{ $to }}" class="admin-input w-auto">
            </div>
        @endif
    </div>
</div>
