<div>
    @if (session('error'))
        <div class="mb-4 rounded-user-md border border-brand-red bg-brand-red-tint px-4 py-3 text-sm font-semibold text-brand-red">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-4 grid gap-2.5 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
        <input type="search" wire:model.live.debounce.400ms="search"
               class="h-11 w-full rounded-user-md border border-brand-line bg-white px-4 text-sm placeholder:text-brand-muted focus:border-brand-red focus:outline-none focus:ring-1 focus:ring-brand-red"
               placeholder="Tìm tài liệu...">

        <select wire:model.live="categoryFilter"
                class="h-11 rounded-user-md border border-brand-line bg-white px-3 text-sm focus:border-brand-red focus:outline-none">
            <option value="">Mọi danh mục</option>
            @foreach ($categories as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="kindFilter"
                class="h-11 rounded-user-md border border-brand-line bg-white px-3 text-sm focus:border-brand-red focus:outline-none">
            <option value="">Mọi loại</option>
            <option value="file">Tài liệu</option>
            <option value="video">Video</option>
        </select>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($documents as $doc)
            <button type="button" wire:click="view({{ $doc->id }})"
                    class="user-card flex items-start gap-3 p-4 text-left transition-shadow hover:shadow-user">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-user-md bg-brand-red-tint text-brand-red">
                    @svg($doc->isVideo() ? 'heroicon-o-play-circle' : 'heroicon-o-document-text', 'h-5 w-5')
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-bold leading-snug">{{ $doc->title }}</span>

                    @if ($doc->description)
                        <span class="mt-1 line-clamp-2 block text-xs text-brand-muted">{{ $doc->description }}</span>
                    @endif

                    <span class="mt-2 flex flex-wrap items-center gap-1.5">
                        @if ($doc->category)
                            <span class="user-pill !min-h-[24px] !px-2.5 !text-[11px]">{{ $doc->category->name }}</span>
                        @endif

                        @if ($doc->isSensitive())
                            <span class="tag tag-amber">Tài liệu mật</span>
                        @endif

                        @unless ($doc->allow_download)
                            <span class="inline-flex items-center gap-1 text-[11px] text-brand-muted">
                                @svg('heroicon-o-eye', 'h-3.5 w-3.5')
                                Chỉ xem online
                            </span>
                        @endunless
                    </span>
                </span>
            </button>
        @empty
            <div class="user-card col-span-full p-8 text-center">
                <p class="font-bold">Không có tài liệu nào.</p>
                <p class="mt-1 text-sm text-brand-muted">
                    Danh mục chỉ hiển thị tài liệu bạn được cấp quyền theo phòng ban và vị trí công việc.
                </p>
            </div>
        @endforelse
    </div>

    @if ($documents->hasPages())
        <div class="mt-4">{{ $documents->links() }}</div>
    @endif

    {{-- Trình xem tài liệu có watermark động (spec 3.3.2) --}}
    @if ($showViewer && $viewingDocument)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-brand-black/60 p-4"
             x-data
             x-on:keydown.escape.window="$wire.showViewer = false">
            <div class="mx-auto max-w-3xl">
                <div class="rounded-user-lg bg-white shadow-user">
                    <header class="flex items-start justify-between gap-3 border-b border-brand-line p-5">
                        <div class="min-w-0">
                            <h2 class="text-base font-bold sm:text-lg">{{ $viewingDocument->title }}</h2>
                            @if ($viewingDocument->category)
                                <p class="mt-0.5 text-xs text-brand-muted">{{ $viewingDocument->category->name }}</p>
                            @endif
                        </div>

                        <button type="button" wire:click="$set('showViewer', false)"
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-user-pill text-brand-muted transition-colors hover:bg-brand-soft"
                                aria-label="Đóng">
                            @svg('heroicon-o-x-mark', 'h-5 w-5')
                        </button>
                    </header>

                    <div class="relative p-5">
                        @if ($watermarkText)
                            {{-- Watermark phủ toàn vùng nội dung, không chặn thao tác đọc --}}
                            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-10 overflow-hidden">
                                @foreach (range(1, 3) as $i)
                                    <span class="rotate-[-20deg] select-none whitespace-nowrap text-lg font-bold text-brand-ink/[.05]">
                                        {{ $watermarkText }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <div class="relative">
                            @if ($viewingDocument->description)
                                <p class="mb-4 text-sm leading-relaxed text-brand-muted">{{ $viewingDocument->description }}</p>
                            @endif

                            @php
                                $version = $viewingDocument->currentVersion;
                                $hasFile = $version?->storedFile !== null;
                            @endphp

                            @if (! $hasFile)
                                <div class="rounded-user-md border border-brand-line bg-brand-soft p-8 text-center">
                                    @svg('heroicon-o-exclamation-triangle', 'mx-auto h-10 w-10 text-state-warning')
                                    <p class="mt-3 text-sm font-bold">Tài liệu chưa có nội dung</p>
                                    <p class="mt-1 text-xs text-brand-muted">
                                        Bộ phận quản trị chưa tải file lên cho tài liệu này.
                                    </p>
                                </div>
                            @elseif ($viewingDocument->isVideo())
                                @php $embedUrl = $viewingDocument->embedUrl(); @endphp

                                @if ($embedUrl)
                                    <iframe src="{{ $embedUrl }}"
                                            class="aspect-video w-full rounded-user-md"
                                            allow="accelerometer; encrypted-media; picture-in-picture"
                                            allowfullscreen
                                            title="{{ $viewingDocument->title }}"></iframe>
                                @elseif ($viewingDocument->video_url)
                                    <video class="aspect-video w-full rounded-user-md bg-brand-black"
                                           controls
                                           controlsList="nodownload"
                                           oncontextmenu="return false"
                                           src="{{ $viewingDocument->video_url }}">
                                        Trình duyệt không hỗ trợ phát video.
                                    </video>
                                @else
                                    <p class="rounded-user-md bg-brand-soft p-6 text-center text-sm text-brand-muted">
                                        Video chưa có link nguồn.
                                    </p>
                                @endif
                            @elseif (str_contains((string) $version->mime_type, 'pdf'))
                                {{-- PDF nhúng thẳng: trình đọc sẵn có của trình duyệt,
                                     không cần thư viện ngoài --}}
                                <iframe src="{{ route('learn.documents.stream', $viewingDocument) }}#toolbar={{ $permission['can_download'] ?? false ? 1 : 0 }}"
                                        class="h-[60vh] w-full rounded-user-md border border-brand-line"
                                        title="{{ $viewingDocument->title }}"></iframe>
                            @elseif (str_starts_with((string) $version->mime_type, 'image/'))
                                <img src="{{ route('learn.documents.stream', $viewingDocument) }}"
                                     alt="{{ $viewingDocument->title }}"
                                     class="mx-auto max-h-[60vh] rounded-user-md border border-brand-line">
                            @else
                                {{-- Định dạng trình duyệt không mở được (Word, Excel...):
                                     chỉ cho tải nếu tài liệu cho phép --}}
                                <div class="rounded-user-md border border-brand-line bg-brand-soft p-8 text-center">
                                    @svg('heroicon-o-document-text', 'mx-auto h-12 w-12 text-brand-muted')
                                    <p class="mt-3 text-sm font-bold">{{ $version->original_filename }}</p>
                                    <p class="mt-1 text-xs text-brand-muted">
                                        Định dạng này cần mở bằng ứng dụng trên máy.
                                    </p>

                                    @if ($permission['can_download'] ?? false)
                                        <a href="{{ route('learn.documents.download', $viewingDocument) }}"
                                           class="user-btn mt-4">
                                            @svg('heroicon-o-arrow-down-tray', 'h-4 w-4')
                                            Tải xuống
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>

                    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-line p-4">
                        <p class="text-[11px] text-brand-muted">
                            Lượt truy cập của bạn đã được ghi nhận để phục vụ kiểm soát nội bộ.
                        </p>

                        <div class="flex items-center gap-2">
                            @if ($permission['can_download'] ?? false)
                                <a href="{{ route('learn.documents.download', $viewingDocument) }}"
                                   class="user-pill inline-flex items-center gap-1.5 transition-colors hover:bg-brand-red hover:text-white">
                                    @svg('heroicon-o-arrow-down-tray', 'h-3.5 w-3.5')
                                    Tải bản gốc
                                </a>
                            @else
                                <span class="user-pill inline-flex items-center gap-1.5">
                                    @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5')
                                    Không được tải xuống
                                </span>
                            @endif
                        </div>
                    </footer>
                </div>
            </div>
        </div>
    @endif
</div>
