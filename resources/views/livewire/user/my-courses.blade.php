<div>
    {{--
        Hero: mảng đỏ thương hiệu lớn, tạo cảm hứng học tập (spec 6.5).
        Vòng tiến độ đặt bên phải vì đó là con số người học tìm đầu tiên.
    --}}
    <section class="relative mb-5 overflow-hidden rounded-user-lg bg-gradient-to-br from-brand-red via-brand-red to-brand-red-dark p-5 text-white shadow-user sm:p-7">
        {{-- Hoạ tiết nền tạo chiều sâu, không cần file ảnh --}}
        <div class="pointer-events-none absolute inset-0 opacity-[0.12]"
             style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 20px 20px;"></div>
        <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/[.07]"></div>
        <div class="pointer-events-none absolute -bottom-20 -left-10 h-48 w-48 rounded-full bg-white/[.05]"></div>

        <div class="relative flex flex-wrap items-center justify-between gap-4 sm:gap-6">
            <div class="min-w-0 flex-1 basis-0">
                <p class="text-xs font-semibold uppercase tracking-wider text-white/70">
                    {{ now()->hour < 12 ? 'Chào buổi sáng' : (now()->hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối') }}
                </p>
                <h2 class="mt-1.5 truncate text-xl font-bold sm:text-3xl">
                    {{ $employee?->full_name ?? auth()->user()->name }}
                </h2>

                @if ($summary['overdue'] > 0)
                    <p class="mt-2 inline-flex items-center gap-1.5 rounded-user-pill bg-white/[.18] px-3 py-1.5 text-xs font-semibold backdrop-blur">
                        @svg('heroicon-o-exclamation-triangle', 'h-4 w-4')
                        Bạn có {{ $summary['overdue'] }} khóa quá hạn cần hoàn thành
                    </p>
                @elseif ($summary['in_progress'] > 0)
                    <p class="mt-2 text-sm text-white/85">
                        Bạn đang học {{ $summary['in_progress'] }} khóa. Tiếp tục nhé!
                    </p>
                @elseif ($summary['total'] > 0 && $summary['completed'] === $summary['total'])
                    <p class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-white">
                        @svg('heroicon-s-sparkles', 'h-4 w-4')
                        Bạn đã hoàn thành tất cả khóa học được giao
                    </p>
                @else
                    <p class="mt-2 text-sm text-white/85">Sẵn sàng cho hành trình học tập mới.</p>
                @endif

                {{-- Chuỗi ngày học: động lực duy trì thói quen --}}
                @if ($summary['streak'] > 0)
                    <p class="mt-3 inline-flex items-center gap-1.5 rounded-user-pill bg-white/[.18] px-3 py-1.5 text-xs font-semibold backdrop-blur">
                        @svg('heroicon-s-fire', 'h-4 w-4')
                        {{ $summary['streak'] }} ngày học liên tiếp
                    </p>
                @endif
            </div>

            {{-- Vòng tiến độ tổng, vẽ bằng SVG --}}
            @if ($summary['total'] > 0)
                @php
                    $pct = $summary['overall_percent'];
                    $circumference = 2 * M_PI * 42;
                    $dash = $pct / 100 * $circumference;
                @endphp

                <div class="relative h-[92px] w-[92px] shrink-0 sm:h-[116px] sm:w-[116px]">
                    <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90">
                        <circle cx="50" cy="50" r="42" fill="none" stroke="rgba(255,255,255,.22)" stroke-width="9" />
                        <circle cx="50" cy="50" r="42" fill="none" stroke="white" stroke-width="9"
                                stroke-linecap="round"
                                stroke-dasharray="{{ $dash }} {{ $circumference }}"
                                class="transition-[stroke-dasharray] duration-1000" />
                    </svg>
                    <div class="absolute inset-0 grid place-items-center text-center">
                        <div>
                            <strong class="block text-2xl font-bold leading-none">{{ $pct }}%</strong>
                            <span class="mt-0.5 block text-[10px] text-white/75">hoàn thành</span>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Ba chỉ số nhanh --}}
        <div class="relative mt-5 grid grid-cols-3 gap-2 sm:gap-2.5">
            <div class="min-w-0 rounded-user-md bg-white/[.14] px-2.5 py-2.5 backdrop-blur sm:px-3">
                <strong class="block text-xl font-bold leading-none sm:text-2xl">{{ $summary['total'] }}</strong>
                <span class="mt-1 block truncate text-[10px] text-white/80 sm:text-[11px]">Khóa được giao</span>
            </div>
            <div class="min-w-0 rounded-user-md bg-white/[.14] px-2.5 py-2.5 backdrop-blur sm:px-3">
                <strong class="block text-xl font-bold leading-none sm:text-2xl">{{ $summary['completed'] }}</strong>
                <span class="mt-1 block truncate text-[10px] text-white/80 sm:text-[11px]">Đã hoàn thành</span>
            </div>
            <div class="min-w-0 rounded-user-md bg-white/[.14] px-2.5 py-2.5 backdrop-blur sm:px-3">
                <strong class="block text-xl font-bold leading-none sm:text-2xl">{{ $summary['certificates'] }}</strong>
                <span class="mt-1 block truncate text-[10px] text-white/80 sm:text-[11px]">Chứng nhận</span>
            </div>
        </div>
    </section>

    {{-- Filter dạng pill (spec 6.5: thành phần phụ được phép bo tròn) --}}
    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        @foreach ([
            'all' => 'Tất cả',
            'mandatory' => 'Bắt buộc',
            'in_progress' => 'Đang học',
            'completed' => 'Đã xong',
        ] as $key => $label)
            <button type="button"
                    wire:click="setFilter('{{ $key }}')"
                    @class([
                        'user-pill transition-all duration-200',
                        'bg-brand-red text-white shadow-user-sm' => $filter === $key,
                        'hover:bg-brand-line' => $filter !== $key,
                    ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($enrollments as $enrollment)
            @php
                $percent = (int) $enrollment->progress_percent;
                $isDone = $enrollment->isCompleted();
                $isOverdue = $enrollment->status === \App\Models\Enrollment::STATUS_OVERDUE;
                $days = $enrollment->daysUntilDue();
            @endphp

            <article wire:key="course-{{ $enrollment->id }}"
                     @class([
                         'group relative flex flex-col overflow-hidden rounded-user-md border bg-white shadow-user-sm',
                         'transition-all duration-300 hover:-translate-y-1 hover:shadow-user',
                         'border-brand-red' => $isOverdue,
                         'border-brand-line' => ! $isOverdue,
                     ])>

                {{-- Ảnh bìa: dùng ảnh upload nếu có, không thì sinh gradient theo mã khóa --}}
                <x-course-cover :course="$enrollment->course" height="h-28">
                    <div class="absolute inset-0 flex items-start justify-between p-3">
                        <span @class([
                            'rounded-user-pill px-2.5 py-1 text-[11px] font-semibold backdrop-blur',
                            'bg-white text-brand-red' => $enrollment->is_mandatory,
                            'bg-white/25 text-white' => ! $enrollment->is_mandatory,
                        ])>
                            {{ $enrollment->is_mandatory ? 'Bắt buộc' : 'Tự chọn' }}
                        </span>

                        @if ($isOverdue)
                            <span class="rounded-user-pill bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-red">
                                Quá hạn
                            </span>
                        @elseif ($isDone)
                            <span class="inline-flex items-center gap-1 rounded-user-pill bg-white px-2.5 py-1 text-[11px] font-semibold text-state-success">
                                @svg('heroicon-s-check-circle', 'h-3.5 w-3.5')
                                Hoàn thành
                            </span>
                        @elseif ($days !== null && $days <= 7)
                            <span class="rounded-user-pill bg-state-warning px-2.5 py-1 text-[11px] font-semibold text-white">
                                còn {{ max(0, $days) }} ngày
                            </span>
                        @endif
                    </div>
                </x-course-cover>

                <div class="flex flex-1 flex-col p-4">
                    <h3 class="line-clamp-2 text-[15px] font-bold leading-snug transition-colors group-hover:text-brand-red">
                        {{ $enrollment->course->title }}
                    </h3>

                    @if ($enrollment->course->description)
                        <p class="mt-1.5 line-clamp-2 text-[13px] leading-relaxed text-brand-muted">
                            {{ $enrollment->course->description }}
                        </p>
                    @endif

                    <div class="mt-auto pt-4">
                        <div class="mb-1.5 flex items-center justify-between text-xs">
                            <span class="text-brand-muted">
                                {{ $enrollment->completed_lessons }}/{{ $enrollment->total_lessons }} bài
                                @php $left = $enrollment->total_lessons - $enrollment->completed_lessons; @endphp
                                @if ($left > 0)
                                    · <span class="font-semibold text-state-warning">còn {{ $left }}</span>
                                @endif
                            </span>
                            <span class="font-bold {{ $isDone ? 'text-state-success' : 'text-brand-red' }}">{{ $percent }}%</span>
                        </div>

                        <div class="progress-track mb-4">
                            <div class="progress-fill {{ $isDone ? '!bg-state-success' : '' }}"
                                 style="width: {{ $percent }}%"></div>
                        </div>

                        @if (Route::has('learn.course'))
                            <a href="{{ route('learn.course', $enrollment->course) }}"
                               wire:navigate
                               class="{{ $isDone ? 'user-btn-secondary' : 'user-btn' }} w-full">
                                @if ($isDone)
                                    Xem lại
                                @elseif ($percent > 0)
                                    @svg('heroicon-s-play', 'h-4 w-4')
                                    Học tiếp
                                @else
                                    @svg('heroicon-s-play', 'h-4 w-4')
                                    Bắt đầu học
                                @endif
                            </a>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="user-card col-span-full p-10 text-center">
                <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-brand-red-tint text-brand-red">
                    @svg('heroicon-o-academic-cap', 'h-8 w-8')
                </span>
                <p class="mt-4 font-bold">Bạn chưa được giao khóa học nào.</p>
                <p class="mt-1 text-sm text-brand-muted">
                    Khóa học sẽ tự động xuất hiện khi quản trị viên gán theo phòng ban hoặc vị trí của bạn.
                </p>
            </div>
        @endforelse
    </div>
</div>
