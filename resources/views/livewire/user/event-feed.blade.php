<div>
    {{-- Hero gọn hơn trang khóa học: đây là màn đầu tiên, nội dung mới là chính --}}
    <section class="relative mb-5 overflow-hidden rounded-user-lg bg-gradient-to-br from-brand-red via-brand-red to-brand-red-dark p-5 text-white shadow-user sm:p-6">
        <div class="pointer-events-none absolute inset-0 opacity-[0.12]"
             style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 20px 20px;"></div>
        <div class="pointer-events-none absolute -right-14 -top-14 h-48 w-48 rounded-full bg-white/[.07]"></div>

        <div class="relative">
            <p class="text-xs font-semibold uppercase tracking-wider text-white/70">
                {{ now()->hour < 12 ? 'Chào buổi sáng' : (now()->hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối') }}
            </p>
            <h2 class="mt-1.5 truncate text-xl font-bold sm:text-2xl">
                {{ $employee?->full_name ?? auth()->user()->name }}
            </h2>

            @if ($personalCount > 0)
                <p class="mt-2 inline-flex items-center gap-1.5 rounded-user-pill bg-white/[.18] px-3 py-1.5 text-xs font-semibold backdrop-blur">
                    @svg('heroicon-s-bell-alert', 'h-4 w-4')
                    Bạn có {{ $personalCount }} sự kiện dành riêng cho mình
                </p>
            @else
                <p class="mt-2 text-sm text-white/85">Sự kiện và thông báo mới nhất của công ty.</p>
            @endif
        </div>
    </section>

    {{-- Bộ lọc --}}
    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        @foreach ([
            '' => 'Tất cả',
            'personal' => 'Riêng tôi',
            'upcoming' => 'Sắp diễn ra',
        ] as $key => $label)
            <button type="button"
                    wire:click="setFilter('{{ $key }}')"
                    @class([
                        'user-pill transition-all duration-200',
                        'bg-brand-red text-white shadow-user-sm' => $filter === $key,
                        'hover:bg-brand-line' => $filter !== $key,
                    ])>
                {{ $label }}
                @if ($key === 'personal' && $personalCount > 0)
                    <span @class([
                        'ml-1 rounded-user-pill px-1.5 text-[10px] font-bold',
                        'bg-white/25' => $filter === $key,
                        'bg-brand-red text-white' => $filter !== $key,
                    ])>{{ $personalCount }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="grid gap-3">
        @forelse ($events as $event)
            @php
                $personal = $event->isPersonal();
                $now = $event->isHappeningNow();
                $ended = $event->hasEnded();
            @endphp

            <article wire:key="ev-{{ $event->id }}"
                     @class([
                         'group relative overflow-hidden rounded-user-md border bg-white p-4 shadow-user-sm',
                         'transition-all duration-300 hover:-translate-y-0.5 hover:shadow-user',
                         // Sự kiện riêng có viền đỏ + dải màu bên trái để nhận ra ngay
                         'border-brand-red' => $personal,
                         'border-brand-line' => ! $personal,
                         'opacity-70' => $ended,
                     ])>

                @if ($personal)
                    <span class="absolute inset-y-0 left-0 w-1 bg-brand-red" aria-hidden="true"></span>
                @endif

                <div class="{{ $personal ? 'pl-3' : '' }}">
                    <div class="mb-2 flex flex-wrap items-center gap-1.5">
                        @if ($personal)
                            <span class="inline-flex items-center gap-1 rounded-user-pill bg-brand-red px-2.5 py-1 text-[11px] font-semibold text-white">
                                @svg('heroicon-s-user', 'h-3 w-3')
                                Dành riêng cho bạn
                            </span>
                        @else
                            <span class="rounded-user-pill bg-brand-soft px-2.5 py-1 text-[11px] font-semibold text-brand-muted">
                                Toàn công ty
                            </span>
                        @endif

                        <span class="rounded-user-pill bg-brand-soft px-2.5 py-1 text-[11px] font-medium text-brand-muted">
                            {{ $event->typeLabel() }}
                        </span>

                        @if ($now)
                            <span class="inline-flex items-center gap-1 rounded-user-pill bg-state-success px-2.5 py-1 text-[11px] font-semibold text-white">
                                <span class="h-1.5 w-1.5 rounded-full bg-white"></span>
                                Đang diễn ra
                            </span>
                        @elseif ($ended)
                            <span class="rounded-user-pill bg-brand-soft px-2.5 py-1 text-[11px] font-medium text-brand-muted">
                                Đã kết thúc
                            </span>
                        @elseif ($event->isUpcoming())
                            <span class="rounded-user-pill bg-state-warning px-2.5 py-1 text-[11px] font-semibold text-white">
                                {{ $event->starts_at->diffForHumans(['parts' => 1]) }}
                            </span>
                        @endif
                    </div>

                    <h3 class="text-[15px] font-bold leading-snug transition-colors group-hover:text-brand-red">
                        {{ $event->title }}
                    </h3>

                    <p class="mt-1.5 whitespace-pre-line text-[13px] leading-relaxed text-brand-muted">
                        {{ $event->content }}
                    </p>

                    @if ($event->starts_at || $event->location)
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-brand-line pt-3 text-xs text-brand-muted">
                            @if ($event->starts_at)
                                <span class="inline-flex items-center gap-1.5">
                                    @svg('heroicon-o-calendar-days', 'h-4 w-4 shrink-0')
                                    <span>
                                        {{ $event->starts_at->format('H:i · d/m/Y') }}
                                        @if ($event->ends_at)
                                            – {{ $event->ends_at->isSameDay($event->starts_at)
                                                ? $event->ends_at->format('H:i')
                                                : $event->ends_at->format('H:i · d/m/Y') }}
                                        @endif
                                    </span>
                                </span>
                            @endif

                            @if ($event->location)
                                <span class="inline-flex items-center gap-1.5">
                                    @svg('heroicon-o-map-pin', 'h-4 w-4 shrink-0')
                                    {{ $event->location }}
                                </span>
                            @endif
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <div class="user-card p-10 text-center">
                <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-brand-red-tint text-brand-red">
                    @svg('heroicon-o-calendar-days', 'h-8 w-8')
                </span>
                <p class="mt-4 font-bold">
                    {{ $filter === 'personal' ? 'Chưa có sự kiện nào dành riêng cho bạn.' : 'Chưa có sự kiện nào.' }}
                </p>
                <p class="mt-1 text-sm text-brand-muted">
                    Sự kiện mới sẽ xuất hiện ở đây khi quản trị viên đăng.
                </p>
            </div>
        @endforelse
    </div>
</div>
