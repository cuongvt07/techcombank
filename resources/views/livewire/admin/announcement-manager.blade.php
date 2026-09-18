<div>
    <x-admin.page-header title="Sự kiện & thông báo"
                         subtitle="Gửi sự kiện tới toàn công ty hoặc riêng từng nhân viên.">
        <x-slot:actions>
            <button type="button" wire:click="create" class="admin-btn">+ Thêm sự kiện</button>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Bốn chỉ số nhanh --}}
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="admin-panel p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-muted">Tổng số</p>
            <strong class="mt-1 block text-2xl font-bold">{{ $stats['total'] }}</strong>
        </div>
        <div class="admin-panel p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-muted">Đang hiển thị</p>
            <strong class="mt-1 block text-2xl font-bold text-state-success">{{ $stats['published'] }}</strong>
        </div>
        <div class="admin-panel p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-muted">Sắp diễn ra</p>
            <strong class="mt-1 block text-2xl font-bold">{{ $stats['upcoming'] }}</strong>
        </div>
        <div class="admin-panel p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-muted">Còn nháp</p>
            {{-- Nháp là việc chưa xong, tô vàng cho dễ nhận ra --}}
            <strong class="mt-1 block text-2xl font-bold {{ $stats['draft'] > 0 ? 'text-state-warning' : '' }}">
                {{ $stats['draft'] }}
            </strong>
        </div>
    </div>

    <div class="admin-panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
            <div class="min-w-[240px] flex-1">
                <input type="search" wire:model.live.debounce.400ms="search" class="admin-input"
                       placeholder="Tìm theo tiêu đề, nội dung hoặc địa điểm...">
            </div>

            <select wire:model.live="scopeFilter" class="admin-input w-auto min-w-[170px]">
                <option value="">Mọi phạm vi</option>
                <option value="all">Toàn công ty</option>
                <option value="personal">Riêng cá nhân</option>
            </select>

            <select wire:model.live="typeFilter" class="admin-input w-auto min-w-[150px]">
                <option value="">Mọi loại</option>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="min-w-[260px]">Sự kiện</th>
                        <th>Phạm vi</th>
                        <th>Loại</th>
                        <th>Thời gian</th>
                        <th>Địa điểm</th>
                        <th>Trạng thái</th>
                        <th class="w-32 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($announcements as $item)
                        <tr wire:key="ann-{{ $item->id }}">
                            <td>
                                <span class="block font-bold">{{ $item->title }}</span>
                                <span class="mt-0.5 block truncate text-xs text-brand-muted" style="max-width: 340px">
                                    {{ $item->content }}
                                </span>
                            </td>

                            <td>
                                @if ($item->isPersonal())
                                    <span class="tag tag-red">Riêng</span>
                                    <span class="mt-1 block text-xs text-brand-muted">
                                        {{ $item->employee?->full_name ?? '—' }}
                                    </span>
                                @else
                                    <span class="tag">Toàn công ty</span>
                                @endif
                            </td>

                            <td><span class="tag">{{ $item->typeLabel() }}</span></td>

                            <td class="whitespace-nowrap text-xs">
                                @if ($item->starts_at)
                                    <span class="block font-semibold">{{ $item->starts_at->format('d/m/Y H:i') }}</span>
                                    @if ($item->ends_at)
                                        <span class="block text-brand-muted">
                                            đến {{ $item->ends_at->format('d/m/Y H:i') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-brand-muted">—</span>
                                @endif
                            </td>

                            <td class="text-xs">{{ $item->location ?: '—' }}</td>

                            <td>
                                @if (! $item->published_at)
                                    <span class="tag tag-amber">Nháp</span>
                                @elseif ($item->hasEnded())
                                    <span class="tag">Đã qua</span>
                                @elseif ($item->isHappeningNow())
                                    <span class="tag tag-green">Đang diễn ra</span>
                                @else
                                    <span class="tag tag-green">Đang hiển thị</span>
                                @endif
                            </td>

                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($item->published_at)
                                        <x-admin.icon-button icon="heroicon-o-eye-slash"
                                                             label="Gỡ khỏi site người dùng"
                                                             wire:click="unpublish({{ $item->id }})" />
                                    @else
                                        <x-admin.icon-button icon="heroicon-o-paper-airplane"
                                                             label="Phát hành ngay"
                                                             variant="primary"
                                                             wire:click="publish({{ $item->id }})" />
                                    @endif

                                    <x-admin.icon-button icon="heroicon-o-pencil-square"
                                                         label="Sửa"
                                                         wire:click="edit({{ $item->id }})" />

                                    <x-admin.icon-button icon="heroicon-o-trash"
                                                         label="Xóa"
                                                         variant="danger"
                                                         wire:click="delete({{ $item->id }})"
                                                         wire:confirm="Xóa sự kiện này?" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-10 text-center text-sm text-brand-muted">
                                Chưa có sự kiện nào. Bấm "Thêm sự kiện" để tạo mới.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($announcements->hasPages())
            <div class="border-t border-brand-line p-4">{{ $announcements->links() }}</div>
        @endif
    </div>

    {{-- Form tạo/sửa --}}
    <x-admin.modal :title="$editingId ? 'Sửa sự kiện' : 'Thêm sự kiện'" max-width="max-w-3xl">
        <form wire:submit="save" class="grid gap-4">
            <div>
                <label for="ann-title" class="admin-label">Tiêu đề</label>
                <input id="ann-title" type="text" wire:model="title" class="admin-input"
                       placeholder="Ví dụ: Họp đào tạo quy trình mới">
                @error('title') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="ann-content" class="admin-label">Nội dung</label>
                <textarea id="ann-content" wire:model="content" rows="4" class="admin-input"
                          placeholder="Mô tả chi tiết sự kiện..."></textarea>
                @error('content') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
            </div>

            {{-- Phạm vi gán: hai lựa chọn, chọn riêng mới hiện ô chọn nhân viên --}}
            <div class="rounded-admin border border-brand-line p-4">
                <span class="admin-label">Gửi cho ai</span>

                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-admin border border-brand-line p-3 transition-colors hover:border-brand-red">
                        <input type="radio" wire:model.live="scope" value="all"
                               class="mt-0.5 h-4 w-4 border-brand-line text-brand-red focus:ring-brand-red">
                        <span>
                            <span class="block text-sm font-semibold">Toàn công ty</span>
                            <span class="mt-0.5 block text-xs text-brand-muted">Mọi nhân viên đều thấy</span>
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-start gap-2.5 rounded-admin border border-brand-line p-3 transition-colors hover:border-brand-red">
                        <input type="radio" wire:model.live="scope" value="personal"
                               class="mt-0.5 h-4 w-4 border-brand-line text-brand-red focus:ring-brand-red">
                        <span>
                            <span class="block text-sm font-semibold">Riêng một nhân viên</span>
                            <span class="mt-0.5 block text-xs text-brand-muted">Hiển thị ưu tiên trên đầu</span>
                        </span>
                    </label>
                </div>

                @if ($scope === 'personal')
                    <div class="mt-3">
                        <label for="ann-employee" class="admin-label">Nhân viên nhận</label>
                        <select id="ann-employee" wire:model="employee_id" class="admin-input">
                            <option value="">— Chọn nhân viên —</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">
                                    {{ $employee->full_name }} ({{ $employee->employee_code }})
                                </option>
                            @endforeach
                        </select>
                        @error('employee_id') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="ann-starts" class="admin-label">Bắt đầu</label>
                    <input id="ann-starts" type="datetime-local" wire:model="starts_at" class="admin-input">
                    @error('starts_at') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="ann-ends" class="admin-label">Kết thúc</label>
                    <input id="ann-ends" type="datetime-local" wire:model="ends_at" class="admin-input">
                    @error('ends_at') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="ann-location" class="admin-label">Địa điểm</label>
                    <input id="ann-location" type="text" wire:model="location" class="admin-input"
                           placeholder="Phòng họp tầng 5 / link họp trực tuyến">
                    @error('location') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="ann-type" class="admin-label">Loại</label>
                    <select id="ann-type" wire:model="type" class="admin-input">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="grid gap-4 border-t border-brand-line pt-4 sm:grid-cols-2">
                <div>
                    <label for="ann-published" class="admin-label">Phát hành lúc</label>
                    <input id="ann-published" type="datetime-local" wire:model="published_at" class="admin-input">
                    <p class="mt-1 text-xs text-brand-muted">Để trống = lưu nháp, chưa hiện với nhân viên.</p>
                    @error('published_at') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="ann-expires" class="admin-label">Hết hiệu lực</label>
                    <input id="ann-expires" type="datetime-local" wire:model="expires_at" class="admin-input">
                    <p class="mt-1 text-xs text-brand-muted">Để trống = hiển thị vô thời hạn.</p>
                    @error('expires_at') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>
            </div>
        </form>

        <x-slot:footer>
            <button type="button" wire:click="$set('showModal', false)" class="admin-btn-secondary">Hủy</button>
            <button type="button" wire:click="save" class="admin-btn">
                {{ $editingId ? 'Lưu thay đổi' : 'Tạo sự kiện' }}
            </button>
        </x-slot:footer>
    </x-admin.modal>
</div>
