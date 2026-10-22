@props([
    'id',
    'label' => 'Mã',
    // Mã hiện có khi sửa; để trống khi tạo mới
    'value' => '',
])

{{--
    Ô mã chỉ đọc. Mã do hệ thống tự sinh khi tạo (trait HasAutoCode) và không
    sửa được, nên không cho nhập — tránh trùng mã và sai định dạng.
--}}
<div {{ $attributes }}>
    <label for="{{ $id }}" class="admin-label">{{ $label }}</label>
    <input id="{{ $id }}" type="text" readonly tabindex="-1"
           value="{{ $value }}"
           placeholder="Tự động sinh khi lưu"
           class="admin-input cursor-not-allowed bg-brand-soft font-mono text-brand-muted">
</div>
