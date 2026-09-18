@props([
    'wireModel' => 'showModal',
    'title' => '',
    'maxWidth' => 'max-w-2xl',
])

{{--
    Modal dùng chung cho form tạo/sửa. Alpine đi kèm Livewire 3 lo phần đóng bằng ESC.
    Bo góc theo thang admin (sắc cạnh), không dùng hiệu ứng trang trí (spec 6.4).
--}}
<div x-data
     x-show="$wire.{{ $wireModel }}"
     x-on:keydown.escape.window="$wire.{{ $wireModel }} = false"
     x-cloak
     class="fixed inset-0 z-50 overflow-y-auto"
     role="dialog"
     aria-modal="true">

    <div class="fixed inset-0 bg-brand-black/50" x-on:click="$wire.{{ $wireModel }} = false"></div>

    <div class="relative flex min-h-full items-start justify-center p-4 sm:p-6">
        <div class="admin-panel w-full {{ $maxWidth }} shadow-user" x-on:click.stop>
            <div class="admin-panel-head">
                <h3>{{ $title }}</h3>
                <button type="button"
                        wire:click="$set('{{ $wireModel }}', false)"
                        class="grid h-8 w-8 place-items-center rounded-admin text-brand-muted transition-colors hover:bg-brand-soft hover:text-brand-ink"
                        aria-label="Đóng">
                    @svg('heroicon-o-x-mark', 'h-4 w-4')
                </button>
            </div>

            <div class="p-5">
                {{ $slot }}
            </div>

            @if (isset($footer))
                <div class="flex items-center justify-end gap-2 border-t border-brand-line px-5 py-4">
                    {{ $footer }}
                </div>
            @endif
        </div>
    </div>
</div>
