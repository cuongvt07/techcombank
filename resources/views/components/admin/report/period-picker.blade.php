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
        {{-- Tab kỳ: nền xám nhạt, tab đang chọn nền trắng + chữ đỏ + bóng nhẹ (fb7 mục 3.3) --}}
        <div class="inline-flex max-w-full flex-wrap gap-0.5 rounded-admin p-[3px]" style="background: #eceef2" role="group" aria-label="{{ $label }}">
            @foreach ($options as $key => $text)
                <button type="button" wire:click="setPreset('{{ $key }}')"
                        aria-pressed="{{ $preset === $key ? 'true' : 'false' }}"
                        @if ($preset === $key) style="box-shadow: 0 1px 2px rgba(17, 22, 28, .12)" @endif
                        @class([
                            'h-[34px] whitespace-nowrap rounded-[8px] px-3 text-[13px] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-red',
                            'bg-white font-semibold text-brand-red' => $preset === $key,
                            'text-[#5A6572] hover:text-brand-ink' => $preset !== $key,
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
