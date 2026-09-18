<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
    <div class="min-w-0 space-y-5">
        {{-- Tài liệu hướng dẫn: đặt trên form vì phần lớn vướng mắc đã có
             lời giải trong tài liệu, đọc trước thì không cần gửi phiếu --}}
        @if (\App\Models\Setting::get('guide.enabled', true) && Route::has('learn.guide'))
            <section class="user-card flex flex-wrap items-center gap-4 p-5">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-user-md bg-brand-red-tint text-brand-red">
                    @svg('heroicon-o-book-open', 'h-6 w-6')
                </span>

                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-bold">Tài liệu hướng dẫn sử dụng</h2>
                    <p class="mt-1 text-[13px] text-brand-muted">
                        Hướng dẫn đầy đủ các chức năng: học bài, làm kiểm tra, xem tài liệu, dùng trợ lý AI.
                        @if (\App\Models\Setting::get('guide.watermark', true))
                            Bản tải về có đóng dấu tên bạn.
                        @endif
                    </p>
                </div>

                <a href="{{ route('learn.guide') }}" class="user-btn shrink-0">
                    @svg('heroicon-o-arrow-down-tray', 'h-4 w-4')
                    Tải PDF
                </a>
            </section>
        @endif

        <section class="user-card p-5">
            <h2 class="text-base font-bold">Gửi yêu cầu hỗ trợ</h2>
            <p class="mt-1 text-sm text-brand-muted">
                Mô tả vấn đề bạn gặp phải, bộ phận phụ trách sẽ tiếp nhận và phản hồi.
            </p>

            <form wire:submit="submit" class="mt-4 grid gap-4">
                <div>
                    <label for="s_topic" class="mb-1.5 block text-xs font-semibold uppercase text-brand-muted">Chủ đề</label>
                    <select id="s_topic" wire:model="topic"
                            class="h-11 w-full rounded-user-md border border-brand-line bg-white px-3.5 text-sm focus:border-brand-red focus:outline-none focus:ring-1 focus:ring-brand-red">
                        <option value="">— Chọn chủ đề —</option>
                        @foreach ($contacts as $contact)
                            <option value="{{ $contact->topic }}">{{ $contact->topic }}</option>
                        @endforeach
                        <option value="Khác">Khác</option>
                    </select>
                    @error('topic') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="s_subject" class="mb-1.5 block text-xs font-semibold uppercase text-brand-muted">Tiêu đề</label>
                    <input id="s_subject" type="text" wire:model="subject"
                           class="h-11 w-full rounded-user-md border border-brand-line bg-white px-3.5 text-sm placeholder:text-brand-muted focus:border-brand-red focus:outline-none focus:ring-1 focus:ring-brand-red"
                           placeholder="Tóm tắt ngắn gọn vấn đề">
                    @error('subject') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="s_content" class="mb-1.5 block text-xs font-semibold uppercase text-brand-muted">Nội dung</label>
                    <textarea id="s_content" wire:model="content" rows="5"
                              class="w-full rounded-user-md border border-brand-line bg-white p-3.5 text-sm placeholder:text-brand-muted focus:border-brand-red focus:outline-none focus:ring-1 focus:ring-brand-red"
                              placeholder="Mô tả chi tiết vấn đề..."></textarea>
                    @error('content') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <span class="mb-1.5 block text-xs font-semibold uppercase text-brand-muted">Mức độ ưu tiên</span>
                    <div class="flex gap-2">
                        @foreach (['low' => 'Thấp', 'normal' => 'Bình thường', 'high' => 'Gấp'] as $value => $label)
                            <button type="button" wire:click="$set('priority', '{{ $value }}')"
                                    @class([
                                        'user-pill transition-colors',
                                        'bg-brand-red text-white' => $priority === $value,
                                        'hover:bg-brand-line' => $priority !== $value,
                                    ])>
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <button type="submit" class="user-btn w-full sm:w-auto sm:justify-self-start">
                    Gửi yêu cầu
                </button>
            </form>
        </section>

        @if ($requests->isNotEmpty())
            <section class="mt-5">
                <h2 class="mb-2.5 text-sm font-bold">Yêu cầu đã gửi</h2>

                <div class="user-card overflow-hidden">
                    @foreach ($requests as $request)
                        <div class="border-b border-brand-line p-4 last:border-b-0">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold">{{ $request->subject }}</p>
                                    <p class="mt-0.5 font-mono text-[11px] text-brand-muted">{{ $request->ticket_no }}</p>
                                </div>

                                @php
                                    $map = [
                                        'new' => ['tag-amber', 'Mới gửi'],
                                        'in_progress' => ['tag-amber', 'Đang xử lý'],
                                        'resolved' => ['tag-green', 'Đã xử lý'],
                                        'closed' => ['', 'Đã đóng'],
                                    ];
                                    [$cls, $label] = $map[$request->status] ?? ['', $request->status];
                                @endphp
                                <span class="tag {{ $cls }} shrink-0">{{ $label }}</span>
                            </div>

                            <p class="mt-1.5 text-xs text-brand-muted">
                                {{ $request->topic }} · {{ $request->created_at->format('d/m/Y H:i') }}
                            </p>

                            @if ($request->resolution)
                                <div class="mt-2.5 rounded-user-md bg-state-success-tint p-3">
                                    <p class="text-xs font-semibold text-state-success">Phản hồi:</p>
                                    <p class="mt-1 text-xs text-brand-ink">{{ $request->resolution }}</p>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    {{-- Đầu mối hỗ trợ theo phòng ban (spec 4.5) --}}
    <aside class="user-card overflow-hidden">
        <h2 class="border-b border-brand-line px-5 py-3.5 text-sm font-bold">Đầu mối liên hệ</h2>

        <div class="grid">
            @forelse ($contacts as $contact)
                <div class="border-b border-brand-line p-4 last:border-b-0">
                    <p class="text-sm font-bold">{{ $contact->topic }}</p>
                    <p class="mt-0.5 text-xs text-brand-muted">{{ $contact->contact_name }}</p>

                    <div class="mt-2 grid gap-1">
                        @if ($contact->email)
                            <a href="mailto:{{ $contact->email }}"
                               class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-red hover:underline">
                                @svg('heroicon-o-envelope', 'h-4 w-4')
                                {{ $contact->email }}
                            </a>
                        @endif

                        @if ($contact->phone)
                            <a href="tel:{{ $contact->phone }}"
                               class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-red hover:underline">
                                @svg('heroicon-o-phone', 'h-4 w-4')
                                {{ $contact->phone }}
                            </a>
                        @endif
                    </div>

                    @if ($contact->note)
                        <p class="mt-2 text-[11px] text-brand-muted">{{ $contact->note }}</p>
                    @endif
                </div>
            @empty
                <p class="p-5 text-sm text-brand-muted">Chưa cấu hình đầu mối hỗ trợ.</p>
            @endforelse
        </div>
    </aside>
</div>
