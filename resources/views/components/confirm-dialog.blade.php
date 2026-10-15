@props([
    // 'admin' dùng bo góc sắc và nút admin; 'user' dùng bo góc mềm của site người dùng
    'scope' => 'admin',
])

{{--
    Popup xác nhận ở giữa màn hình, thay cho hộp thoại confirm() của trình duyệt.

    Dùng: thêm data-confirm="Nội dung hỏi" vào bất kỳ nút nào, giữ nguyên wire:click.
      data-confirm-title="..."   tiêu đề (mặc định "Xác nhận")
      data-confirm-ok="..."      chữ trên nút đồng ý (mặc định "Đồng ý")
      data-confirm-danger        tô đỏ nút đồng ý, dùng cho xóa / thu hồi

    Cơ chế: bắt click ở pha capture trên document, chặn lại trước khi Livewire kịp
    nhận, hỏi người dùng, đồng ý thì bấm lại chính nút đó với cờ "đã xác nhận".
    Nhờ vậy không phải sửa từng hàm xử lý bên PHP.
--}}
@php
    $panel = $scope === 'user' ? 'user-card' : 'admin-panel';
    $btnCancel = $scope === 'user' ? 'user-btn-secondary' : 'admin-btn-secondary';
    $btnOk = $scope === 'user' ? 'user-btn' : 'admin-btn';
@endphp

<div x-data="{
        open: false,
        title: 'Xác nhận',
        message: '',
        ok: 'Đồng ý',
        danger: false,
        target: null,

        init() {
            // Layout có thể được dựng lại khi chuyển trang (wire:navigate): gỡ listener
            // của lần dựng trước, nếu không nó vẫn chạy và mở một popup vô hình
            if (window.__confirmDialogListener) {
                document.removeEventListener('click', window.__confirmDialogListener, true);
            }

            window.__confirmDialogListener = (e) => {
                const el = e.target.closest('[data-confirm]');
                if (!el) return;

                // Lần bấm lại sau khi đã đồng ý: cho đi tiếp tới Livewire
                if (el.dataset.confirmed === '1') {
                    delete el.dataset.confirmed;
                    return;
                }

                e.preventDefault();
                e.stopImmediatePropagation();
                this.ask(el);
            };

            document.addEventListener('click', window.__confirmDialogListener, true);
        },

        ask(el) {
            this.target = el;
            this.title = el.dataset.confirmTitle || 'Xác nhận';
            this.message = el.dataset.confirm || '';
            this.ok = el.dataset.confirmOk || 'Đồng ý';
            this.danger = el.hasAttribute('data-confirm-danger');
            this.open = true;
            this.$nextTick(() => this.$refs.cancelBtn?.focus());
        },

        accept() {
            const el = this.target;
            this.open = false;
            this.target = null;

            if (el && el.isConnected) {
                el.dataset.confirmed = '1';
                el.click();
            }
        },

        cancel() {
            this.open = false;
            this.target = null;
        },
     }"
     x-show="open"
     x-cloak
     x-on:keydown.escape.window="open && cancel()"
     class="fixed inset-0 grid place-items-center p-4"
     style="z-index: 90"
     role="alertdialog"
     aria-modal="true"
     aria-labelledby="confirm-dialog-title">

    <div class="fixed inset-0 bg-brand-black/50" x-on:click="cancel()"></div>

    <div class="{{ $panel }} relative w-full p-6 text-center shadow-user"
         style="max-width: 420px"
         x-on:click.stop>

        {{-- Icon: đỏ cho thao tác nguy hiểm, trung tính cho thao tác thường --}}
        <span class="mx-auto grid h-12 w-12 place-items-center rounded-full"
              x-bind:class="danger ? 'bg-brand-red-tint text-brand-red' : 'bg-brand-soft text-brand-ink'">
            <template x-if="danger">@svg('heroicon-o-exclamation-triangle', 'h-6 w-6')</template>
            <template x-if="! danger">@svg('heroicon-o-question-mark-circle', 'h-6 w-6')</template>
        </span>

        <h3 id="confirm-dialog-title" class="mt-4 text-base font-bold" x-text="title"></h3>
        <p class="mt-2 text-sm leading-relaxed text-brand-muted" x-text="message"></p>

        <div class="mt-6 grid grid-cols-2 gap-2">
            <button type="button" x-ref="cancelBtn" x-on:click="cancel()" class="{{ $btnCancel }}">
                Hủy
            </button>
            <button type="button" x-on:click="accept()" class="{{ $btnOk }}" x-text="ok"></button>
        </div>
    </div>
</div>
