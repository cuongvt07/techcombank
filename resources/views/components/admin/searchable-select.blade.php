@props([
    'id',
    'label',
    'model',
    'options' => [],
    'selected' => null,
    'placeholder' => 'Tìm để chọn...',
    'emptyLabel' => '— Không ràng buộc —',
])

<div wire:key="searchable-{{ $id }}-{{ $selected ?? 'empty' }}"
     x-data="{
         options: @js($options),
         selected: @js((string) ($selected ?? '')),
         query: '',
         open: false,
         get selectedLabel() {
             return this.options.find(option => String(option.value) === String(this.selected))?.label ?? '';
         },
         get filteredOptions() {
             const term = this.query.trim().toLocaleLowerCase('vi');
             return term === '' ? this.options : this.options.filter(option => option.label.toLocaleLowerCase('vi').includes(term));
         },
         choose(option) {
             this.selected = String(option.value ?? '');
             this.query = option.label;
             this.open = false;
             $wire.set(@js($model), this.selected === '' ? null : this.selected);
         }
     }"
     x-init="query = selectedLabel"
     x-on:click.outside="open = false; query = selectedLabel"
     class="relative">
    <label for="{{ $id }}" class="admin-label">{{ $label }}</label>
    <input id="{{ $id }}"
           type="search"
           x-model="query"
           x-on:focus="open = true; query = ''"
           x-on:input="open = true"
           x-on:keydown.escape.prevent="open = false; query = selectedLabel"
           x-on:keydown.enter.prevent="filteredOptions.length && choose(filteredOptions[0])"
           autocomplete="off"
           role="combobox"
           aria-autocomplete="list"
           x-bind:aria-expanded="open.toString()"
           aria-controls="{{ $id }}-options"
           class="admin-input"
           placeholder="{{ $placeholder }}">

    <div x-cloak x-show="open" id="{{ $id }}-options" role="listbox"
         class="absolute z-40 mt-1 max-h-60 w-full overflow-y-auto rounded-admin border border-brand-line bg-white py-1 shadow-admin">
        @if ($emptyLabel !== '')
            <button type="button" role="option" x-on:click="choose({ value: '', label: @js($emptyLabel) })"
                    class="block w-full px-3 py-2 text-left text-sm text-brand-muted hover:bg-brand-soft">
                {{ $emptyLabel }}
            </button>
        @endif
        <template x-for="option in filteredOptions" :key="option.value">
            <button type="button" role="option" x-on:click="choose(option)"
                    x-bind:aria-selected="String(option.value) === String(selected)"
                    class="block w-full truncate px-3 py-2 text-left text-sm text-brand-ink hover:bg-brand-soft"
                    x-text="option.label"></button>
        </template>
        <p x-show="filteredOptions.length === 0" class="px-3 py-2 text-sm text-brand-muted">Không tìm thấy dữ liệu phù hợp.</p>
    </div>
</div>
