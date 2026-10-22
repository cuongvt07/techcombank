@props([
    'id',
    'label',
    'model',
    'options' => [],
    'hint' => null,
])

{{--
    Chọn nhiều giá trị bằng ô tick. Dùng cho danh sách ngắn (vài đến vài chục
    mục) như cấp bậc — danh sách dài thì nên dùng ô tìm kiếm.

    Không tick ô nào = không ràng buộc; nêu rõ trong hint để người dùng không
    tưởng phải tick hết mới là "tất cả".
--}}
<fieldset {{ $attributes->merge(['class' => '']) }}>
    <legend class="admin-label">{{ $label }}</legend>

    @if ($hint)
        <p class="mb-2 text-xs text-brand-muted">{{ $hint }}</p>
    @endif

    <div class="grid gap-2 sm:grid-cols-2">
        @foreach ($options as $option)
            <label wire:key="{{ $id }}-{{ $option['value'] }}"
                   class="flex cursor-pointer items-center gap-2 rounded-admin border border-brand-line p-3 text-sm">
                <input type="checkbox" wire:model="{{ $model }}" value="{{ $option['value'] }}"
                       class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                <span>{{ $option['label'] }}</span>
            </label>
        @endforeach
    </div>

    @error($model) <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
    @error($model . '.*') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
</fieldset>
