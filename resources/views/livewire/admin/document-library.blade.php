<div>
    <x-admin.page-header title="Thư viện tài liệu"
                         subtitle="Tài liệu, video bài giảng, phiên bản và cấp độ bảo mật.">
        <x-slot:actions>
            <button type="button" wire:click="create" class="admin-btn">
                @svg('heroicon-o-plus', 'h-4 w-4')
                Thêm tài liệu
            </button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="admin-panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
            <div class="min-w-[220px] flex-1">
                <input type="search" wire:model.live.debounce.400ms="search" class="admin-input"
                       placeholder="Tìm theo tiêu đề hoặc mô tả...">
            </div>

            <select wire:model.live="kindFilter" class="admin-input w-auto min-w-[140px]">
                <option value="">Mọi loại</option>
                <option value="file">Tài liệu</option>
                <option value="video">Video</option>
            </select>

            <select wire:model.live="categoryFilter" class="admin-input w-auto min-w-[180px]">
                <option value="">Mọi danh mục</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="statusFilter" class="admin-input w-auto min-w-[160px]">
                <option value="">Mọi trạng thái</option>
                <option value="draft">Nháp</option>
                <option value="pending_review">Chờ duyệt</option>
                <option value="published">Đã xuất bản</option>
                <option value="archived">Lưu trữ</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Tài liệu</th>
                        <th class="w-28">Loại</th>
                        <th>Danh mục</th>
                        <th class="w-36">Bảo mật</th>
                        <th class="w-24">Phiên bản</th>
                        <th class="w-32">Trạng thái</th>
                        <th class="w-24 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($documents as $doc)
                        <tr wire:key="doc-{{ $doc->id }}">
                            <td>
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-admin bg-brand-soft text-brand-muted">
                                        @svg($doc->isVideo() ? 'heroicon-o-play-circle' : 'heroicon-o-document-text', 'h-4 w-4')
                                    </span>
                                    <div class="min-w-0">
                                        <div class="truncate font-bold">{{ $doc->title }}</div>
                                        @if ($doc->currentVersion)
                                            <div class="truncate text-xs text-brand-muted">
                                                {{ $doc->currentVersion->original_filename }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="tag">{{ $doc->isVideo() ? 'Video' : 'Tài liệu' }}</span>
                            </td>
                            <td class="text-brand-muted">{{ $doc->category?->name ?? '—' }}</td>
                            <td>
                                @php
                                    $confMap = [
                                        'public' => ['', 'Công khai'],
                                        'internal' => ['', 'Nội bộ'],
                                        'confidential' => ['tag-amber', 'Mật'],
                                        'restricted' => ['tag-red', 'Tối mật'],
                                    ];
                                    [$cls, $label] = $confMap[$doc->confidentiality] ?? ['', $doc->confidentiality];
                                @endphp
                                <span class="tag {{ $cls }}">{{ $label }}</span>
                                @if (! $doc->allow_download)
                                    <span class="mt-1 block text-[11px] text-brand-muted">Không cho tải</span>
                                @endif
                            </td>
                            <td>
                                <button type="button" wire:click="viewVersions({{ $doc->id }})"
                                        class="font-bold text-brand-red hover:underline">
                                    {{ $doc->versions_count }} bản
                                </button>
                            </td>
                            <td>
                                @php
                                    $stMap = [
                                        'draft' => ['', 'Nháp'],
                                        'pending_review' => ['tag-amber', 'Chờ duyệt'],
                                        'published' => ['tag-green', 'Đã xuất bản'],
                                        'archived' => ['', 'Lưu trữ'],
                                    ];
                                    [$scls, $slabel] = $stMap[$doc->status] ?? ['', $doc->status];
                                @endphp
                                <span class="tag {{ $scls }}">{{ $slabel }}</span>
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa tài liệu"
                                                         wire:click="edit({{ $doc->id }})" />

                                    @if ($doc->status === 'published')
                                        <x-admin.icon-button icon="heroicon-o-archive-box" label="Gỡ xuất bản"
                                                             variant="danger"
                                                             wire:click="unpublish({{ $doc->id }})"
                                                             wire:confirm="Gỡ xuất bản tài liệu này?" />
                                    @else
                                        <x-admin.icon-button icon="heroicon-o-paper-airplane" label="Xuất bản tài liệu"
                                                             variant="primary"
                                                             wire:click="publish({{ $doc->id }})" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-brand-muted">Chưa có tài liệu nào.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($documents->hasPages())
            <div class="border-t border-brand-line p-4">{{ $documents->links() }}</div>
        @endif
    </div>

    @if ($showModal)
        <x-admin.modal :title="$editingId ? 'Sửa tài liệu' : 'Thêm tài liệu'" maxWidth="max-w-3xl">
            <form wire:submit="save" id="doc-form" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="d_title" class="admin-label">Tiêu đề</label>
                    <input id="d_title" type="text" wire:model="title" class="admin-input">
                    @error('title') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="d_desc" class="admin-label">Mô tả</label>
                    <textarea id="d_desc" wire:model="description" rows="2" class="admin-input !h-auto py-2.5"></textarea>
                </div>

                <div>
                    <label for="d_kind" class="admin-label">Loại nội dung</label>
                    <select id="d_kind" wire:model.live="kind" class="admin-input" @disabled($editingId)>
                        <option value="file">Tài liệu (Word/Excel/PDF/PPT)</option>
                        <option value="video">Video bài giảng</option>
                    </select>
                    @if ($editingId)
                        <p class="mt-1 text-xs text-brand-muted">Không đổi được loại sau khi đã tạo.</p>
                    @endif
                </div>

                <div>
                    <label for="d_cat" class="admin-label">Danh mục</label>
                    <select id="d_cat" wire:model="document_category_id" class="admin-input">
                        <option value="">— Chọn —</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="d_dept" class="admin-label">Phòng ban ban hành</label>
                    <select id="d_dept" wire:model="owner_department_id" class="admin-input">
                        <option value="">— Chọn —</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="d_conf" class="admin-label">Cấp độ bảo mật</label>
                    <select id="d_conf" wire:model="confidentiality" class="admin-input">
                        <option value="public">Công khai</option>
                        <option value="internal">Nội bộ</option>
                        <option value="confidential">Mật</option>
                        <option value="restricted">Tối mật</option>
                    </select>
                </div>

                @if ($kind === 'video')
                    {{-- Video nhúng từ nguồn ngoài: giới hạn upload 2MB không đủ cho video,
                         và dịch vụ chuyên dụng lo luôn việc chuyển mã, băng thông --}}
                    <div class="sm:col-span-2">
                        <label for="d_video" class="admin-label">Link video</label>
                        <input id="d_video" type="url" wire:model.blur="video_url" class="admin-input"
                               placeholder="https://www.youtube.com/watch?v=... hoặc https://vimeo.com/...">
                        @error('video_url') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror

                        <p class="mt-1.5 text-xs text-brand-muted">
                            Dán link từ YouTube, Vimeo, hoặc link file video trên hạ tầng nội bộ
                            (.mp4/.webm/.m3u8). Hệ thống tự nhận diện nguồn.
                        </p>

                        {{-- Xem trước ngay để người soạn biết đã dán đúng link chưa --}}
                        @php
                            $embedService = app(\App\Services\VideoEmbedService::class);
                            $parsed = $video_url ? $embedService->parse($video_url) : null;
                            $previewUrl = $parsed ? $embedService->embedUrl($parsed['provider'], $parsed['id']) : null;
                        @endphp

                        @if ($parsed)
                            <div class="mt-3">
                                <div class="mb-2 flex items-center gap-2">
                                    <span class="tag tag-green">{{ $embedService->providerLabel($parsed['provider']) }}</span>
                                    <span class="text-xs text-brand-muted">Đã nhận diện nguồn video</span>
                                </div>

                                @if ($previewUrl)
                                    <iframe src="{{ $previewUrl }}"
                                            class="aspect-video w-full rounded-admin border border-brand-line"
                                            allowfullscreen
                                            title="Xem trước video"></iframe>
                                @else
                                    <video src="{{ $video_url }}" controls
                                           class="aspect-video w-full rounded-admin bg-brand-black"></video>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="sm:col-span-2">
                        <label for="d_dur" class="admin-label">Thời lượng (giây)</label>
                        <input id="d_dur" type="number" wire:model="duration_seconds" class="admin-input">
                        <p class="mt-1 text-xs text-brand-muted">
                            Dùng để tính % xem hết video của từng nhân viên.
                        </p>
                        @error('duration_seconds') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="sm:col-span-2">
                        <label for="d_file" class="admin-label">
                            {{ $editingId ? 'Tải lên phiên bản mới (để trống nếu chỉ sửa thông tin)' : 'File nội dung' }}
                        </label>
                        <input id="d_file" type="file" wire:model="upload"
                               class="admin-input !h-auto !py-2 file:mr-3 file:rounded-admin file:border-0 file:bg-brand-soft file:px-3 file:py-1.5 file:text-xs file:font-semibold">
                        <p class="mt-1 text-xs text-brand-muted">
                            Tối đa 2MB · PDF, Word, Excel, PowerPoint.
                        </p>
                        @error('upload') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        <div wire:loading wire:target="upload" class="mt-1 text-xs font-semibold text-brand-muted">Đang tải lên...</div>
                    </div>
                @endif

                @if ($editingId)
                    <div class="sm:col-span-2">
                        <label for="d_note" class="admin-label">Ghi chú thay đổi phiên bản</label>
                        <input id="d_note" type="text" wire:model="change_note" class="admin-input"
                               placeholder="VD: Cập nhật quy trình mục 4">
                    </div>
                @endif

                <div class="grid gap-2.5 border-t border-brand-line pt-4 sm:col-span-2">
                    <p class="admin-label !mb-0">Chống thất thoát tài liệu</p>

                    <label class="flex cursor-pointer items-start gap-2.5 text-sm font-medium">
                        <input type="checkbox" wire:model="allow_download"
                               class="mt-0.5 h-4 w-4 shrink-0 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        <span>
                            Cho phép tải bản gốc
                            <span class="mt-0.5 block text-xs font-normal text-brand-muted">
                                Tắt tùy chọn này thì nhân viên chỉ xem trực tuyến, không tải được file gốc.
                            </span>
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-start gap-2.5 text-sm font-medium">
                        <input type="checkbox" wire:model="enable_watermark"
                               class="mt-0.5 h-4 w-4 shrink-0 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        <span>
                            Bật watermark động khi xem
                            <span class="mt-0.5 block text-xs font-normal text-brand-muted">
                                Hiển thị tên, email và thời gian truy cập đè lên nội dung để truy vết rò rỉ.
                            </span>
                        </span>
                    </label>
                </div>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="doc-form" class="admin-btn" wire:loading.attr="disabled" wire:target="save,upload">
                    {{ $editingId ? 'Lưu thay đổi' : 'Tạo tài liệu' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Lịch sử phiên bản + rollback (spec 3.2.1) --}}
    @if ($showVersions)
        <x-admin.modal wireModel="showVersions" title="Lịch sử phiên bản" maxWidth="max-w-3xl">
            @if ($versions->isEmpty())
                <p class="text-sm text-brand-muted">Tài liệu này chưa có phiên bản nào.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th class="w-20">Bản</th>
                                <th>Tên file</th>
                                <th class="w-28">Dung lượng</th>
                                <th>Ghi chú</th>
                                <th>Người tải lên</th>
                                <th class="w-32 text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($versions as $version)
                                <tr wire:key="ver-{{ $version->id }}">
                                    <td class="font-bold">v{{ $version->version_no }}</td>
                                    <td class="truncate">{{ $version->original_filename }}</td>
                                    <td class="text-brand-muted">
                                        {{ $version->size_bytes ? number_format($version->size_bytes / 1024, 0) . ' KB' : '—' }}
                                    </td>
                                    <td class="text-brand-muted">{{ $version->change_note ?? '—' }}</td>
                                    <td class="text-brand-muted">{{ $version->createdBy?->name ?? '—' }}</td>
                                    <td>
                                        <div class="flex justify-end">
                                            @if ($version->isCurrent())
                                                <span class="tag tag-green">Đang dùng</span>
                                            @else
                                                <x-admin.icon-button icon="heroicon-o-arrow-uturn-left"
                                                                     label="Khôi phục phiên bản {{ $version->version_no }}"
                                                                     wire:click="rollback({{ $version->id }})"
                                                                     wire:confirm="Khôi phục về phiên bản {{ $version->version_no }}?" />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <x-slot:footer>
                <button type="button" wire:click="$set('showVersions', false)" class="admin-btn-secondary">Đóng</button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
