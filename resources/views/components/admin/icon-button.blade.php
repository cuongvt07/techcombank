@props([
    'icon',
    'label',
    'variant' => 'default',
])

{{--
    Nút thao tác dạng icon cho cột hành động trong bảng (spec 6.4: ưu tiên mật độ
    dữ liệu hơn khoảng trắng trang trí).

    Nhãn không biến mất mà chuyển thành tooltip + aria-label, nên vẫn đọc được
    bằng trình đọc màn hình và tra được khi rê chuột.
    Kích thước 32×32 giữ vừa chiều cao dòng 58px mà vẫn đủ vùng bấm.
--}}
@php
    $variantClasses = match ($variant) {
        'danger' => 'border-brand-line text-brand-muted hover:border-brand-red hover:bg-brand-red-tint hover:text-brand-red',
        'primary' => 'border-brand-red bg-brand-red text-white hover:bg-brand-red-dark',
        default => 'border-brand-line text-brand-muted hover:border-brand-red hover:text-brand-red',
    };
@endphp

<button {{ $attributes->merge([
            'type' => 'button',
            'title' => $label,
            'aria-label' => $label,
            'class' => 'grid h-8 w-8 shrink-0 place-items-center rounded-admin border transition-colors '
                . 'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-red focus-visible:ring-offset-1 '
                . $variantClasses,
        ]) }}>
    @svg($icon, 'h-4 w-4')
</button>
