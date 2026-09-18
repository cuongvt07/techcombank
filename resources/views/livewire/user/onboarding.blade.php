<div>
    {{-- Hero chào mừng --}}
    <section class="relative mb-5 overflow-hidden rounded-user-lg bg-gradient-to-br from-brand-red via-brand-red to-brand-red-dark p-5 text-white shadow-user sm:p-7">
        <div class="pointer-events-none absolute inset-0 opacity-[0.12]"
             style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 20px 20px;"></div>
        <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/[.07]"></div>

        <div class="relative">
            <p class="text-xs font-semibold uppercase tracking-wider text-white/70">Chào mừng bạn gia nhập</p>
            <h2 class="mt-1.5 text-xl font-bold sm:text-3xl">{{ $company['name'] }}</h2>

            <p class="mt-2.5 max-w-2xl text-sm leading-relaxed text-white/85">
                {{ $company['intro'] }}
            </p>

            @if ($daysSinceJoined !== null)
                <p class="mt-3 inline-flex items-center gap-1.5 rounded-user-pill bg-white/[.18] px-3 py-1.5 text-xs font-semibold backdrop-blur">
                    @svg('heroicon-s-sparkles', 'h-4 w-4')
                    Ngày thứ {{ $daysSinceJoined + 1 }} của bạn tại {{ $company['name'] }}
                </p>
            @endif
        </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Cột trái: lộ trình + quy trình --}}
        <div class="grid gap-4 lg:col-span-2">
            {{-- Lộ trình onboarding --}}
            <section class="user-card p-5">
                <h3 class="mb-1 text-base font-bold">Lộ trình onboarding của bạn</h3>
                <p class="mb-4 text-[13px] text-brand-muted">
                    Những khóa học cần hoàn thành trong thời gian đầu.
                </p>

                @forelse ($onboardingCourses as $enrollment)
                    @php
                        $percent = (int) $enrollment->progress_percent;
                        $done = $enrollment->isCompleted();
                    @endphp

                    <div wire:key="ob-{{ $enrollment->id }}"
                         class="mb-3 flex items-start gap-3 rounded-user-md border border-brand-line p-3 transition-colors hover:border-brand-red last:mb-0">
                        <span @class([
                            'mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full',
                            'bg-state-success-tint text-state-success' => $done,
                            'bg-brand-red-tint text-brand-red' => ! $done,
                        ])>
                            @if ($done)
                                @svg('heroicon-s-check', 'h-4 w-4')
                            @else
                                @svg('heroicon-o-academic-cap', 'h-4 w-4')
                            @endif
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold">{{ $enrollment->course->title }}</p>

                            @if ($enrollment->course->description)
                                <p class="mt-0.5 line-clamp-2 text-xs text-brand-muted">
                                    {{ $enrollment->course->description }}
                                </p>
                            @endif

                            <div class="mt-2 flex items-center gap-2">
                                <div class="progress-track flex-1">
                                    <div class="progress-fill {{ $done ? '!bg-state-success' : '' }}"
                                         style="width: {{ $percent }}%"></div>
                                </div>
                                <span class="shrink-0 text-xs font-bold {{ $done ? 'text-state-success' : 'text-brand-red' }}">
                                    {{ $percent }}%
                                </span>
                            </div>
                        </div>

                        @if (Route::has('learn.course') && ! $done)
                            <a href="{{ route('learn.course', $enrollment->course) }}"
                               wire:navigate
                               class="user-btn shrink-0 !min-h-[34px] !px-3 !text-xs">
                                Học
                            </a>
                        @endif
                    </div>
                @empty
                    <p class="rounded-user-md bg-brand-soft px-4 py-6 text-center text-sm text-brand-muted">
                        Chưa có khóa onboarding nào được gán. Khóa học sẽ tự xuất hiện khi quản trị viên cấu hình.
                    </p>
                @endforelse
            </section>

            {{-- Quy trình — chỉ hiện khi quản trị viên đã cấu hình --}}
            @if ($company['process'])
                <section class="user-card p-5">
                    <h3 class="mb-3 text-base font-bold">Quy trình cần biết</h3>
                    <div class="whitespace-pre-line text-[13px] leading-relaxed text-brand-muted">
                        {{ $company['process'] }}
                    </div>
                </section>
            @endif

            @if ($company['values'])
                <section class="user-card p-5">
                    <h3 class="mb-3 text-base font-bold">Giá trị cốt lõi</h3>
                    <div class="whitespace-pre-line text-[13px] leading-relaxed text-brand-muted">
                        {{ $company['values'] }}
                    </div>
                </section>
            @endif
        </div>

        {{-- Cột phải: đầu mối liên hệ (spec 4.4 + 4.5) --}}
        <aside class="user-card h-fit p-5">
            <h3 class="mb-1 text-base font-bold">Cần hỗ trợ, liên hệ ai?</h3>
            <p class="mb-4 text-[13px] text-brand-muted">Đầu mối theo từng chủ đề.</p>

            @forelse ($contacts as $contact)
                <div wire:key="ct-{{ $contact->id }}"
                     class="mb-3 rounded-user-md border border-brand-line p-3 last:mb-0">
                    <p class="text-sm font-bold">{{ $contact->topic }}</p>

                    @if ($contact->department)
                        <p class="mt-0.5 text-[11px] text-brand-muted">{{ $contact->department->name }}</p>
                    @endif

                    @if ($contact->contact_name)
                        <p class="mt-1.5 text-[13px]">{{ $contact->contact_name }}</p>
                    @endif

                    <div class="mt-1.5 grid gap-1">
                        @if ($contact->email)
                            <a href="mailto:{{ $contact->email }}"
                               class="inline-flex items-center gap-1.5 text-xs text-brand-muted transition-colors hover:text-brand-red">
                                @svg('heroicon-o-envelope', 'h-3.5 w-3.5 shrink-0')
                                <span class="truncate">{{ $contact->email }}</span>
                            </a>
                        @endif

                        @if ($contact->phone)
                            <a href="tel:{{ $contact->phone }}"
                               class="inline-flex items-center gap-1.5 text-xs text-brand-muted transition-colors hover:text-brand-red">
                                @svg('heroicon-o-phone', 'h-3.5 w-3.5 shrink-0')
                                {{ $contact->phone }}
                            </a>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-brand-muted">Chưa có đầu mối nào được cấu hình.</p>
            @endforelse

            @if (Route::has('learn.support'))
                <a href="{{ route('learn.support') }}" wire:navigate class="user-btn-secondary mt-4 w-full">
                    Gửi yêu cầu hỗ trợ
                </a>
            @endif
        </aside>
    </div>
</div>
