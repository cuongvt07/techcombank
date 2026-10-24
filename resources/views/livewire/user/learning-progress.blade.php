<div class="grid gap-6">
    <section>
        <div class="mb-3">
            <h1 class="text-lg font-bold">Kết quả bài kiểm tra</h1>
            <p class="mt-1 text-sm text-brand-muted">Bấm vào kết quả để mở khóa học có bài kiểm tra đó.</p>
        </div>

        <div class="user-card overflow-hidden">
            @forelse ($quizAttempts as $attempt)
                @php $course = $attempt->quiz?->course; @endphp
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
                                · Lần {{ $attempt->attempt_no }}
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
            <p class="mt-1 text-sm text-brand-muted">Mở chứng nhận để xem mã và thông tin xác nhận.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @forelse ($certificates as $certificate)
                <button type="button" wire:click="showCertificate({{ $certificate->id }})"
                        class="user-card flex w-full items-start gap-3 p-4 text-left transition hover:border-brand-red">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-user-md bg-state-success-tint text-state-success">
                        @svg('heroicon-o-trophy', 'h-5 w-5')
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-bold">{{ $certificate->course?->title }}</span>
                        <span class="mt-0.5 block font-mono text-[11px] text-brand-muted">{{ $certificate->certificate_no }}</span>
                        <span class="mt-1 block text-xs text-brand-red">Xem bằng chứng chứng nhận</span>
                    </span>
                    @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-brand-muted')
                </button>
            @empty
                <p class="user-card p-5 text-sm text-brand-muted">Bạn chưa có chứng nhận hoàn thành.</p>
            @endforelse
        </div>
    </section>

    @if ($viewingCertificate)
        <div class="fixed inset-0 z-[100] grid place-items-center bg-brand-black/60 p-4" role="dialog" aria-modal="true" aria-labelledby="certificate-title">
            <div class="user-card relative w-full max-w-lg overflow-hidden">
                <div class="border-b border-brand-line bg-state-success-tint p-5 text-center">
                    <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-white text-state-success shadow-user-sm">
                        @svg('heroicon-o-trophy', 'h-7 w-7')
                    </span>
                    <h2 id="certificate-title" class="mt-3 text-lg font-bold">Chứng nhận hoàn thành</h2>
                    <p class="mt-1 text-sm text-brand-muted">{{ $viewingCertificate->course?->title }}</p>
                </div>

                <dl class="grid gap-3 p-5 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-brand-muted">Học viên</dt>
                        <dd class="text-right font-semibold">{{ $viewingCertificate->employee?->full_name }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-brand-muted">Mã chứng nhận</dt>
                        <dd class="text-right font-mono font-bold">{{ $viewingCertificate->certificate_no }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-brand-muted">Mã xác thực</dt>
                        <dd class="break-all text-right font-mono">{{ $viewingCertificate->verification_code }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-brand-muted">Ngày cấp</dt>
                        <dd class="text-right font-semibold">{{ $viewingCertificate->issued_at?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                    @if ($viewingCertificate->score !== null)
                        <div class="flex justify-between gap-4">
                            <dt class="text-brand-muted">Điểm</dt>
                            <dd class="text-right font-semibold">{{ (int) $viewingCertificate->score }}</dd>
                        </div>
                    @endif
                    @if (! $viewingCertificate->isValid())
                        <div class="rounded-user-md border border-state-warning bg-state-warning-tint p-3 text-xs font-semibold text-state-warning">
                            Chứng nhận này đã hết hạn {{ $viewingCertificate->valid_until?->format('d/m/Y') }}.
                        </div>
                    @endif
                </dl>

                <div class="flex justify-end border-t border-brand-line p-4">
                    <button type="button" wire:click="closeCertificate" class="user-btn">Đóng</button>
                </div>
            </div>
        </div>
    @endif
</div>
