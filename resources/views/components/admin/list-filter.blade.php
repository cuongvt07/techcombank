@props([
    // Tên trường lọc, hiện ngay trong ô: "Trạng thái  Tất cả ▾"
    'label',
    // Thuộc tính Livewire; có model thì component tự dựng <select>, slot là danh sách <option>.
    // Không có model thì slot là control tùy biến (vd. <x-admin.searchable-select>, ô ngày).
    'model' => null,
    'id' => null,
])

@php
    $filterId = $id ?? ($model ? 'f-' . str_replace('.', '-', $model) : null);
@endphp

{{-- Một ô lọc trong <x-admin.list-toolbar> — nhãn chữ thường, không uppercase --}}
@if ($model)
    <label for="{{ $filterId }}" class="list-filter">
        <span class="list-filter-label">{{ $label }}</span>
        <select id="{{ $filterId }}" wire:model.live="{{ $model }}" {{ $attributes->class('list-filter-select') }}>
            {{ $slot }}
        </select>
        @svg('heroicon-o-chevron-down', 'list-filter-chevron')
    </label>
@else
    <div {{ $attributes->class('list-filter list-filter-custom') }}>
        <span class="list-filter-label" @if ($filterId) id="{{ $filterId }}-label" @endif>{{ $label }}</span>
        {{ $slot }}
    </div>
@endif
