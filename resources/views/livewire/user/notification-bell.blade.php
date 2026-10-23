@php
    $unread = $counts['all'] ?? 0;
    // Site quản trị: góc vuông vức theo admin; site nhân viên: bo góc mềm user-*
    $panelRound = $admin ? 'rounded-admin' : 'rounded-user-md';
    $pillRound = $admin ? 'rounded-admin-sm' : 'rounded-user-pill';
@endphp

<div class="relative shrink-0"
     {{-- fit(): chuông không nằm sát mép phải (vd. thanh quản trị trên màn hẹp) thì
          đẩy panel vào trong để không tràn ra ngoài màn hình --}}
     x-data="{
         open: false,
         fit() {
             const p = this.$refs.panel;
             p.style.right = '0px';
             const left = p.getBoundingClientRect().left;
             if (left < 16) p.style.right = (left - 16) + 'px';
         },
     }"
     @keydown.escape.window="open = false"
     @click.outside="open = false"
     wire:poll.60s>
    @if ($enabled)
        <button type="button"
                @click="open = ! open; setTimeout(() => open && fit(), 0)"
                :aria-expanded="open.toString()"
                @class([
                    'relative grid h-11 w-11 place-items-center transition-colors hover:bg-brand-soft',
                    $admin ? 'rounded-admin-sm' : 'rounded-user-pill',
                    'text-brand-red' => $unread > 0,
                    'text-brand-ink' => $unread === 0,
                ])
                aria-label="Thông báo{{ $unread > 0 ? ' — ' . $unread . ' chưa đọc' : '' }}">
            @svg($unread > 0 ? 'heroicon-o-bell-alert' : 'heroicon-o-bell', 'h-6 w-6')

            @if ($unread > 0)
                {{-- Ẩn khi 0; quá 9 thì "9+" để badge không phình to --}}
                <span class="absolute right-0 top-0 grid h-5 place-items-center rounded-full border-2 border-white bg-brand-red px-1 text-[10px] font-bold leading-none text-white tabular-nums"
                      style="min-width: 20px;">
                    {{ $unread > 9 ? '9+' : $unread }}
                </span>
            @endif
        </button>

        {{-- Neo phải theo chuông; bề rộng co theo màn hình (390px vẫn cách mép 16px) --}}
        <div x-show="open"
             x-ref="panel"
             x-transition.origin.top.right
             style="display: none; top: calc(100% + 6px); width: min(380px, calc(100vw - 32px));"
             class="absolute right-0 z-50 overflow-hidden border border-brand-line bg-white shadow-user {{ $panelRound }}">
            <div class="flex items-center justify-between gap-3 px-4 pt-3">
                <div class="min-w-0">
                    <p class="text-sm font-bold">Thông báo</p>
                    <p class="text-xs text-brand-muted">
                        {{ $unread > 0 ? $unread . ' chưa đọc' : 'Bạn đã xem hết' }}
                    </p>
                </div>

                @if (($counts[$tab] ?? 0) > 0)
                    <button type="button"
                            wire:click="markAllRead"
                            class="shrink-0 px-2 py-1 text-xs font-semibold text-brand-red hover:bg-brand-red-tint {{ $pillRound }}">
                        Đánh dấu đã đọc tất cả
                    </button>
                @endif
            </div>

            {{-- Tab theo loại, mỗi tab có số chưa đọc riêng --}}
            <div class="flex gap-1 overflow-x-auto border-b border-brand-line px-4 py-2.5">
                @foreach ($tabs as $key => $label)
                    @php $n = $counts[$key] ?? 0; @endphp
                    {{-- Site quản trị: tab phụ chỉ hiện khi có việc --}}
                    @continue($admin && ! in_array($key, ['all', 'support'], true) && $n === 0 && $tab !== $key)

                    <button type="button"
                            wire:click="setTab('{{ $key }}')"
                            @class([
                                'inline-flex shrink-0 items-center gap-1 px-2 py-1 text-xs font-semibold transition-colors',
                                $pillRound,
                                'bg-brand-ink text-white' => $tab === $key,
                                'bg-brand-soft text-brand-muted hover:text-brand-ink' => $tab !== $key,
                            ])>
                        {{ $label }}
                        @if ($n > 0)
                            <span @class([
                                'rounded-full px-1.5 text-[10px] font-bold leading-4',
                                'bg-white text-brand-ink' => $tab === $key,
                                'bg-brand-red text-white' => $tab !== $key,
                            ])>{{ $n > 99 ? '99+' : $n }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="overflow-y-auto" style="max-height: min(420px, 60vh);">
                @forelse ($items as $item)
                    <button type="button"
                            wire:key="bell-{{ $item['key'] }}"
                            wire:click="open('{{ $item['key'] }}')"
                            @class([
                                'flex w-full items-start gap-3 border-b border-brand-line px-4 py-3 text-left transition-colors last:border-b-0',
                                'bg-brand-red-tint hover:bg-brand-soft' => ! $item['read'],
                                'hover:bg-brand-soft' => $item['read'],
                            ])>
                        {{-- Lời nhắc / thông báo học tập có biểu tượng riêng để nhận ra ngay --}}
                        <span @class([
                            'grid h-9 w-9 shrink-0 place-items-center rounded-full',
                            'bg-brand-red text-white' => $item['accent'],
                            'bg-brand-soft text-brand-muted' => ! $item['accent'],
                        ])>
                            @svg($item['icon'], 'h-5 w-5')
                        </span>

                        <span class="min-w-0 flex-1">
                            <span @class([
                                'text-[13px] leading-snug line-clamp-2',
                                'font-bold text-brand-ink' => ! $item['read'],
                                'font-semibold text-brand-muted' => $item['read'],
                            ])>{{ $item['title'] }}</span>
                            @if ($item['body'] !== '')
                                <span class="mt-0.5 text-xs leading-relaxed text-brand-muted line-clamp-2">{{ $item['body'] }}</span>
                            @endif
                            <span class="mt-1 block text-[11px] text-brand-muted">
                                {{ \App\Services\NotificationCenter::CATEGORY_LABELS[$item['category']] ?? '' }}
                                · {{ $item['at']?->diffForHumans() }}
                            </span>
                        </span>

                        @unless ($item['read'])
                            <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-brand-red" aria-label="Chưa đọc"></span>
                        @endunless
                    </button>
                @empty
                    <div class="p-6 text-center">
                        <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-brand-soft text-brand-muted">
                            @svg('heroicon-o-bell', 'h-6 w-6')
                        </span>
                        <p class="mt-3 text-sm font-semibold">Chưa có thông báo nào</p>
                    </div>
                @endforelse
            </div>

            @unless ($admin)
                <a href="{{ route('learn.events') }}"
                   wire:navigate
                   @click="open = false"
                   class="block border-t border-brand-line px-4 py-3 text-center text-sm font-bold text-brand-red hover:bg-brand-soft">
                    Xem tất cả sự kiện
                </a>
            @endunless
        </div>
    @endif
</div>
