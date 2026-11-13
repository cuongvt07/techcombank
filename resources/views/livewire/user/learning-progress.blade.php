<div class="grid gap-6">
    <section>
        <div class="mb-3">
            <h1 class="text-lg font-bold">Kết quả bài kiểm tra</h1>
            <p class="mt-1 text-sm text-brand-muted">Mỗi bài hiển thị lượt có điểm cao nhất. Bấm vào để mở khóa học có bài kiểm tra đó.</p>
        </div>

        <div class="user-card overflow-hidden">
            @forelse ($quizResults as $result)
                @php
                    $attempt = $result['attempt'];
                    $course = $attempt->quiz?->course;
                @endphp
                @if ($course)
                    <a wire:navigate href="{{ route('learn.course', $course) }}"
                       class="flex items-center justify-between gap-3 border-b border-brand-line p-4 transition-colors last:border-b-0 hover:bg-brand-soft">
                @else
                    <div class="flex items-center justify-between gap-3 border-b border-brand-line p-4 last:border-b-0">
                @endif
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold">{{ $attempt->quiz?->title ?? '—' }}</p>
                            <p class="mt-0.5 text-xs text-brand-muted">
                                {{ $course?->title ?? 'Không còn khóa học liên kết' }}
                                · Lần {{ $attempt->attempt_no }} · {{ $result['attempts_count'] }} lượt đã làm
                                · {{ $attempt->submitted_at?->format('d/m/Y H:i') ?? '—' }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <span class="text-sm font-bold">{{ (int) $attempt->percentage }}%</span>
                            <span class="tag {{ $attempt->is_passed ? 'tag-green' : 'tag-red' }}">
                                {{ $attempt->is_passed ? 'Đạt' : 'Chưa đạt' }}
                            </span>
                            @if ($course)
                                @svg('heroicon-o-chevron-right', 'h-4 w-4 text-brand-muted')
                            @endif
                        </div>
                @if ($course)
                    </a>
                @else
                    </div>
                @endif
            @empty
                <p class="p-5 text-sm text-brand-muted">Bạn chưa có kết quả bài kiểm tra.</p>
            @endforelse
        </div>
    </section>

    <section>
        <div class="mb-3">
            <h2 class="text-lg font-bold">Chứng nhận hoàn thành</h2>
            <p class="mt-1 text-sm text-brand-muted">Mở chứng nhận để xem, in hoặc lưu PDF.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @forelse ($certificates as $certificate)
                {{-- Trang chứng nhận riêng (in / lưu PDF), không dùng wire:navigate vì trang không có layout Livewire --}}
                <a href="{{ route('learn.certificate', $certificate) }}"
                   class="user-card flex w-full items-start gap-3 p-4 text-left transition hover:border-brand-red">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-user-md bg-state-success-tint text-state-success">
                        @svg('heroicon-o-trophy', 'h-5 w-5')
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-bold">{{ $certificate->course?->title }}</span>
                        <span class="mt-0.5 block font-mono text-[11px] text-brand-muted">{{ $certificate->certificate_no }}</span>
                        <span class="mt-1 block text-xs text-brand-red">Xem chứng nhận · In / Lưu PDF</span>
                    </span>
                    @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-brand-muted')
                </a>
            @empty
                <p class="user-card p-5 text-sm text-brand-muted">Bạn chưa có chứng nhận hoàn thành.</p>
            @endforelse
        </div>
    </section>

</div>
