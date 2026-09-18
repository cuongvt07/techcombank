<div>
    <x-admin.page-header title="Kho tài nguyên"
                         subtitle="Thư mục và file dùng chung toàn hệ thống. Kéo file từ máy vào để tải lên.">
        <x-slot:actions>
            <button type="button" wire:click="createFolder" class="admin-btn-secondary">
                @svg('heroicon-o-folder-plus', 'h-4 w-4')
                Thư mục mới
            </button>

            <label class="admin-btn cursor-pointer">
                @svg('heroicon-o-arrow-up-tray', 'h-4 w-4')
                Tải file lên
                <input type="file" wire:model="uploads" multiple class="hidden">
            </label>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-4 xl:grid-cols-[260px_minmax(0,1fr)] xl:items-start">
        {{-- Cây thư mục --}}
        <aside class="admin-panel xl:sticky xl:top-4">
            <div class="admin-panel-head">
                <h3>Thư mục</h3>
            </div>

            <nav class="max-h-[60vh] overflow-y-auto p-2">
                <button type="button" wire:click="openFolder(null)"
                        @class([
                            'flex w-full items-center gap-2 whitespace-nowrap rounded-admin px-2.5 py-2 text-left text-[13px] font-semibold transition-colors',
                            'bg-brand-red-tint text-brand-red' => $folder === null,
                            'text-brand-ink hover:bg-brand-soft' => $folder !== null,
                        ])>
                    @svg('heroicon-o-home', 'h-4 w-4 shrink-0')
                    Thư mục gốc
                </button>

                {{-- Dựng cây đệ quy từ dữ liệu đã nhóm sẵn, không truy vấn thêm --}}
                @include('livewire.admin.partials.folder-tree', [
                    'nodes' => $tree[null] ?? collect(),
                    'tree' => $tree,
                    'level' => 0,
                    'activeId' => $folder,
                ])
            </nav>

            <div class="border-t border-brand-line p-3 text-[11px] text-brand-muted">
                {{ $stats['folders'] }} thư mục · {{ $stats['files'] }} file ·
                {{ app(\App\Services\FileStorageService::class)->humanSize($stats['size']) }}
            </div>
        </aside>

        <div class="min-w-0">
            {{-- Breadcrumb --}}
            <div class="mb-3 flex flex-wrap items-center gap-1.5 text-sm">
                <button type="button" wire:click="openFolder(null)"
                        class="whitespace-nowrap font-semibold text-brand-muted hover:text-brand-red">
                    Kho tài nguyên
                </button>

                @foreach ($breadcrumbs as $crumb)
                    @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5 shrink-0 text-brand-muted')
                    <button type="button" wire:click="openFolder({{ $crumb->id }})"
                            @class([
                                'whitespace-nowrap font-semibold',
                                'text-brand-ink' => $loop->last,
                                'text-brand-muted hover:text-brand-red' => ! $loop->last,
                            ])>
                        {{ $crumb->name }}
                    </button>
                @endforeach
            </div>

            {{-- Vùng kéo thả: bao quanh toàn bộ nội dung nên thả chỗ nào cũng được --}}
            <div x-data="{ dragging: false }"
                 x-on:dragover.prevent="dragging = true"
                 x-on:dragleave.prevent="dragging = false"
                 x-on:drop.prevent="
                     dragging = false;
                     if ($event.dataTransfer.files.length) {
                         $refs.fileInput.files = $event.dataTransfer.files;
                         $refs.fileInput.dispatchEvent(new Event('change', { bubbles: true }));
                     }
                 "
                 class="relative">

                <input type="file" wire:model="uploads" multiple x-ref="fileInput" class="hidden">

                {{-- Lớp phủ khi đang kéo file vào --}}
                <div x-show="dragging" x-cloak
                     class="absolute inset-0 z-20 grid place-items-center rounded-admin border-2 border-dashed border-brand-red bg-brand-red-tint/95">
                    <div class="text-center">
                        @svg('heroicon-o-arrow-down-tray', 'mx-auto h-10 w-10 text-brand-red')
                        <p class="mt-2 font-semibold text-brand-red">Thả file vào đây để tải lên</p>
                        <p class="mt-0.5 text-xs text-brand-red">
                            Vào thư mục: {{ $currentFolder?->name ?? 'Thư mục gốc' }}
                        </p>
                    </div>
                </div>

                {{-- Tiến trình tải lên --}}
                <div wire:loading wire:target="uploads,saveUploads"
                     class="mb-3 flex items-center gap-3 rounded-admin border border-brand-red bg-brand-red-tint px-4 py-3">
                    <svg class="h-5 w-5 shrink-0 animate-spin text-brand-red" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/>
                        <path d="M12 2a10 10 0 0110 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                    <span class="text-sm font-semibold text-brand-red">Đang tải file lên...</span>
                </div>

                @error('uploads.*')
                    <div class="mb-3 rounded-admin border border-brand-red bg-brand-red-tint px-4 py-3 text-sm font-semibold text-brand-red">
                        {{ $message }}
                    </div>
                @enderror

                <div class="admin-panel">
                    <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
                        <div class="min-w-[220px] flex-1">
                            <input type="search" wire:model.live.debounce.400ms="search" class="admin-input"
                                   placeholder="Tìm file trong toàn bộ kho...">
                        </div>

                        <select wire:model.live="kindFilter" class="admin-input w-auto min-w-[160px]">
                            <option value="">Mọi loại file</option>
                            <option value="document">Văn bản</option>
                            <option value="spreadsheet">Bảng tính</option>
                            <option value="presentation">Trình chiếu</option>
                            <option value="pdf">PDF</option>
                            <option value="image">Hình ảnh</option>
                            <option value="video">Video</option>
                            <option value="archive">File nén</option>
                        </select>
                    </div>

                    @if ($search !== '')
                        <div class="border-b border-brand-line bg-brand-soft px-4 py-2.5 text-xs text-brand-muted">
                            Đang tìm trong toàn bộ kho — kết quả hiển thị kèm vị trí thư mục.
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Tên</th>
                                    @if ($search !== '')
                                        <th class="w-48">Vị trí</th>
                                    @endif
                                    <th class="w-28">Dung lượng</th>
                                    <th class="w-36">Người tải lên</th>
                                    <th class="w-32">Ngày tải</th>
                                    <th class="w-32 text-right">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                {{-- Thư mục con hiện trước file, như Drive --}}
                                @foreach ($folders as $sub)
                                    <tr wire:key="folder-{{ $sub->id }}">
                                        <td>
                                            <button type="button" wire:click="openFolder({{ $sub->id }})"
                                                    class="flex items-center gap-2.5 text-left">
                                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-admin bg-state-warning-tint text-state-warning">
                                                    @svg('heroicon-o-folder', 'h-4 w-4')
                                                </span>
                                                <span class="min-w-0">
                                                    <span class="block truncate font-bold hover:text-brand-red">{{ $sub->name }}</span>
                                                    <span class="block text-[11px] text-brand-muted">
                                                        {{ $sub->children_count }} thư mục · {{ $sub->files_count }} file
                                                        @if ($sub->is_system)
                                                            · <span class="text-state-warning">hệ thống</span>
                                                        @endif
                                                    </span>
                                                </span>
                                            </button>
                                        </td>

                                        @if ($search !== '')
                                            <td></td>
                                        @endif

                                        <td class="text-brand-muted">—</td>
                                        <td class="text-brand-muted">{{ $sub->createdBy?->name ?? '—' }}</td>
                                        <td class="text-xs text-brand-muted">{{ $sub->created_at?->format('d/m/Y') }}</td>
                                        <td>
                                            <div class="flex items-center justify-end gap-1.5">
                                                <x-admin.icon-button icon="heroicon-o-pencil-square" label="Đổi tên thư mục"
                                                                     wire:click="editFolder({{ $sub->id }})" />
                                                @unless ($sub->is_system)
                                                    <x-admin.icon-button icon="heroicon-o-arrows-right-left" label="Di chuyển thư mục"
                                                                         wire:click="moveFolderTo({{ $sub->id }})" />
                                                    <x-admin.icon-button icon="heroicon-o-trash" label="Xóa thư mục"
                                                                         variant="danger"
                                                                         wire:click="deleteFolder({{ $sub->id }})" />
                                                @endunless
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach

                                @forelse ($files as $file)
                                    <tr wire:key="file-{{ $file->id }}">
                                        <td>
                                            <div class="flex items-center gap-2.5">
                                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-admin bg-brand-soft text-brand-muted">
                                                    @svg($file->icon(), 'h-4 w-4')
                                                </span>
                                                <div class="min-w-0">
                                                    <div class="truncate font-bold">{{ $file->name }}</div>
                                                    <div class="truncate text-[11px] uppercase text-brand-muted">
                                                        {{ $file->extension ?? $file->mime_type }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        @if ($search !== '')
                                            <td class="text-xs text-brand-muted">
                                                @if ($file->folder)
                                                    <button type="button" wire:click="openFolder({{ $file->folder->id }})"
                                                            class="truncate hover:text-brand-red">
                                                        {{ $file->folder->name }}
                                                    </button>
                                                @else
                                                    Thư mục gốc
                                                @endif
                                            </td>
                                        @endif

                                        <td class="text-brand-muted">{{ $file->humanSize() }}</td>
                                        <td class="truncate text-brand-muted">{{ $file->uploadedBy?->name ?? '—' }}</td>
                                        <td class="text-xs text-brand-muted">{{ $file->created_at?->format('d/m/Y') }}</td>
                                        <td>
                                            <div class="flex items-center justify-end gap-1.5">
                                                <x-admin.icon-button icon="heroicon-o-arrow-down-tray" label="Tải xuống"
                                                                     wire:click="download({{ $file->id }})" />
                                                <x-admin.icon-button icon="heroicon-o-pencil-square" label="Đổi tên file"
                                                                     wire:click="renameFile({{ $file->id }})" />
                                                <x-admin.icon-button icon="heroicon-o-arrows-right-left" label="Di chuyển file"
                                                                     wire:click="moveFile({{ $file->id }})" />
                                                <x-admin.icon-button icon="heroicon-o-trash" label="Xóa file"
                                                                     variant="danger"
                                                                     wire:click="deleteFile({{ $file->id }})"
                                                                     wire:confirm="Xóa file này?" />
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    @if ($folders->isEmpty())
                                        <tr>
                                            <td colspan="{{ $search !== '' ? 6 : 5 }}" class="py-12 text-center">
                                                @svg('heroicon-o-folder-open', 'mx-auto h-10 w-10 text-brand-line')
                                                <p class="mt-2 font-semibold">
                                                    {{ $search !== '' ? 'Không tìm thấy file nào.' : 'Thư mục trống' }}
                                                </p>
                                                @if ($search === '')
                                                    <p class="mt-1 text-sm text-brand-muted">
                                                        Kéo file từ máy tính thả vào đây để tải lên.
                                                    </p>
                                                @endif
                                            </td>
                                        </tr>
                                    @endif
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($files->hasPages())
                        <div class="border-t border-brand-line p-4">{{ $files->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Modal thư mục --}}
    @if ($showFolderModal)
        <x-admin.modal wireModel="showFolderModal"
                       :title="$editingFolderId ? 'Đổi tên thư mục' : 'Tạo thư mục mới'"
                       maxWidth="max-w-lg">
            <form wire:submit="saveFolder" id="folder-form" class="grid gap-4">
                @unless ($editingFolderId)
                    <p class="rounded-admin border border-brand-line bg-brand-soft p-3 text-xs text-brand-muted">
                        Thư mục sẽ được tạo trong: <strong class="text-brand-ink">{{ $currentFolder?->name ?? 'Thư mục gốc' }}</strong>
                    </p>
                @endunless

                <div>
                    <label for="f_name" class="admin-label">Tên thư mục</label>
                    <input id="f_name" type="text" wire:model="folderName" class="admin-input" autofocus>
                    @error('folderName') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="f_desc" class="admin-label">Mô tả</label>
                    <input id="f_desc" type="text" wire:model="folderDescription" class="admin-input">
                </div>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showFolderModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="folder-form" class="admin-btn">
                    {{ $editingFolderId ? 'Lưu' : 'Tạo thư mục' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Modal đổi tên file --}}
    @if ($showRenameModal)
        <x-admin.modal wireModel="showRenameModal" title="Đổi tên file" maxWidth="max-w-lg">
            <form wire:submit="saveFileName" id="rename-form">
                <label for="fl_name" class="admin-label">Tên file</label>
                <input id="fl_name" type="text" wire:model="fileName" class="admin-input" autofocus>
                @error('fileName') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showRenameModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="rename-form" class="admin-btn">Lưu</button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Modal di chuyển --}}
    @if ($showMoveModal)
        <x-admin.modal wireModel="showMoveModal" title="Di chuyển đến thư mục" maxWidth="max-w-lg">
            <div class="grid gap-3">
                <label class="flex cursor-pointer items-center gap-2.5 rounded-admin border border-brand-line p-3 text-sm font-medium hover:bg-brand-soft">
                    <input type="radio" wire:model="moveTargetId" value=""
                           class="h-4 w-4 border-brand-line text-brand-red focus:ring-brand-red">
                    @svg('heroicon-o-home', 'h-4 w-4 text-brand-muted')
                    Thư mục gốc
                </label>

                <div class="max-h-64 overflow-y-auto rounded-admin border border-brand-line">
                    @foreach ($moveTargets as $target)
                        <label class="flex cursor-pointer items-center gap-2.5 border-b border-brand-line p-3 text-sm font-medium last:border-b-0 hover:bg-brand-soft"
                               style="padding-left: {{ 12 + $target->depth * 16 }}px">
                            <input type="radio" wire:model="moveTargetId" value="{{ $target->id }}"
                                   class="h-4 w-4 border-brand-line text-brand-red focus:ring-brand-red">
                            @svg('heroicon-o-folder', 'h-4 w-4 text-state-warning')
                            <span class="truncate">{{ $target->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <x-slot:footer>
                <button type="button" wire:click="$set('showMoveModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="button" wire:click="confirmMove" class="admin-btn">Di chuyển</button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
