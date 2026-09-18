@props([
    'employee' => null,
    'size' => 'h-10 w-10',
    'text' => 'text-sm',
])

{{--
    Ảnh đại diện nhân viên.

    Có ảnh upload thì hiện ảnh, không thì sinh vòng tròn chữ cái. Màu lấy theo
    mã nhân viên nên mỗi người một màu cố định — nhìn quen mặt qua màu, và danh
    sách không nhảy màu mỗi lần tải lại.

    Bảng màu chỉ dùng sắc độ đỏ thương hiệu và trung tính đậm, tránh kéo màu lạ
    vào làm loãng bộ nhận diện (spec 6.1).
--}}
@php
    // Cùng tông với đỏ thương hiệu đậm (#c00016)
    $palettes = [
        '#c00016', '#6d0813', '#9b000c', '#a3171e',
        '#8a1216', '#340206', '#521013', '#240809',
    ];

    $seed = crc32((string) ($employee?->employee_code ?? $employee?->full_name ?? '?'));
    $color = $palettes[$seed % count($palettes)];

    $hasPhoto = $employee?->avatar_file_id && Route::has('learn.avatar');
@endphp

@if ($hasPhoto)
    <img src="{{ route('learn.avatar', $employee) }}"
         alt="{{ $employee->full_name }}"
         {{ $attributes->merge(['class' => $size . ' shrink-0 rounded-full object-cover']) }}>
@else
    <span {{ $attributes->merge([
              'class' => $size . ' ' . $text
                  . ' grid shrink-0 place-items-center rounded-full font-bold leading-none text-white',
          ]) }}
          style="background-color: {{ $color }}"
          aria-hidden="true"
          title="{{ $employee?->full_name }}">
        {{ $employee?->initials() ?? '?' }}
    </span>
@endif
