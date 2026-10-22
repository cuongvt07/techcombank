@props([
    'label',
])

{{--
    Một khối giá trị chỉ đọc có nhãn phía trên — dùng trong popup chi tiết để
    mọi giá trị đều có tiêu đề, cùng kiểu nhãn với ô nhập trong form (admin-label).
--}}
<div {{ $attributes }}>
    <p class="admin-label">{{ $label }}</p>
    <div class="text-sm text-brand-ink">{{ $slot }}</div>
</div>
