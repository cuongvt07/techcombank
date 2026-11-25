@props([
    'title',
    // Dòng phụ cạnh tiêu đề, vd "22/09 – 21/10/2026, theo ngày"
    'subtitle' => null,
    // Chú thích của khối (cách đọc, nhận định) — hiện trong tooltip của icon ⓘ cạnh tiêu đề,
    // không in thành dòng chữ nhỏ dưới khối
    'note' => null,
    // Chú thích quan trọng: icon ⓘ màu đỏ
    'noteAlert' => false,
])

{{-- Khung một khối biểu đồ / danh sách trong trang báo cáo. Slot "actions" ở góc phải tiêu đề. --}}
<section {{ $attributes->merge(['class' => 'admin-panel flex min-w-0 flex-col']) }}>
    <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-5 pt-4">
        <div class="flex min-w-0 flex-wrap items-center gap-x-2.5">
            <h2 class="text-[15px] font-bold">{{ $title }}</h2>
            @if ($note)
                <x-admin.report.info :text="$note" :alert="$noteAlert" label="Chú thích: {{ $title }}" class="-ml-1" />
            @endif
            @if ($subtitle)
                <span class="text-xs text-brand-muted">{{ $subtitle }}</span>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-3">{{ $actions }}</div>
        @endisset
    </header>

    <div class="min-w-0 flex-1 px-5 py-4">{{ $slot }}</div>
</section>
