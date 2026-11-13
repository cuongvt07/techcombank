<div>
    @if (session('error'))
        <div class="mb-4 rounded-user-md border border-brand-red bg-brand-red-tint px-4 py-3 text-sm font-semibold text-brand-red">
            {{ session('error') }}
        </div>
    @endif

    @if (! $attempt)
        {{-- Màn hình trước khi bắt đầu --}}
        <div class="mx-auto max-w-2xl">
            <div class="user-card p-6 text-center sm:p-8">
                <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-brand-red-tint text-brand-red">
                    @svg('heroicon-o-clipboard-document-check', 'h-8 w-8')
                </span>

                <h1 class="mt-4 text-xl font-bold">{{ $quiz->title }}</h1>
                @if ($quiz->description)
                    <p class="mt-2 text-sm text-brand-muted">{{ $quiz->description }}</p>
                @endif

                <dl class="mt-5 grid grid-cols-2 gap-3 text-left sm:grid-cols-3">
                    <div class="rounded-user-md bg-brand-soft p-3">
                        <dt class="text-[11px] font-semibold uppercase text-brand-muted">Điểm đạt</dt>
                        <dd class="mt-0.5 text-lg font-bold">{{ $quiz->pass_score }}%</dd>
                    </div>
                    <div class="rounded-user-md bg-brand-soft p-3">
                        <dt class="text-[11px] font-semibold uppercase text-brand-muted">Thời gian</dt>
                        <dd class="mt-0.5 text-lg font-bold">
                            {{ $quiz->duration_minutes ? $quiz->duration_minutes . '′' : '∞' }}
                        </dd>
                    </div>
                    <div class="rounded-user-md bg-brand-soft p-3">
                        <dt class="text-[11px] font-semibold uppercase text-brand-muted">Đã làm</dt>
                        <dd class="mt-0.5 text-lg font-bold">
                            {{ $attemptsUsed }}{{ $quiz->max_attempts ? '/' . $quiz->max_attempts : '' }}
                        </dd>
                    </div>
                </dl>

                @if ($canAttempt)
                    <button type="button" wire:click="startAttempt" class="user-btn mt-6 w-full sm:w-auto">
                        Bắt đầu làm bài
                    </button>
                @else
                    <p class="mt-6 rounded-user-md border border-brand-red bg-brand-red-tint px-4 py-3 text-sm font-semibold text-brand-red">
                        Bạn đã dùng hết số lần làm bài cho phép.
                    </p>
                @endif

                <a wire:navigate href="{{ route('learn.course', $course) }}" class="mt-3 inline-block text-xs font-semibold text-brand-muted hover:text-brand-red">
                    Quay lại khóa học
                </a>
            </div>

            {{-- Lịch sử các lần thi trước (spec 3.2.3) --}}
            @if ($history->isNotEmpty())
                <div class="user-card mt-4 overflow-hidden">
                    <h2 class="border-b border-brand-line px-5 py-3 text-sm font-bold">Lịch sử làm bài</h2>
                    <ul>
                        @foreach ($history as $past)
                            <li class="flex items-center justify-between gap-3 border-b border-brand-line px-5 py-3 last:border-b-0">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold">Lần {{ $past->attempt_no }}</p>
                                    <p class="text-xs text-brand-muted">
                                        {{ $past->submitted_at?->format('d/m/Y H:i') ?? '—' }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="text-sm font-bold">{{ (int) $past->percentage }}%</span>
                                    <span class="tag {{ $past->is_passed ? 'tag-green' : 'tag-red' }}">
                                        {{ $past->is_passed ? 'Đạt' : 'Chưa đạt' }}
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

    @elseif ($attempt->isInProgress())
        {{-- Đang làm bài (bố cục theo mẫu: thanh đầu + tiến độ, thẻ câu hỏi, cột điều hướng) --}}
        @php
            $total = $questions->count();
            $currentIndex = min($currentIndex, max(0, $total - 1));
            $current = $questions->get($currentIndex);
            $answeredCount = $questions->filter(fn ($a) => ! empty($answers[$a->question_id] ?? []))->count();
            $progressPercent = $total > 0 ? round($answeredCount / $total * 100) : 0;
            $topic = $course->title;
        @endphp

        {{-- Style riêng của màn làm bài: không phụ thuộc class Tailwind chưa có trong bản build --}}
        <style>
            .qp-layout { display: grid; gap: 1rem; }
            @media (min-width: 1024px) { .qp-layout { grid-template-columns: minmax(0, 1fr) 280px; align-items: start; } }
            .qp-progress { height: 6px; border-radius: 999px; background: #e4e7ee; overflow: hidden; }
            .qp-progress > span { display: block; height: 100%; border-radius: 999px; background: #c00016; transition: width .3s ease; }
            .qp-timer { display: inline-flex; align-items: center; gap: .5rem; border-radius: 999px; border: 1px solid #f7c9a8; background: #fff4ec; color: #c2410c; padding: .45rem .9rem; font-size: .8rem; font-weight: 600; white-space: nowrap; }
            .qp-timer.is-urgent { border-color: #c00016; background: #fdf2f3; color: #c00016; }
            .qp-timer b { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1rem; }
            .qp-eyebrow { font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #c00016; }
            .qp-option { display: flex; width: 100%; align-items: flex-start; gap: .75rem; border: 1px solid #e4e7ee; border-radius: 12px; padding: .8rem 1rem; text-align: left; background: #fff; transition: border-color .15s, background-color .15s; }
            .qp-option:hover { border-color: #c00016; background: #fafbfc; }
            .qp-option.is-selected { border-color: #c00016; background: #fdf2f3; color: #c00016; font-weight: 600; }
            .qp-mark { margin-top: .1rem; display: grid; place-items: center; width: 18px; height: 18px; flex-shrink: 0; border: 2px solid #c4c9d4; border-radius: 999px; background: #fff; }
            .qp-mark.is-square { border-radius: 5px; }
            .qp-option.is-selected .qp-mark { border-color: #c00016; }
            .qp-option.is-selected .qp-mark::after { content: ''; width: 8px; height: 8px; border-radius: inherit; background: #c00016; }
            .qp-option.is-selected .qp-mark.is-square::after { border-radius: 2px; }
            .qp-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .4rem; }
            @media (max-width: 1023px) { .qp-grid { grid-template-columns: repeat(auto-fill, minmax(40px, 1fr)); } }
            .qp-num { height: 36px; border-radius: 8px; font-size: .75rem; font-weight: 700; background: #f0f2f5; color: #9aa1ad; transition: transform .1s; }
            .qp-num:hover { transform: translateY(-1px); }
            /* Đã trả lời: xanh lá đậm theo góp ý người dùng */
            .qp-num.is-answered { background: #15803d; color: #fff; }
            .qp-num.is-current { background: #c00016; color: #fff; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #c00016; }
            .qp-nav-body { display: none; }
            .qp-nav-body.is-open { display: block; }
            @media (min-width: 1024px) { .qp-nav-body { display: block; } .qp-nav-toggle-icon { display: none; } }
            .qp-submit { display: flex; width: 100%; align-items: center; justify-content: center; gap: .5rem; min-height: 44px; border-radius: 12px; background: #141821; color: #fff; font-size: .85rem; font-weight: 700; transition: background-color .15s; }
            .qp-submit:hover { background: #05070a; }
            .qp-modal { position: fixed; inset: 0; z-index: 120; display: grid; place-items: center; padding: 1rem; background: rgba(5, 7, 10, .55); }
        </style>

        <div class="mx-auto" style="max-width: 72rem"
             x-data="{
                modal: null,
                pendingUrl: null,
                pendingHard: false,
                allowLeave: false,
                leftTab: false,
                busy: false,
                navOpen: false,
                quizUrl: window.location.href,
                quizState: window.history.state,
                handlers: {},
                root: null,

                init() {
                    // Trang được dựng lại (wire:navigate / morph) thì gỡ listener cũ trước
                    if (window.__quizGuardCleanup) window.__quizGuardCleanup();
                    this.root = this.$el;

                    // 1) Chuyển trang bằng wire:navigate (kể cả nút Back của trình duyệt)
                    this.handlers.navigate = (e) => {
                        if (this.inactive()) return;
                        const url = e.detail && e.detail.url;
                        const href = url ? (url.href || String(url)) : null;
                        if (!href || href === this.quizUrl) return;
                        e.preventDefault();
                        // Nút Back đã đổi URL trên thanh địa chỉ: trả lại URL bài thi
                        if (e.detail.history) window.history.pushState(this.quizState, '', this.quizUrl);
                        this.ask(href, false);
                    };

                    // 2) Link thường (không wire:navigate): chặn ở pha capture
                    this.handlers.click = (e) => {
                        if (this.inactive() || e.defaultPrevented) return;
                        const a = e.target.closest('a[href]');
                        if (!a) return;
                        if ([...a.attributes].some((at) => at.name.startsWith('wire:navigate'))) return;
                        if (a.target && a.target !== '_self') return;
                        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                        const raw = a.getAttribute('href') || '';
                        if (raw === '' || raw.startsWith('#') || raw.startsWith('javascript:')) return;
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        this.ask(a.href, true);
                    };

                    // 3) Đóng tab / tải lại: trình duyệt chỉ cho hiện hộp thoại mặc định
                    this.handlers.beforeunload = (e) => {
                        if (this.inactive()) return;
                        e.preventDefault();
                        e.returnValue = '';
                    };

                    // 4) Chuyển sang tab/ứng dụng khác: ghi nhận, quay lại thì cảnh báo
                    this.handlers.visibility = () => {
                        if (this.inactive()) return;
                        if (document.visibilityState === 'hidden') {
                            this.leftTab = true;
                            $wire.recordTabLeave();
                        } else if (this.leftTab) {
                            this.leftTab = false;
                            if (this.modal !== 'leave') this.modal = 'returned';
                        }
                    };

                    document.addEventListener('livewire:navigate', this.handlers.navigate);
                    document.addEventListener('click', this.handlers.click, true);
                    window.addEventListener('beforeunload', this.handlers.beforeunload);
                    document.addEventListener('visibilitychange', this.handlers.visibility);
                    window.__quizGuardCleanup = () => this.cleanup();
                },

                cleanup() {
                    document.removeEventListener('livewire:navigate', this.handlers.navigate);
                    document.removeEventListener('click', this.handlers.click, true);
                    window.removeEventListener('beforeunload', this.handlers.beforeunload);
                    document.removeEventListener('visibilitychange', this.handlers.visibility);
                    if (window.__quizGuardCleanup) window.__quizGuardCleanup = null;
                },

                // Đã cho phép rời trang, hoặc màn làm bài đã bị gỡ (nộp bài xong) mà destroy() chưa kịp chạy
                inactive() {
                    if (this.root && ! this.root.isConnected) { this.cleanup(); return true; }
                    return this.allowLeave;
                },

                // Alpine gọi khi phần tử bị gỡ (nộp bài xong / rời trang)
                destroy() { this.cleanup(); },

                ask(url, hard) {
                    this.pendingUrl = url;
                    this.pendingHard = hard;
                    this.modal = 'leave';
                },

                stay() {
                    this.modal = null;
                    this.pendingUrl = null;
                },

                go() {
                    const url = this.pendingUrl;
                    this.allowLeave = true;
                    this.modal = null;
                    this.cleanup();
                    if (!url) return;
                    if (this.pendingHard || !window.Livewire || !window.Livewire.navigate) {
                        window.location.href = url;
                    } else {
                        window.Livewire.navigate(url);
                    }
                },

                // Bài có cấp chứng nhận: chỉ được rời trang sau khi nộp bài
                async submitAndLeave() {
                    if (this.busy) return;
                    this.busy = true;
                    this.allowLeave = true;
                    try { await $wire.submit(); } finally { this.go(); }
                },
             }">

            {{-- Thanh đầu: tên bài + thông số + đồng hồ --}}
            <div class="user-card flex flex-wrap items-center justify-between gap-3 p-4">
                <div class="min-w-0">
                    <h1 class="text-base font-bold leading-snug sm:text-lg">{{ $quiz->title }}</h1>
                    <p class="mt-0.5 text-xs text-brand-muted">
                        {{ $total }} câu hỏi
                        @if ($quiz->duration_minutes) · {{ $quiz->duration_minutes }} phút @endif
                        · Điểm đạt: {{ $quiz->pass_score }}% trở lên
                    </p>
                </div>

                @if ($attempt->expires_at)
                    {{-- Đếm ngược ở client cho mượt; server vẫn kiểm tra lại mốc hết giờ --}}
                    <div x-data="{
                            remaining: {{ $attempt->remainingSeconds() ?? 0 }},
                            timer: null,
                            init() {
                                this.timer = setInterval(() => {
                                    this.remaining--;
                                    if (this.remaining <= 0) {
                                        clearInterval(this.timer);
                                        $wire.timeUp();
                                    }
                                }, 1000);
                            },
                            destroy() { clearInterval(this.timer); },
                            get label() {
                                const m = Math.floor(Math.max(0, this.remaining) / 60);
                                const s = Math.max(0, this.remaining) % 60;
                                return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
                            }
                         }"
                         wire:ignore
                         class="qp-timer" :class="{ 'is-urgent': remaining < 60 }">
                        @svg('heroicon-o-clock', 'h-4 w-4')
                        <span>Thời gian còn lại</span>
                        <b x-text="label">--:--</b>
                    </div>
                @else
                    <div class="qp-timer">
                        @svg('heroicon-o-clock', 'h-4 w-4')
                        <span>Không giới hạn thời gian</span>
                    </div>
                @endif
            </div>

            {{-- Thanh tiến độ: tỉ lệ câu đã trả lời --}}
            <div class="qp-progress mb-4 mt-4" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                 aria-valuenow="{{ $progressPercent }}" aria-label="Tiến độ làm bài">
                <span style="width: {{ $progressPercent }}%"></span>
            </div>

            <div class="qp-layout">
                {{-- Cột trái: câu hỏi hiện tại --}}
                <div class="min-w-0">
                    @if ($current && $current->question)
                        @php
                            $question = $current->question;
                            $isMultiple = $question->isMultiple();
                            $selected = $answers[$question->id] ?? [];
                        @endphp

                        <div class="user-card p-5 sm:p-6" wire:key="q-{{ $question->id }}">
                            <p class="qp-eyebrow">Câu {{ $currentIndex + 1 }}/{{ $total }} · {{ $topic }}</p>
                            <p class="mt-2 text-base font-bold leading-relaxed">{{ $question->content }}</p>
                            @if ($isMultiple)
                                <p class="mt-1 text-xs font-semibold text-brand-muted">
                                    Chọn tất cả đáp án đúng — phải chọn đủ mới được tính điểm.
                                </p>
                            @endif

                            <div class="mt-4 grid gap-2.5" role="{{ $isMultiple ? 'group' : 'radiogroup' }}">
                                @foreach ($question->options as $option)
                                    @php $isSelected = in_array($option->id, $selected, true); @endphp

                                    <button type="button"
                                            wire:click="selectOption({{ $question->id }}, {{ $option->id }}, {{ $isMultiple ? 'true' : 'false' }})"
                                            role="{{ $isMultiple ? 'checkbox' : 'radio' }}"
                                            aria-checked="{{ $isSelected ? 'true' : 'false' }}"
                                            class="qp-option {{ $isSelected ? 'is-selected' : '' }}">
                                        <span class="qp-mark {{ $isMultiple ? 'is-square' : '' }}"></span>
                                        <span class="text-sm leading-relaxed">{{ $option->content }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-4 flex items-center justify-between gap-3">
                            <button type="button" wire:click="previousQuestion"
                                    class="user-btn-secondary !min-h-[42px] !px-4 !text-xs"
                                    @disabled($currentIndex === 0)>
                                ← Câu trước
                            </button>

                            @if ($currentIndex < $total - 1)
                                <button type="button" wire:click="nextQuestion" class="user-btn !min-h-[42px] !px-5 !text-xs">
                                    Câu tiếp theo →
                                </button>
                            @else
                                <button type="button" wire:click="confirmSubmit" class="user-btn !min-h-[42px] !px-5 !text-xs">
                                    Nộp bài kiểm tra
                                </button>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Cột phải (mobile: xuống dưới, thu gọn được): điều hướng câu hỏi --}}
                <aside class="user-card p-4">
                    <button type="button" class="flex w-full items-center justify-between gap-2 text-left"
                            x-on:click="navOpen = ! navOpen" :aria-expanded="navOpen">
                        <span class="text-sm font-bold">Điều hướng câu hỏi</span>
                        <span class="qp-nav-toggle-icon flex items-center gap-1 text-xs font-semibold text-brand-muted">
                            {{ $answeredCount }}/{{ $total }}
                            <span x-show="! navOpen">@svg('heroicon-o-chevron-down', 'h-4 w-4')</span>
                            <span x-show="navOpen" x-cloak>@svg('heroicon-o-chevron-up', 'h-4 w-4')</span>
                        </span>
                    </button>

                    <div class="qp-nav-body" :class="{ 'is-open': navOpen }">
                        <div class="qp-grid mt-3">
                            @foreach ($questions as $index => $answer)
                                @php $answered = ! empty($answers[$answer->question_id] ?? []); @endphp
                                <button type="button" wire:click="goToQuestion({{ $index }})"
                                        aria-label="Câu {{ $index + 1 }}{{ $answered ? ' — đã trả lời' : '' }}"
                                        @if ($index === $currentIndex) aria-current="step" @endif
                                        class="qp-num {{ $index === $currentIndex ? 'is-current' : ($answered ? 'is-answered' : '') }}">
                                    {{ $index + 1 }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <button type="button" wire:click="confirmSubmit" class="qp-submit mt-4">
                        Nộp bài kiểm tra
                    </button>
                </aside>
            </div>

            {{-- Popup rời trang / quay lại sau khi chuyển tab --}}
            <template x-if="modal">
                <div class="qp-modal" role="dialog" aria-modal="true" x-on:keydown.escape.window="stay()">
                    <div class="w-full max-w-sm rounded-user-lg bg-white p-6 text-center shadow-user">
                        <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-brand-red-tint text-brand-red">
                            @svg('heroicon-o-exclamation-triangle', 'h-6 w-6')
                        </span>

                        <template x-if="modal === 'leave'">
                            <div>
                                @if ($requiresCertificate)
                                    {{--
                                        Bài thuộc khóa có cấp chứng nhận: không cho rời trang khi bài còn dở.
                                        Lối ra duy nhất là nộp bài (câu chưa làm tính 0 điểm) — tránh việc
                                        rời trang tra cứu đáp án rồi quay lại làm tiếp.
                                    --}}
                                    <h3 class="mt-3 text-lg font-bold">Không thể rời trang khi đang làm bài có chứng chỉ</h3>
                                    <p class="mt-2 text-sm text-brand-muted">
                                        Bài kiểm tra này dùng để xét cấp chứng nhận. Bạn cần nộp bài trước khi rời trang —
                                        các câu chưa trả lời sẽ được tính 0 điểm.
                                    </p>
                                    <div class="mt-5 grid gap-2.5">
                                        <button type="button" class="user-btn" x-on:click="stay()">Tiếp tục làm bài</button>
                                        <button type="button" class="user-btn-secondary" x-on:click="submitAndLeave()" :disabled="busy">
                                            <span x-text="busy ? 'Đang nộp bài…' : 'Nộp bài và rời trang'"></span>
                                        </button>
                                    </div>
                                @else
                                    <h3 class="mt-3 text-lg font-bold">Bạn muốn thoát khỏi bài kiểm tra?</h3>
                                    <p class="mt-2 text-sm text-brand-muted">
                                        Câu trả lời đã được lưu tự động, bạn có thể quay lại làm tiếp.
                                        @if ($attempt->expires_at) Lưu ý: đồng hồ vẫn tiếp tục chạy khi bạn rời trang. @endif
                                    </p>
                                    <div class="mt-5 grid grid-cols-2 gap-2.5">
                                        <button type="button" class="user-btn-secondary" x-on:click="go()">Thoát</button>
                                        <button type="button" class="user-btn" x-on:click="stay()">Ở lại làm bài</button>
                                    </div>
                                @endif
                            </div>
                        </template>

                        <template x-if="modal === 'returned'">
                            <div>
                                <h3 class="mt-3 text-lg font-bold">Bạn vừa rời khỏi bài kiểm tra</h3>
                                <p class="mt-2 text-sm text-brand-muted">
                                    @if ($requiresCertificate)
                                        Đây là bài kiểm tra có cấp chứng nhận. Mỗi lần chuyển sang tab hoặc ứng dụng khác
                                        đều được ghi nhận vào lượt thi.
                                    @else
                                        Việc chuyển sang tab khác đã được ghi nhận. Hãy tập trung hoàn thành bài kiểm tra.
                                    @endif
                                </p>
                                <button type="button" class="user-btn mt-5 w-full" x-on:click="stay()">Tiếp tục làm bài</button>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        {{-- Xác nhận nộp bài --}}
        @if ($showConfirmSubmit)
            @php $unanswered = $total - $answeredCount; @endphp

            <div class="fixed inset-0 z-50 grid place-items-center bg-brand-black/50 p-4"
                 x-on:keydown.escape.window="$wire.showConfirmSubmit = false">
                <div class="w-full max-w-sm rounded-user-lg bg-white p-6 text-center shadow-user">
                    <h3 class="text-lg font-bold">Nộp bài kiểm tra?</h3>

                    @if ($unanswered > 0)
                        <p class="mt-2 text-sm font-semibold text-state-warning">
                            Còn {{ $unanswered }} câu chưa trả lời — các câu này sẽ được tính 0 điểm.
                        </p>
                    @else
                        <p class="mt-2 text-sm text-brand-muted">Bạn đã trả lời tất cả các câu hỏi.</p>
                    @endif

                    <div class="mt-5 grid grid-cols-2 gap-2.5">
                        <button type="button" wire:click="$set('showConfirmSubmit', false)" class="user-btn-secondary">
                            Quay lại
                        </button>
                        <button type="button" wire:click="submit" class="user-btn">Nộp bài</button>
                    </div>
                </div>
            </div>
        @endif

    @else
        {{-- Kết quả sau khi nộp --}}
        <style>
            .qr-item { border: 1px solid #e4e7ee; border-radius: 12px; padding: 1rem; background: #fff; }
            .qr-item.is-correct { border-left: 4px solid #15803d; }
            .qr-item.is-wrong { border-left: 4px solid #c00016; }
            .qr-opt { display: flex; align-items: flex-start; gap: .5rem; border-radius: 8px; padding: .5rem .65rem; font-size: .85rem; line-height: 1.45; }
            .qr-opt.is-correct { background: #ecfdf3; color: #166534; font-weight: 600; }
            .qr-opt.is-wrong { background: #fdf2f3; color: #c00016; }
            .qr-badge { display: inline-flex; align-items: center; gap: .25rem; border-radius: 999px; padding: .1rem .5rem; font-size: .68rem; font-weight: 700; white-space: nowrap; }
            .qr-badge.is-correct { background: #15803d; color: #fff; }
            .qr-badge.is-wrong { background: #c00016; color: #fff; }
            .qr-badge.is-muted { background: #f0f2f5; color: #6b7280; }
        </style>

        <div class="mx-auto max-w-3xl" x-data="{ reviewOpen: false }">
            <div class="user-card p-6 text-center sm:p-8">
                <span @class([
                    'mx-auto grid h-16 w-16 place-items-center rounded-full',
                    'bg-state-success-tint text-state-success' => $attempt->is_passed,
                    'bg-brand-red-tint text-brand-red' => ! $attempt->is_passed,
                ])>
                    @svg($attempt->is_passed ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle', 'h-9 w-9')
                </span>

                <h1 class="mt-4 text-xl font-bold">
                    {{ $attempt->is_passed ? 'Chúc mừng, bạn đã đạt!' : 'Chưa đạt điểm yêu cầu' }}
                </h1>
                <p class="mt-1 text-sm text-brand-muted">{{ $quiz->title }} · Lần {{ $attempt->attempt_no }}</p>

                <p class="mt-6 text-5xl font-bold {{ $attempt->is_passed ? 'text-state-success' : 'text-brand-red' }}">
                    {{ (int) $attempt->percentage }}%
                </p>
                <p class="mt-1 text-sm text-brand-muted">
                    {{ rtrim(rtrim((string) $attempt->score, '0'), '.') }}/{{ rtrim(rtrim((string) $attempt->max_score, '0'), '.') }} điểm
                    · Cần {{ $quiz->pass_score }}% để đạt
                </p>

                @if ($review !== null)
                    @php $correctCount = collect($review)->where('is_correct', true)->count(); @endphp
                    <p class="mt-3 text-sm font-semibold">
                        Đúng {{ $correctCount }}/{{ count($review) }} câu
                    </p>
                @endif

                <div class="mt-6 grid gap-2.5 sm:grid-cols-2">
                    <a wire:navigate href="{{ route('learn.course', $course) }}" class="user-btn-secondary">
                        Quay lại khóa học
                    </a>

                    @if ($review !== null)
                        <button type="button" class="user-btn" x-on:click="reviewOpen = ! reviewOpen">
                            <span x-text="reviewOpen ? 'Ẩn bài làm' : 'Xem lại bài làm'">Xem lại bài làm</span>
                        </button>
                    @elseif (! $attempt->is_passed && $canAttempt)
                        <button type="button" wire:click="retry" class="user-btn">Làm lại</button>
                    @endif
                </div>

                @if ($review !== null && ! $attempt->is_passed && $canAttempt)
                    <button type="button" wire:click="retry" class="mt-3 text-sm font-semibold text-brand-red hover:underline">
                        Làm lại bài kiểm tra
                    </button>
                @endif

                @if ($review === null)
                    <p class="mt-5 text-xs text-brand-muted">
                        Đáp án của bài kiểm tra này không được công bố sau khi nộp.
                    </p>
                @endif
            </div>

            {{-- Xem lại bài làm: chỉ có dữ liệu khi quản trị bật "Cho nhân viên xem lại đáp án" --}}
            @if ($review !== null)
                <section class="mt-4 grid gap-3" x-show="reviewOpen" x-cloak>
                    <h2 class="text-base font-bold">Xem lại bài làm</h2>

                    @foreach ($review as $item)
                        <article class="qr-item {{ $item['is_correct'] ? 'is-correct' : 'is-wrong' }}">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-bold leading-relaxed">Câu {{ $item['number'] }}. {{ $item['question'] }}</p>
                                @if ($item['is_correct'])
                                    <span class="qr-badge is-correct">@svg('heroicon-o-check', 'h-3 w-3') Đúng</span>
                                @elseif (! $item['answered'])
                                    <span class="qr-badge is-muted">Chưa trả lời</span>
                                @else
                                    <span class="qr-badge is-wrong">@svg('heroicon-o-x-mark', 'h-3 w-3') Sai</span>
                                @endif
                            </div>

                            <ul class="mt-3 grid gap-1.5">
                                @foreach ($item['options'] as $option)
                                    <li class="qr-opt {{ $option['correct'] ? 'is-correct' : ($option['picked'] ? 'is-wrong' : '') }}">
                                        <span class="mt-0.5 shrink-0">
                                            @if ($option['correct'])
                                                @svg('heroicon-o-check-circle', 'h-4 w-4')
                                            @elseif ($option['picked'])
                                                @svg('heroicon-o-x-circle', 'h-4 w-4')
                                            @else
                                                <span class="block h-4 w-4"></span>
                                            @endif
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            {{ $option['content'] }}
                                            @if ($option['picked'])
                                                <span class="ml-1 text-[11px] font-semibold" style="opacity: .8">(bạn chọn)</span>
                                            @endif
                                            @if ($option['correct'])
                                                <span class="ml-1 text-[11px] font-semibold" style="opacity: .8">(đáp án đúng)</span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            @if ($item['explanation'])
                                <p class="mt-3 border-t border-brand-line pt-3 text-xs leading-relaxed text-brand-muted">
                                    <span class="font-semibold text-brand-ink">Giải thích:</span> {{ $item['explanation'] }}
                                </p>
                            @endif
                        </article>
                    @endforeach
                </section>
            @endif
        </div>
    @endif
</div>
