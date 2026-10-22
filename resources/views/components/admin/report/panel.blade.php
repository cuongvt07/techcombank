@props([
    'title',
    // Dòng phụ cạnh tiêu đề, vd "22/09 – 21/10/2026, theo ngày"
    'subtitle' => null,
    // Ghi chú cuối khối — câu kết luận người xem cần biết (nhận định, không mô tả lại biểu đồ)
    'note' => null,
    // Ghi chú quan trọng: chấm đỏ đầu dòng
    'noteAlert' => false,
])

{{-- Khung một khối biểu đồ / danh sách trong trang báo cáo. Slot "actions" ở góc phải tiêu đề. --}}
<section {{ $attributes->merge(['class' => 'admin-panel flex min-w-0 flex-col']) }}>
    <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-5 pt-4">
        <div class="flex min-w-0 flex-wrap items-baseline gap-x-2.5">
            <h2 class="text-[15px] font-bold">{{ $title }}</h2>
            @if ($subtitle)
                <span class="text-xs text-brand-muted">{{ $subtitle }}</span>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-3">{{ $actions }}</div>
        @endisset
    </header>

    <div class="min-w-0 flex-1 px-5 py-4">{{ $slot }}</div>

    @if ($note)
        <p class="mx-5 flex gap-2 border-t border-brand-line py-3 text-xs text-brand-muted">
            @if ($noteAlert)
                <span class="mt-1 h-2 w-2 shrink-0 bg-brand-red"></span>
            @endif
            <span>{!! $note !!}</span>
        </p>
    @endif
</section>
