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
        {{-- Đang làm bài --}}
        @php
            $current = $questions->get($currentIndex);
            $total = $questions->count();
        @endphp

        <div class="mx-auto max-w-3xl">
            {{-- Thanh trạng thái: số câu đã trả lời + đồng hồ đếm ngược --}}
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-user-md border border-brand-line bg-white p-4 shadow-user-sm">
                <div>
                    <p class="text-xs font-semibold uppercase text-brand-muted">Câu {{ $currentIndex + 1 }}/{{ $total }}</p>
                    <p class="mt-0.5 text-sm font-bold">
                        Đã trả lời {{ collect($answers)->filter()->count() }}/{{ $total }} câu
                    </p>
                </div>

                @if ($attempt->expires_at)
                    {{-- Đếm ngược ở client cho mượt; server vẫn kiểm tra lại mốc hết giờ --}}
                    <div x-data="{
                            remaining: {{ $attempt->remainingSeconds() ?? 0 }},
                            init() {
                                const timer = setInterval(() => {
                                    this.remaining--;
                                    if (this.remaining <= 0) {
                                        clearInterval(timer);
                                        $wire.timeUp();
                                    }
                                }, 1000);
                            },
                            get label() {
                                const m = Math.floor(Math.max(0, this.remaining) / 60);
                                const s = Math.max(0, this.remaining) % 60;
                                return `${m}:${String(s).padStart(2, '0')}`;
                            }
                         }"
                         class="flex items-center gap-2 rounded-user-md px-3 py-2"
                         :class="remaining < 60 ? 'bg-brand-red-tint text-brand-red' : 'bg-brand-soft text-brand-ink'">
                        @svg('heroicon-o-clock', 'h-5 w-5')
                        <span class="font-mono text-lg font-bold" x-text="label">—</span>
                    </div>
                @endif
            </div>

            {{-- Lưới số câu để nhảy nhanh --}}
            <div class="mb-4 flex flex-wrap gap-1.5">
                @foreach ($questions as $index => $answer)
                    @php $answered = ! empty($answers[$answer->question_id] ?? []); @endphp
                    <button type="button" wire:click="goToQuestion({{ $index }})"
                            @class([
                                'h-9 w-9 rounded-user-md text-xs font-bold transition-colors',
                                'bg-brand-red text-white' => $index === $currentIndex,
                                'bg-state-success-tint text-state-success' => $index !== $currentIndex && $answered,
                                'bg-brand-soft text-brand-muted hover:bg-brand-line' => $index !== $currentIndex && ! $answered,
                            ])>
                        {{ $index + 1 }}
                    </button>
                @endforeach
            </div>

            @if ($current && $current->question)
                @php
                    $question = $current->question;
                    $isMultiple = $question->isMultiple();
                    $selected = $answers[$question->id] ?? [];
                @endphp

                <div class="user-card p-5 sm:p-6" wire:key="q-{{ $question->id }}">
                    <div class="mb-4 flex items-start gap-3">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-red text-sm font-bold text-white">
                            {{ $currentIndex + 1 }}
                        </span>
                        <div class="min-w-0">
                            <p class="font-bold leading-relaxed">{{ $question->content }}</p>
                            @if ($isMultiple)
                                <p class="mt-1 text-xs font-semibold text-brand-muted">
                                    Chọn tất cả đáp án đúng — phải chọn đủ mới được tính điểm.
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="grid gap-2.5">
                        @foreach ($question->options as $option)
                            @php $isSelected = in_array($option->id, $selected, true); @endphp

                            <button type="button"
                                    wire:click="selectOption({{ $question->id }}, {{ $option->id }}, {{ $isMultiple ? 'true' : 'false' }})"
                                    @class([
                                        'flex w-full items-start gap-3 rounded-user-md border p-3.5 text-left transition-colors',
                                        'border-brand-red bg-brand-red-tint' => $isSelected,
                                        'border-brand-line hover:border-brand-red hover:bg-brand-soft' => ! $isSelected,
                                    ])>
                                <span @class([
                                    'mt-0.5 grid h-5 w-5 shrink-0 place-items-center border-2 text-white',
                                    'rounded-user-md' => $isMultiple,
                                    'rounded-full' => ! $isMultiple,
                                    'border-brand-red bg-brand-red' => $isSelected,
                                    'border-brand-line' => ! $isSelected,
                                ])>
                                    @if ($isSelected)
                                        @svg('heroicon-o-check', 'h-3 w-3 stroke-[4]')
                                    @endif
                                </span>
                                <span class="text-sm leading-relaxed">{{ $option->content }}</span>
                            </button>
                        @endforeach
                    </div>

                    <div class="mt-5 flex items-center justify-between gap-3 border-t border-brand-line pt-4">
                        <button type="button" wire:click="previousQuestion"
                                class="user-btn-secondary !min-h-[42px] !px-4 !text-xs"
                                @disabled($currentIndex === 0)>
                            @svg('heroicon-o-chevron-left', 'h-4 w-4')
                            Câu trước
                        </button>

                        @if ($currentIndex < $total - 1)
                            <button type="button" wire:click="nextQuestion" class="user-btn !min-h-[42px] !px-4 !text-xs">
                                Câu sau
                                @svg('heroicon-o-chevron-right', 'h-4 w-4')
                            </button>
                        @else
                            <button type="button" wire:click="confirmSubmit" class="user-btn !min-h-[42px] !px-5 !text-xs">
                                Nộp bài
                            </button>
                        @endif
                    </div>
                </div>

                @if ($currentIndex < $total - 1)
                    <button type="button" wire:click="confirmSubmit"
                            class="mt-4 w-full text-xs font-semibold text-brand-muted hover:text-brand-red">
                        Nộp bài ngay
                    </button>
                @endif
            @endif
        </div>

        {{-- Xác nhận nộp bài --}}
        @if ($showConfirmSubmit)
            @php $unanswered = $questions->count() - collect($answers)->filter()->count(); @endphp

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
        <div class="mx-auto max-w-2xl">
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

                <p class="mt-6 text-5xl font-bold {{ $attempt->is_passed ? 'text-state-success' : 'text-brand-red' }}">
                    {{ (int) $attempt->percentage }}%
                </p>
                <p class="mt-1 text-sm text-brand-muted">
                    {{ rtrim(rtrim((string) $attempt->score, '0'), '.') }}/{{ rtrim(rtrim((string) $attempt->max_score, '0'), '.') }} điểm
                    · Cần {{ $quiz->pass_score }}% để đạt
                </p>

                @if ($quiz->show_correct_answers)
                    <div class="mt-6 grid gap-2.5 text-left">
                        <h2 class="text-sm font-bold">Chi tiết bài làm</h2>
                        @foreach ($questions as $index => $answer)
                            <div @class([
                                'rounded-user-md border p-3.5',
                                'border-state-success bg-state-success-tint' => $answer->is_correct,
                                'border-brand-red bg-brand-red-tint' => ! $answer->is_correct,
                            ])>
                                <p class="text-sm font-bold">{{ $index + 1 }}. {{ $answer->question_snapshot }}</p>

                                @if ($answer->question)
                                    <ul class="mt-2 grid gap-1 text-xs">
                                        @foreach ($answer->question->options as $option)
                                            @php
                                                $picked = in_array($option->id, $answer->selected_option_ids ?? [], true);
                                            @endphp
                                            <li @class([
                                                'flex items-center gap-1.5',
                                                'font-bold text-state-success' => $option->is_correct,
                                                'text-brand-red line-through' => $picked && ! $option->is_correct,
                                                'text-brand-muted' => ! $option->is_correct && ! $picked,
                                            ])>
                                                @if ($option->is_correct)
                                                    @svg('heroicon-o-check', 'h-3.5 w-3.5 shrink-0')
                                                @elseif ($picked)
                                                    @svg('heroicon-o-x-mark', 'h-3.5 w-3.5 shrink-0')
                                                @else
                                                    <span class="w-3.5 shrink-0"></span>
                                                @endif
                                                {{ $option->content }}
                                            </li>
                                        @endforeach
                                    </ul>

                                    @if ($answer->question->explanation)
                                        <p class="mt-2 border-t border-white/50 pt-2 text-xs text-brand-muted">
                                            {{ $answer->question->explanation }}
                                        </p>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="mt-6 grid gap-2.5 sm:grid-cols-2">
                    <a wire:navigate href="{{ route('learn.course', $course) }}" class="user-btn-secondary">
                        Quay lại khóa học
                    </a>

                    @if (! $attempt->is_passed && $canAttempt)
                        <button type="button" wire:click="retry" class="user-btn">Làm lại</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
