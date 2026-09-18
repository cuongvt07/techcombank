<div>
    @if (session('error'))
        <div class="mb-4 rounded-user-md border border-brand-red bg-brand-red-tint px-4 py-3 text-sm font-semibold text-brand-red">
            {{ session('error') }}
        </div>
    @endif

    {{-- Thanh tiến độ khóa, sticky trên mobile để luôn thấy mình đang ở đâu --}}
    <div class="mb-4 rounded-user-md border border-brand-line bg-white p-4 shadow-user-sm">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <h2 class="truncate text-base font-bold sm:text-lg">{{ $course->title }}</h2>
                <p class="mt-0.5 text-xs text-brand-muted">
                    {{ $enrollment->completed_lessons }}/{{ $enrollment->total_lessons }} bài bắt buộc
                    @php $remainingLessons = $enrollment->total_lessons - $enrollment->completed_lessons; @endphp
                    @if ($remainingLessons > 0)
                        · <span class="font-semibold text-state-warning">còn {{ $remainingLessons }} bài</span>
                    @endif
                    @if ($enrollment->due_date)
                        · Hạn {{ $enrollment->due_date->format('d/m/Y') }}
                    @endif
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($enrollment->isCompleted())
                    <span class="tag tag-green">Đã hoàn thành</span>
                @elseif ($enrollment->status === \App\Models\Enrollment::STATUS_OVERDUE)
                    <span class="tag tag-red">Quá hạn</span>
                @endif
                <span class="text-lg font-bold text-brand-red">{{ (int) $enrollment->progress_percent }}%</span>
            </div>
        </div>

        <div class="progress-track">
            <div class="progress-fill {{ $enrollment->isCompleted() ? '!bg-state-success' : '' }}"
                 style="width: {{ (float) $enrollment->progress_percent }}%"></div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-[300px_minmax(0,1fr)] lg:items-start">
        {{-- Mục lục bài học --}}
        <aside class="rounded-user-md border border-brand-line bg-white shadow-user-sm lg:sticky lg:top-20">
            <div class="border-b border-brand-line px-4 py-3">
                <h3 class="text-sm font-bold">Nội dung khóa học</h3>
                <p class="mt-0.5 text-xs text-brand-muted">
                    {{ $lessons->count() }} bài ·
                    {{ $course->sequential ? 'Học tuần tự' : 'Học tự do' }}
                </p>
            </div>

            <ol class="max-h-[60vh] overflow-y-auto">
                @foreach ($lessons as $index => $item)
                    @php
                        $itemProgress = $progressMap[$item->id] ?? null;
                        $isDone = $itemProgress?->status === \App\Models\LessonProgress::STATUS_COMPLETED;
                        $isCurrent = $currentLesson && $item->id === $currentLesson->id;
                        $isUnlocked = $unlockedMap[$item->id] ?? false;
                    @endphp

                    <li wire:key="toc-{{ $item->id }}">
                        <button type="button"
                                wire:click="openLesson({{ $item->id }})"
                                @disabled(! $isUnlocked)
                                @class([
                                    'flex w-full items-start gap-3 border-b border-brand-line px-4 py-3 text-left transition-colors last:border-b-0',
                                    'bg-brand-red-tint' => $isCurrent,
                                    'hover:bg-brand-soft' => $isUnlocked && ! $isCurrent,
                                    'cursor-not-allowed opacity-50' => ! $isUnlocked,
                                ])>
                            {{-- Trạng thái bài: xong / đang học / bị khóa --}}
                            <span @class([
                                'mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-[11px] font-bold',
                                'bg-state-success text-white' => $isDone,
                                'bg-brand-red text-white' => $isCurrent && ! $isDone,
                                'bg-brand-soft text-brand-muted' => ! $isDone && ! $isCurrent,
                            ])>
                                @if ($isDone)
                                    @svg('heroicon-o-check', 'h-3.5 w-3.5 stroke-[3]')
                                @elseif (! $isUnlocked)
                                    @svg('heroicon-o-lock-closed', 'h-3 w-3')
                                @else
                                    {{ $index + 1 }}
                                @endif
                            </span>

                            <span class="min-w-0 flex-1">
                                <span @class([
                                    'block text-[13px] font-bold leading-snug',
                                    'text-brand-red' => $isCurrent,
                                ])>
                                    {{ $item->title }}
                                </span>

                                <span class="mt-1 flex flex-wrap items-center gap-1.5">
                                    @php
                                        $typeMap = [
                                            'text' => ['heroicon-o-document-text', 'Bài đọc'],
                                            'document' => ['heroicon-o-paper-clip', 'Tài liệu'],
                                            'video' => ['heroicon-o-play-circle', 'Video'],
                                            'quiz' => ['heroicon-o-clipboard-document-check', 'Kiểm tra'],
                                        ];
                                        [$icon, $typeLabel] = $typeMap[$item->content_type] ?? ['heroicon-o-document', ''];
                                    @endphp

                                    <span class="inline-flex items-center gap-1 text-[11px] text-brand-muted">
                                        @svg($icon, 'h-3.5 w-3.5')
                                        {{ $typeLabel }}
                                    </span>

                                    @if ($item->estimated_minutes)
                                        <span class="text-[11px] text-brand-muted">· {{ $item->estimated_minutes }}′</span>
                                    @endif

                                    @unless ($item->is_required)
                                        <span class="text-[11px] text-brand-muted">· Tự chọn</span>
                                    @endunless
                                </span>

                                @if ($item->isVideo() && $itemProgress && ! $isDone && $itemProgress->watch_percent > 0)
                                    <span class="mt-1.5 block">
                                        <span class="progress-track !h-1">
                                            <span class="progress-fill block" style="width: {{ (float) $itemProgress->watch_percent }}%"></span>
                                        </span>
                                    </span>
                                @endif
                            </span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </aside>

        {{-- Nội dung bài đang học --}}
        <div class="min-w-0">
            @if (! $currentLesson)
                <div class="user-card p-10 text-center">
                    <p class="font-bold">Khóa học chưa có bài học nào.</p>
                </div>
            @else
                {{--
                    Ghi nhận thời gian người học ở lại bài.
                    Gửi mỗi 60 giây và khi rời trang; dừng đếm khi tab bị ẩn để
                    tab quên đóng không tính thành giờ học.
                --}}
                <article class="user-card overflow-hidden"
                         wire:key="lesson-{{ $currentLesson->id }}"
                         x-data="{
                             seconds: 0,
                             timer: null,
                             start() {
                                 this.stop();
                                 this.timer = setInterval(() => {
                                     if (document.hidden) return;
                                     this.seconds++;
                                     if (this.seconds >= 60) this.flush();
                                 }, 1000);
                             },
                             stop() {
                                 if (this.timer) clearInterval(this.timer);
                                 this.timer = null;
                             },
                             flush() {
                                 if (this.seconds <= 0) return;
                                 $wire.recordTimeSpent(this.seconds);
                                 this.seconds = 0;
                             }
                         }"
                         x-init="start()"
                         x-on:beforeunload.window="flush()"
                         x-on:visibilitychange.document="document.hidden && flush()">
                    <header class="border-b border-brand-line p-5">
                        <h1 class="text-lg font-bold sm:text-xl">{{ $currentLesson->title }}</h1>
                        @if ($currentLesson->summary)
                            <p class="mt-1.5 text-sm text-brand-muted">{{ $currentLesson->summary }}</p>
                        @endif
                    </header>

                    <div class="p-5">
                        @switch($currentLesson->content_type)
                            @case('text')
                                {{-- Nội dung do quản trị viên soạn, không phải người dùng nhập --}}
                                <div class="prose-sm max-w-none leading-relaxed [&_h2]:mb-2 [&_h2]:mt-4 [&_h2]:font-bold [&_li]:mb-1 [&_p]:mb-3 [&_ul]:mb-3 [&_ul]:list-disc [&_ul]:pl-5">
                                    {!! $currentLesson->content_html !!}
                                </div>
                                @break

                            @case('video')
                                @if ($currentLesson->document)
                                    {{-- Watermark động đè lên video để truy vết rò rỉ (spec 3.3.2) --}}
                                    <div class="relative overflow-hidden rounded-user-md bg-brand-black"
                                         x-data="{
                                             duration: {{ $currentLesson->document->duration_seconds ?? 0 }},
                                             lastSent: 0,
                                             report(el) {
                                                 if (! this.duration) return;
                                                 const pos = Math.floor(el.currentTime);
                                                 // Gửi về server mỗi 10 giây để tránh dồn request
                                                 if (pos - this.lastSent < 10) return;
                                                 this.lastSent = pos;
                                                 $wire.updateVideoProgress(pos, (pos / this.duration) * 100);
                                             }
                                         }">
                                        @php $embedUrl = $currentLesson->document->embedUrl(); @endphp

                                        @if ($embedUrl)
                                            {{-- Video nhúng: trình phát của nhà cung cấp không phát
                                                 sự kiện timeupdate cho trang cha, nên tiến độ do
                                                 người học tự xác nhận ở nút bên dưới --}}
                                            <iframe src="{{ $embedUrl }}"
                                                    class="aspect-video w-full"
                                                    allow="accelerometer; encrypted-media; picture-in-picture"
                                                    allowfullscreen
                                                    title="{{ $currentLesson->title }}"></iframe>
                                        @else
                                            {{-- Video trên hạ tầng riêng: phát trực tiếp, đo được tiến độ --}}
                                            <video class="aspect-video w-full"
                                                   controls
                                                   controlsList="nodownload"
                                                   oncontextmenu="return false"
                                                   x-on:timeupdate="report($event.target)"
                                                   x-on:ended="$wire.updateVideoProgress(duration, 100)"
                                                   src="{{ $currentLesson->document->video_url }}">
                                                Trình duyệt không hỗ trợ phát video.
                                            </video>
                                        @endif

                                        @if ($watermarkText)
                                            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                                <span class="rotate-[-20deg] select-none text-sm font-bold text-white/25">
                                                    {{ $watermarkText }}
                                                </span>
                                            </div>
                                        @endif
                                    </div>

                                    <p class="mt-3 text-xs text-brand-muted">
                                        @if ($this->isEmbeddedVideo($currentLesson))
                                            Xem hết video rồi bấm "Tôi đã xem xong" ở cuối trang để hoàn thành bài học.
                                        @else
                                            Cần xem tối thiểu {{ $currentLesson->minWatchPercent() }}% để hoàn thành bài này.
                                            @if ($currentProgress)
                                                Đã xem {{ (int) $currentProgress->watch_percent }}%.
                                            @endif
                                        @endif
                                    </p>
                                @else
                                    <p class="text-sm text-brand-muted">Bài học chưa gắn video.</p>
                                @endif
                                @break

                            @case('document')
                                @if ($currentLesson->document)
                                    @php $doc = $currentLesson->document; @endphp

                                    {{--
                                        PDF xem thẳng trong trang: trình duyệt có sẵn trình đọc nên
                                        chỉ cần nhúng iframe, không phải tải thư viện từ CDN (mạng
                                        nội bộ ngân hàng thường chặn CDN).

                                        File vẫn đi qua route stream nên mọi lớp kiểm tra quyền và
                                        ghi log truy cập giữ nguyên — không có đường vòng.
                                    --}}
                                    @if ($doc->isInlineViewable())
                                        <div class="relative mb-4 overflow-hidden rounded-user-md border border-brand-line bg-brand-soft">
                                            <iframe src="{{ route('learn.documents.stream', $doc) }}#toolbar=0&navpanes=0"
                                                    class="h-[70vh] max-h-[720px] w-full"
                                                    title="{{ $doc->title }}"></iframe>

                                            @if ($watermarkText)
                                                {{-- Watermark đè lên khung xem; pointer-events-none
                                                     để không chắn thao tác cuộn trang PDF --}}
                                                <div class="pointer-events-none absolute inset-0 flex items-center justify-center overflow-hidden">
                                                    <span class="rotate-[-20deg] select-none whitespace-nowrap text-2xl font-bold text-brand-ink/[.08]">
                                                        {{ $watermarkText }}
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="relative rounded-user-md border border-brand-line bg-brand-soft p-6">
                                        @if ($watermarkText && ! $doc->isInlineViewable())
                                            <div class="pointer-events-none absolute inset-0 flex items-center justify-center overflow-hidden">
                                                <span class="rotate-[-20deg] select-none whitespace-nowrap text-xl font-bold text-brand-ink/[.06]">
                                                    {{ $watermarkText }}
                                                </span>
                                            </div>
                                        @endif

                                        <div class="relative flex items-start gap-3">
                                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-user-md bg-white text-brand-red shadow-user-sm">
                                                @svg('heroicon-o-document-text', 'h-5 w-5')
                                            </span>
                                            <div class="min-w-0">
                                                <p class="font-bold">{{ $doc->title }}</p>
                                                @if ($doc->description)
                                                    <p class="mt-1 text-sm text-brand-muted">{{ $doc->description }}</p>
                                                @endif

                                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                                    <a href="{{ route('learn.documents.stream', $doc) }}"
                                                       target="_blank" rel="noopener"
                                                       class="{{ $doc->isInlineViewable() ? 'user-btn-secondary' : 'user-btn' }} !min-h-[38px] !px-4 !text-xs">
                                                        @svg('heroicon-o-arrow-top-right-on-square', 'h-4 w-4')
                                                        {{ $doc->isInlineViewable() ? 'Mở tab mới' : 'Mở tài liệu' }}
                                                    </a>

                                                    @if ($doc->isWord())
                                                        {{-- Trình duyệt không đọc được DOCX. Chuyển đổi
                                                             để xem trong trang phải qua dịch vụ ngoài,
                                                             tức đẩy tài liệu nội bộ ra Internet — không
                                                             chấp nhận được với tài liệu ngân hàng. --}}
                                                        <span class="user-pill inline-flex items-center gap-1.5">
                                                            @svg('heroicon-o-information-circle', 'h-3.5 w-3.5')
                                                            File Word mở bằng ứng dụng trên máy
                                                        </span>
                                                    @endif

                                                    @if ($doc->allow_download)
                                                        <a href="{{ route('learn.documents.download', $doc) }}"
                                                           class="user-btn-secondary !min-h-[38px] !px-4 !text-xs">
                                                            @svg('heroicon-o-arrow-down-tray', 'h-4 w-4')
                                                            Tải bản gốc
                                                        </a>
                                                    @else
                                                        <span class="user-pill inline-flex items-center gap-1.5">
                                                            @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5')
                                                            Chỉ xem trực tuyến
                                                        </span>
                                                    @endif

                                                    @if ($doc->enable_watermark)
                                                        <span class="user-pill">Có watermark truy vết</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <p class="text-sm text-brand-muted">Bài học chưa gắn tài liệu.</p>
                                @endif
                                @break

                            @case('quiz')
                                @if ($currentLesson->quiz)
                                    @php
                                        $quiz = $currentLesson->quiz;
                                        $isPassed = ($currentProgress?->status ?? null) === \App\Models\LessonProgress::STATUS_COMPLETED;
                                    @endphp

                                    <div class="rounded-user-md border border-brand-line p-5 text-center">
                                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-brand-red-tint text-brand-red">
                                            @svg('heroicon-o-clipboard-document-check', 'h-7 w-7')
                                        </span>

                                        <h3 class="mt-3 font-bold">{{ $quiz->title }}</h3>

                                        <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
                                            <span class="user-pill">Điểm đạt {{ $quiz->pass_score }}%</span>
                                            @if ($quiz->duration_minutes)
                                                <span class="user-pill">{{ $quiz->duration_minutes }} phút</span>
                                            @endif
                                            <span class="user-pill">
                                                {{ $quiz->max_attempts ? 'Tối đa ' . $quiz->max_attempts . ' lần' : 'Không giới hạn số lần' }}
                                            </span>
                                        </div>

                                        @if ($isPassed)
                                            <p class="mt-4 text-sm font-semibold text-state-success">Bạn đã đạt bài kiểm tra này.</p>
                                        @endif

                                        @if (Route::has('learn.quiz'))
                                            <a wire:navigate href="{{ route('learn.quiz', [$course, $currentLesson]) }}"
                                               class="{{ $isPassed ? 'user-btn-secondary' : 'user-btn' }} mt-4">
                                                {{ $isPassed ? 'Làm lại' : 'Bắt đầu làm bài' }}
                                            </a>
                                        @endif
                                    </div>
                                @else
                                    <p class="text-sm text-brand-muted">Bài học chưa gắn bài kiểm tra.</p>
                                @endif
                                @break
                        @endswitch
                    </div>

                    {{-- Điều hướng bài + nút hoàn thành --}}
                    @php
                        $done = ($currentProgress?->status ?? null) === \App\Models\LessonProgress::STATUS_COMPLETED;

                        // Video nhúng không đo được % xem nên người học tự xác nhận,
                        // giống bài đọc và bài tài liệu
                        $manualComplete = in_array($currentLesson->content_type, ['text', 'document'], true)
                            || $this->isEmbeddedVideo($currentLesson);

                        $isFirst = $lessons->first()?->id === $currentLesson->id;
                        $isLast = $lessons->last()?->id === $currentLesson->id;
                    @endphp

                    {{--
                        Một hàng ngang, không xuống dòng: nút hai bên, trạng thái ở giữa.
                        Nhãn nút rút gọn trên màn hẹp thay vì để khối nút tụt xuống dòng dưới.
                    --}}
                    <footer class="flex items-center justify-between gap-2 border-t border-brand-line p-4">
                        <button type="button" wire:click="goToPreviousLesson"
                                class="user-btn-secondary shrink-0 !min-h-[40px] !px-3 !text-xs sm:!px-4"
                                @disabled($isFirst)>
                            @svg('heroicon-o-chevron-left', 'h-4 w-4')
                            <span class="hidden sm:inline">Bài trước</span>
                        </button>

                        {{-- Giữa: trạng thái hoàn thành hoặc nút xác nhận --}}
                        <div class="flex min-w-0 flex-1 justify-center">
                            @if ($done)
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-state-success">
                                    @svg('heroicon-s-check-circle', 'h-5 w-5 shrink-0')
                                    <span class="truncate">Đã hoàn thành</span>
                                </span>
                            @elseif ($manualComplete)
                                <button type="button" wire:click="completeLesson"
                                        class="user-btn !min-h-[40px] !px-3 !text-xs sm:!px-4">
                                    @if ($currentLesson->isVideo())
                                        <span class="hidden sm:inline">Tôi đã xem xong</span>
                                        <span class="sm:hidden">Xem xong</span>
                                    @else
                                        <span class="hidden sm:inline">Đánh dấu hoàn thành</span>
                                        <span class="sm:hidden">Hoàn thành</span>
                                    @endif
                                </button>
                            @endif
                        </div>

                        {{--
                            Xong hết bài thì không còn chỗ để đi tiếp: đổi sang nút
                            thoát về danh sách khoá học thay vì để nút mờ vô nghĩa.
                        --}}
                        @if ($allLessonsCompleted)
                            <button type="button" wire:click="exitCourse"
                                    class="user-btn shrink-0 !min-h-[40px] !px-3 !text-xs sm:!px-4">
                                <span class="hidden sm:inline">Thoát khóa học</span>
                                <span class="sm:hidden">Thoát</span>
                                @svg('heroicon-o-arrow-right-on-rectangle', 'h-4 w-4')
                            </button>
                        @else
                            <button type="button" wire:click="goToNextLesson"
                                    class="user-btn-secondary shrink-0 !min-h-[40px] !px-3 !text-xs sm:!px-4"
                                    @disabled($isLast)>
                                <span class="hidden sm:inline">Bài sau</span>
                                @svg('heroicon-o-chevron-right', 'h-4 w-4')
                            </button>
                        @endif
                    </footer>
                </article>
            @endif
        </div>
    </div>
</div>
