{{--
    Popup trợ lý AI (spec 4.3). Nút tròn góc dưới phải, có mặt ở mọi trang
    site người dùng.

    Trên mobile nút nâng lên trên bottom tab bar để không che mất tab.
--}}
<div>
    {{-- Nút mở --}}
    <button type="button"
            wire:click="toggle"
            @class([
                'fixed right-4 z-40 grid h-14 w-14 place-items-center rounded-full text-white shadow-lg',
                'transition-all duration-300 hover:scale-105 active:scale-95',
                'bg-brand-red hover:bg-brand-red-dark',
                // Mobile: nằm trên bottom tab bar (56px) + khoảng thở
                'bottom-[76px] lg:bottom-6',
            ])
            aria-label="{{ $open ? 'Đóng trợ lý AI' : 'Mở trợ lý AI' }}">
        @if ($open)
            @svg('heroicon-o-x-mark', 'h-6 w-6')
        @else
            @svg('heroicon-s-sparkles', 'h-6 w-6')
        @endif
    </button>

    @if ($open)
        <div class="fixed inset-x-4 z-40 flex flex-col overflow-hidden rounded-user-lg border border-brand-line bg-white shadow-2xl
                    bottom-[144px] max-h-[min(560px,calc(100vh-200px))]
                    lg:inset-x-auto lg:right-6 lg:bottom-24 lg:w-[400px] lg:max-h-[560px]"
             role="dialog"
             aria-label="Trợ lý AI đào tạo">

            {{-- Đầu popup --}}
            <div class="flex items-center gap-3 border-b border-brand-line bg-brand-red px-4 py-3 text-white">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white/20">
                    @svg('heroicon-s-sparkles', 'h-4 w-4')
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold">Trợ lý đào tạo</p>
                    <p class="truncate text-[11px] text-white/75">Trả lời từ tài liệu bạn được phép xem</p>
                </div>

                @if ($messages)
                    <button type="button" wire:click="startNew"
                            class="shrink-0 rounded-user-pill px-2.5 py-1 text-[11px] font-semibold transition-colors hover:bg-white/20"
                            title="Bắt đầu hội thoại mới">
                        Hỏi mới
                    </button>
                @endif
            </div>

            {{-- Khung hội thoại --}}
            <div class="flex-1 space-y-3 overflow-y-auto p-4" id="ai-chat-scroll">
                @if (! $enabled)
                    <div class="rounded-user-md border border-state-warning bg-state-warning-tint p-4 text-center">
                        <p class="text-sm font-semibold text-state-warning">Trợ lý AI chưa được bật</p>
                        <p class="mt-1 text-xs text-brand-muted">
                            Quản trị viên cần cấu hình khóa API trong biến môi trường GROQ_API_KEY.
                        </p>
                    </div>
                @elseif (! $messages)
                    <div class="py-2 text-center">
                        <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-brand-red-tint text-brand-red">
                            @svg('heroicon-o-chat-bubble-left-right', 'h-6 w-6')
                        </span>
                        <p class="mt-3 text-sm font-semibold">Hỏi gì về nội dung đào tạo?</p>
                        <p class="mt-1 text-xs text-brand-muted">
                            Tôi chỉ trả lời dựa trên bài giảng và tài liệu bạn được phép xem.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        @foreach ($suggestions as $suggestion)
                            <button type="button"
                                    wire:click="useSuggestion(@js($suggestion))"
                                    class="rounded-user-md border border-brand-line px-3 py-2 text-left text-[13px] transition-colors hover:border-brand-red hover:bg-brand-red-tint">
                                {{ $suggestion }}
                            </button>
                        @endforeach
                    </div>
                @endif

                @foreach ($messages as $i => $message)
                    @if ($message['role'] === 'user')
                        <div class="flex justify-end" wire:key="msg-{{ $i }}">
                            <p class="max-w-[85%] whitespace-pre-line rounded-user-md bg-brand-red px-3 py-2 text-[13px] leading-relaxed text-white">
                                {{ $message['content'] }}
                            </p>
                        </div>
                    @else
                        <div class="flex justify-start" wire:key="msg-{{ $i }}">
                            <div class="max-w-[90%]">
                                {{--
                                    Model trả về markdown nhẹ (**đậm**, gạch đầu dòng).
                                    Escape trước rồi mới đổi ** thành <strong>, nên nội
                                    dung do model sinh ra không thể chèn HTML vào trang.
                                --}}
                                <div class="whitespace-pre-line rounded-user-md bg-brand-soft px-3 py-2 text-[13px] leading-relaxed">
                                    {!! preg_replace(
                                        '/\*\*(.+?)\*\*/s',
                                        '<strong>$1</strong>',
                                        e($message['content'])
                                    ) !!}
                                </div>

                                @if (! empty($message['sources']))
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        @foreach ($message['sources'] as $source)
                                            @if (! empty($source['url']))
                                                <a href="{{ $source['url'] }}" wire:navigate
                                                   class="inline-flex max-w-full items-center gap-1 rounded-user-pill border border-brand-line px-2 py-0.5 text-[10px] text-brand-muted transition-colors hover:border-brand-red hover:text-brand-red">
                                                    @svg('heroicon-o-link', 'h-3 w-3 shrink-0')
                                                    <span class="truncate">{{ $source['title'] }}</span>
                                                </a>
                                            @else
                                                <span class="inline-flex max-w-full items-center rounded-user-pill border border-brand-line px-2 py-0.5 text-[10px] text-brand-muted">
                                                    <span class="truncate">{{ $source['title'] }}</span>
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach

                {{-- Đang chờ trả lời --}}
                <div wire:loading wire:target="send, useSuggestion" class="flex justify-start">
                    <div class="flex items-center gap-1.5 rounded-user-md bg-brand-soft px-3 py-2.5">
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-brand-muted" style="animation-delay:0ms"></span>
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-brand-muted" style="animation-delay:150ms"></span>
                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-brand-muted" style="animation-delay:300ms"></span>
                    </div>
                </div>

                @if ($errorMessage)
                    <p class="rounded-user-md border border-brand-red bg-brand-red-tint px-3 py-2 text-xs font-semibold text-brand-red">
                        {{ $errorMessage }}
                    </p>
                @endif
            </div>

            {{-- Ô nhập --}}
            @if ($enabled)
                <form wire:submit="send" class="flex items-end gap-2 border-t border-brand-line p-3">
                    <textarea wire:model="question"
                              rows="1"
                              placeholder="Nhập câu hỏi..."
                              class="max-h-24 min-h-[40px] flex-1 resize-none rounded-user-md border border-brand-line px-3 py-2 text-[13px] focus:border-brand-red focus:outline-none focus:ring-1 focus:ring-brand-red"
                              x-on:keydown.enter.prevent="$wire.send()"
                              x-on:input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,96)+'px'"></textarea>

                    <button type="submit"
                            class="grid h-10 w-10 shrink-0 place-items-center rounded-user-md bg-brand-red text-white transition-colors hover:bg-brand-red-dark disabled:opacity-50"
                            wire:loading.attr="disabled"
                            wire:target="send, useSuggestion"
                            aria-label="Gửi câu hỏi">
                        @svg('heroicon-s-paper-airplane', 'h-4 w-4')
                    </button>
                </form>
            @endif
        </div>
    @endif

    {{-- Tự cuộn xuống tin nhắn mới nhất --}}
    <script>
        document.addEventListener('livewire:updated', () => {
            const box = document.getElementById('ai-chat-scroll');
            if (box) box.scrollTop = box.scrollHeight;
        });
    </script>
</div>
