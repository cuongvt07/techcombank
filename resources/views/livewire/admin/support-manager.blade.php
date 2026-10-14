<div>
    <x-admin.page-header title="Phiếu hỗ trợ"
                         subtitle="Yêu cầu nhân viên gửi từ mục Hỗ trợ — nhận xử lý, phản hồi và đóng phiếu." />

    {{-- Bốn chỉ số: phiếu chưa xử lý tô vàng để nhận ra việc còn tồn --}}
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <button type="button" wire:click="$set('statusFilter', 'new')" class="admin-panel p-4 text-left transition-colors hover:border-brand-red">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-muted">Mới, chưa nhận</p>
            <strong class="mt-1 block text-2xl font-bold {{ $stats['new'] > 0 ? 'text-state-warning' : '' }}">{{ $stats['new'] }}</strong>
        </button>
        <button type="button" wire:click="$set('statusFilter', 'in_progress')" class="admin-panel p-4 text-left transition-colors hover:border-brand-red">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-muted">Đang xử lý</p>
            <strong class="mt-1 block text-2xl font-bold">{{ $stats['in_progress'] }}</strong>
        </button>
        <button type="button" wire:click="$set('statusFilter', 'resolved')" class="admin-panel p-4 text-left transition-colors hover:border-brand-red">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-muted">Đã giải quyết</p>
            <strong class="mt-1 block text-2xl font-bold text-state-success">{{ $stats['resolved'] }}</strong>
        </button>
        <button type="button" wire:click="$set('priorityFilter', 'high')" class="admin-panel p-4 text-left transition-colors hover:border-brand-red">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-muted">Gấp còn mở</p>
            <strong class="mt-1 block text-2xl font-bold {{ $stats['high'] > 0 ? 'text-brand-red' : '' }}">{{ $stats['high'] }}</strong>
        </button>
    </div>

    <div class="admin-panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
            <div class="min-w-[240px] flex-1">
                <input type="search" wire:model.live.debounce.400ms="search" class="admin-input"
                       placeholder="Tìm theo mã phiếu, tiêu đề, tên hoặc mã nhân viên...">
            </div>

            <select wire:model.live="statusFilter" class="admin-input w-auto min-w-[160px]">
                <option value="open">Còn mở</option>
                <option value="">Tất cả</option>
                <option value="new">Mới</option>
                <option value="in_progress">Đang xử lý</option>
                <option value="resolved">Đã giải quyết</option>
                <option value="closed">Đã đóng</option>
            </select>

            <select wire:model.live="priorityFilter" class="admin-input w-auto min-w-[140px]">
                <option value="">Mọi mức ưu tiên</option>
                <option value="high">Gấp</option>
                <option value="normal">Bình thường</option>
                <option value="low">Thấp</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Mã phiếu</th>
                        <th class="min-w-[260px]">Nội dung</th>
                        <th>Nhân viên</th>
                        <th>Ưu tiên</th>
                        <th>Trạng thái</th>
                        <th>Người xử lý</th>
                        <th>Gửi lúc</th>
                        <th class="w-28 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requests as $item)
                        <tr wire:key="sr-{{ $item->id }}">
                            <td class="whitespace-nowrap font-mono text-xs">{{ $item->ticket_no }}</td>
                            <td>
                                <button type="button" wire:click="view({{ $item->id }})" class="block text-left font-bold hover:text-brand-red">
                                    {{ $item->subject }}
                                </button>
                                <span class="mt-0.5 block text-xs text-brand-muted">{{ $item->topic }}</span>
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <x-user-avatar :employee="$item->employee" size="h-7 w-7" text="text-[10px]" />
                                    <div class="min-w-0">
                                        <span class="block truncate text-sm">{{ $item->employee?->full_name ?? '—' }}</span>
                                        <span class="block truncate text-xs text-brand-muted">{{ $item->employee?->department?->name }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if ($item->priority === 'high')
                                    <span class="tag tag-red">Gấp</span>
                                @elseif ($item->priority === 'low')
                                    <span class="tag">Thấp</span>
                                @else
                                    <span class="tag">Bình thường</span>
                                @endif
                            </td>
                            <td>
                                @switch($item->status)
                                    @case('new') <span class="tag tag-amber">Mới</span> @break
                                    @case('in_progress') <span class="tag tag-amber">Đang xử lý</span> @break
                                    @case('resolved') <span class="tag tag-green">Đã giải quyết</span> @break
                                    @default <span class="tag">Đã đóng</span>
                                @endswitch
                            </td>
                            <td class="text-xs">{{ $item->assignedTo?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap text-xs text-brand-muted">{{ $item->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($item->status === 'new')
                                        <x-admin.icon-button icon="heroicon-o-hand-raised" label="Nhận xử lý" variant="primary"
                                                             wire:click="take({{ $item->id }})" />
                                    @endif

                                    <x-admin.icon-button icon="heroicon-o-chat-bubble-left-ellipsis" label="Xem và phản hồi"
                                                         wire:click="view({{ $item->id }})" />

                                    @if (in_array($item->status, ['resolved', 'closed'], true))
                                        <x-admin.icon-button icon="heroicon-o-arrow-path" label="Mở lại"
                                                             wire:click="reopen({{ $item->id }})" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-10 text-center text-sm text-brand-muted">
                                Không có phiếu nào khớp bộ lọc.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($requests->hasPages())
            <div class="border-t border-brand-line p-4">{{ $requests->links() }}</div>
        @endif
    </div>

    {{-- Chi tiết + phản hồi --}}
    <x-admin.modal :title="$viewing ? 'Phiếu ' . $viewing->ticket_no : 'Chi tiết phiếu'" max-width="max-w-3xl">
        @if ($viewing)
            <div class="grid gap-4">
                <div class="flex items-start gap-3 rounded-admin border border-brand-line p-3">
                    <x-user-avatar :employee="$viewing->employee" size="h-10 w-10" />
                    <div class="min-w-0 flex-1">
                        <p class="font-bold">{{ $viewing->employee?->full_name }}</p>
                        <p class="text-xs text-brand-muted">
                            {{ $viewing->employee?->employee_code }} · {{ $viewing->employee?->department?->name }}
                        </p>
                    </div>
                    <span class="text-xs text-brand-muted">{{ $viewing->created_at->format('d/m/Y H:i') }}</span>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-muted">{{ $viewing->topic }}</p>
                    <h3 class="mt-1 text-base font-bold">{{ $viewing->subject }}</h3>
                    <p class="mt-2 whitespace-pre-line text-sm leading-relaxed">{{ $viewing->content }}</p>
                </div>

                <div class="grid gap-4 border-t border-brand-line pt-4 sm:grid-cols-[1fr_auto] sm:items-end">
                    <div>
                        <label for="sr-assign" class="admin-label">Người xử lý</label>
                        <select id="sr-assign" wire:model="assigned_to" class="admin-input">
                            <option value="">— Chưa gán —</option>
                            @foreach ($admins as $admin)
                                <option value="{{ $admin->id }}">{{ $admin->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" wire:click="assign" class="admin-btn-secondary">Cập nhật người xử lý</button>
                </div>

                <div>
                    <label for="sr-resolution" class="admin-label">Phản hồi cho nhân viên</label>
                    <textarea id="sr-resolution" wire:model="resolution" rows="4" class="admin-input"
                              placeholder="Nội dung giải đáp — nhân viên đọc được ở mục Hỗ trợ."></textarea>
                    @error('resolution') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        <x-slot:footer>
            <button type="button" wire:click="$set('showModal', false)" class="admin-btn-secondary">Đóng</button>
            @if ($viewing && $viewing->status !== 'closed')
                <button type="button" wire:click="close({{ $viewing->id }})" class="admin-btn-secondary">Đóng phiếu</button>
            @endif
            <button type="button" wire:click="resolve" class="admin-btn">Giải quyết &amp; gửi phản hồi</button>
        </x-slot:footer>
    </x-admin.modal>
</div>
