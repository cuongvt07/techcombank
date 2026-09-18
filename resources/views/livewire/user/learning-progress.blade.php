<div>
    {{-- Nhắc việc lên đầu: đây là lý do chính nhân viên mở màn hình này (spec 4.2) --}}
    @if ($dueSoon->isNotEmpty())
        <section class="mb-5">
            <h2 class="mb-2.5 text-sm font-bold">Cần hoàn thành</h2>
            <div class="grid gap-2.5">
                @foreach ($dueSoon as $item)
                    @php $days = $item->daysUntilDue(); @endphp

                    <a wire:navigate href="{{ route('learn.course', $item->course) }}"
                       @class([
                           'flex items-center gap-3 rounded-user-md border p-3.5 transition-shadow hover:shadow-user-sm',
                           'border-brand-red bg-brand-red-tint' => $days !== null && $days < 0,
                           'border-state-warning bg-state-warning-tint' => $days !== null && $days >= 0 && $days <= 7,
                           'border-brand-line bg-white' => $days === null || $days > 7,
                       ])>
                        <span @class([
                            'grid h-10 w-10 shrink-0 place-items-center rounded-user-md',
                            'bg-brand-red text-white' => $days !== null && $days < 0,
                            'bg-state-warning text-white' => $days !== null && $days >= 0 && $days <= 7,
                            'bg-brand-soft text-brand-muted' => $days === null || $days > 7,
                        ])>
                            @svg('heroicon-o-clock', 'h-5 w-5')
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-bold">{{ $item->course->title }}</p>
                            <p class="mt-0.5 text-xs text-brand-muted">
                                {{ (int) $item->progress_percent }}% hoàn thành ·
                                @if ($days !== null && $days < 0)
                                    <span class="font-bold text-brand-red">Quá hạn {{ abs($days) }} ngày</span>
                                @elseif ($days !== null)
                                    Còn {{ $days }} ngày
                                @endif
                            </p>
                        </div>

                        @svg('heroicon-o-chevron-right', 'h-5 w-5 shrink-0 text-brand-muted')
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="user-card p-4">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Tổng khóa học</span>
            <strong class="mt-1 block text-2xl font-bold leading-none">{{ $summary['total'] }}</strong>
        </div>
        <div class="user-card p-4">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Đã hoàn thành</span>
            <strong class="mt-1 block text-2xl font-bold leading-none text-state-success">{{ $summary['completed'] }}</strong>
        </div>
        <div class="user-card p-4">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Đang học</span>
            {{-- Khóa còn dở tô vàng: nhắc người học còn việc chưa xong --}}
            <strong class="mt-1 block text-2xl font-bold leading-none {{ $summary['in_progress'] > 0 ? 'text-state-warning' : '' }}">
                {{ $summary['in_progress'] }}
            </strong>
        </div>
        <div class="user-card p-4">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Tiến độ trung bình</span>
            <strong class="mt-1 block text-2xl font-bold leading-none text-brand-red">{{ (int) $summary['avg'] }}%</strong>
        </div>
    </section>

    <section class="mb-5">
        <h2 class="mb-2.5 text-sm font-bold">Tất cả khóa học</h2>

        <div class="user-card overflow-hidden">
            @forelse ($enrollments as $item)
                <a wire:navigate href="{{ route('learn.course', $item->course) }}"
                   class="flex items-center gap-3 border-b border-brand-line p-4 transition-colors last:border-b-0 hover:bg-brand-soft">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-sm font-bold">{{ $item->course->title }}</p>
                            @if ($item->is_mandatory)
                                <span class="tag tag-red">Bắt buộc</span>
                            @endif
                        </div>

                        <div class="mt-2 flex items-center gap-2.5">
                            <div class="progress-track flex-1">
                                <div class="progress-fill {{ $item->isCompleted() ? '!bg-state-success' : '' }}"
                                     style="width: {{ (float) $item->progress_percent }}%"></div>
                            </div>
                            <span class="shrink-0 text-xs font-bold {{ $item->isCompleted() ? 'text-state-success' : 'text-brand-red' }}">
                                {{ (int) $item->progress_percent }}%
                            </span>
                        </div>

                        <p class="mt-1 text-xs text-brand-muted">
                            {{ $item->completed_lessons }}/{{ $item->total_lessons }} bài
                            @if ($item->completed_at)
                                · Hoàn thành {{ $item->completed_at->format('d/m/Y') }}
                            @elseif ($item->due_date)
                                · Hạn {{ $item->due_date->format('d/m/Y') }}
                            @endif
                        </p>
                    </div>

                    @svg('heroicon-o-chevron-right', 'h-5 w-5 shrink-0 text-brand-muted')
                </a>
            @empty
                <p class="p-8 text-center text-sm text-brand-muted">Bạn chưa được giao khóa học nào.</p>
            @endforelse
        </div>
    </section>

    @if ($quizAttempts->isNotEmpty())
        <section class="mb-5">
            <h2 class="mb-2.5 text-sm font-bold">Kết quả bài kiểm tra</h2>

            <div class="user-card overflow-hidden">
                @foreach ($quizAttempts as $attempt)
                    <div class="flex items-center justify-between gap-3 border-b border-brand-line p-4 last:border-b-0">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold">{{ $attempt->quiz?->title ?? '—' }}</p>
                            <p class="mt-0.5 text-xs text-brand-muted">
                                Lần {{ $attempt->attempt_no }} ·
                                {{ $attempt->submitted_at?->format('d/m/Y H:i') ?? '—' }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <span class="text-sm font-bold">{{ (int) $attempt->percentage }}%</span>
                            <span class="tag {{ $attempt->is_passed ? 'tag-green' : 'tag-red' }}">
                                {{ $attempt->is_passed ? 'Đạt' : 'Chưa đạt' }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($certificates->isNotEmpty())
        <section>
            <h2 class="mb-2.5 text-sm font-bold">Chứng nhận hoàn thành</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($certificates as $certificate)
                    <div class="user-card p-4">
                        <div class="flex items-start gap-3">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-user-md bg-state-success-tint text-state-success">
                                @svg('heroicon-o-trophy', 'h-5 w-5')
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold">{{ $certificate->course?->title }}</p>
                                <p class="mt-0.5 font-mono text-[11px] text-brand-muted">{{ $certificate->certificate_no }}</p>
                                <p class="mt-1 text-xs text-brand-muted">
                                    Cấp ngày {{ $certificate->issued_at?->format('d/m/Y') }}
                                    @if ($certificate->score)
                                        · {{ (int) $certificate->score }} điểm
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
