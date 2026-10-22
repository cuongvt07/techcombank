@props([
    // [['label' => 'Hoàn thành', 'color' => '#141821', 'type' => 'box'|'line'|'dashed'], ...]
    'items' => [],
])

<ul {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-brand-muted']) }}>
    @foreach ($items as $item)
        @php $type = $item['type'] ?? 'box'; @endphp
        <li class="flex items-center gap-1.5">
            @if ($type === 'box')
                <span class="h-2.5 w-2.5 shrink-0" style="background: {{ $item['color'] }}"></span>
            @else
                <span class="w-4 shrink-0 border-t-2 {{ $type === 'dashed' ? 'border-dashed' : '' }}" style="border-color: {{ $item['color'] }}"></span>
            @endif
            {{ $item['label'] }}
        </li>
    @endforeach
</ul>
