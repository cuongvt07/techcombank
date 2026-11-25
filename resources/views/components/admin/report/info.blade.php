@props([
    // Nội dung chú thích (có thể chứa HTML đã escape sẵn, vd <strong>)
    'text' => null,
    // Nhãn đọc cho trình đọc màn hình
    'label' => 'Xem chú thích',
    // Chú thích quan trọng: icon màu đỏ
    'alert' => false,
    // Phía mở hộp chú thích: 'left' (mép trái icon) | 'right' (mép phải icon)
    'align' => 'left',
])

{{-- Icon ⓘ: rê chuột / focus để xem chú thích. Thay cho các dòng hướng dẫn nhỏ dưới khối. --}}
@php $body = $text ?? (string) $slot; @endphp
@if (trim(strip_tags($body)) !== '')
    <span x-data="{ open: false }" {{ $attributes->merge(['class' => 'relative inline-flex align-middle normal-case tracking-normal']) }}
          x-on:mouseenter="open = true" x-on:mouseleave="open = false" x-on:keydown.escape="open = false">
        <button type="button" aria-label="{{ $label }}"
                x-on:focus="open = true" x-on:blur="open = false" x-on:click="open = ! open"
                @class([
                    'inline-flex h-5 w-5 items-center justify-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-red',
                    'text-brand-red' => $alert,
                    'text-brand-muted hover:text-brand-ink' => ! $alert,
                ])>
            @svg('heroicon-o-information-circle', 'h-4 w-4')
        </button>
        <span x-show="open" x-cloak role="tooltip" x-transition.opacity
              @class([
                  'absolute top-full z-40 mt-1.5 w-72 max-w-[80vw] rounded-admin border border-brand-line bg-white px-3 py-2 text-left text-xs font-normal leading-relaxed text-brand-ink shadow-user',
                  'left-0' => $align === 'left',
                  'right-0' => $align !== 'left',
              ])>{!! $body !!}</span>
    </span>
@endif
