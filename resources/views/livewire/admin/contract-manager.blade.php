<div>
    <x-admin.page-header title="Quản lý hợp đồng"
                         subtitle="Loại hợp đồng, thời hạn hiệu lực và cảnh báo sắp hết hạn.">
        <x-slot:actions>
            <button type="button" wire:click="refreshStatuses" class="admin-btn-secondary">Đồng bộ trạng thái</button>
            <button type="button" wire:click="create" class="admin-btn">+ Thêm hợp đồng</button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($expiringCount > 0)
        {{-- Cảnh báo hợp đồng sắp hết hạn — việc cần xử lý, dùng đỏ có chủ đích --}}
        <div class="mb-4 flex items-center justify-between gap-4 rounded-admin border border-state-warning bg-state-warning-tint px-4 py-3">
            <p class="text-sm font-semibold text-state-warning">
                Có {{ $expiringCount }} hợp đồng sắp đến hạn cần xử lý.
            </p>
            <button type="button" wire:click="$toggle('onlyExpiring')" class="admin-btn-secondary !min-h-[34px] !text-xs">
                {{ $onlyExpiring ? 'Xem tất cả' : 'Chỉ xem hợp đồng sắp hết hạn' }}
            </button>
        </div>
    @endif

    <div class="admin-panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
            <div class="min-w-[240px] flex-1">
                <input type="search" wire:model.live.debounce.400ms="search" class="admin-input"
                       placeholder="Tìm theo số hợp đồng, tên hoặc mã nhân viên...">
            </div>

            <select wire:model.live="statusFilter" class="admin-input w-auto min-w-[180px]">
                <option value="">Mọi trạng thái</option>
                <option value="draft">Nháp</option>
                <option value="active">Đang hiệu lực</option>
                <option value="expiring">Sắp hết hạn</option>
                <option value="expired">Đã hết hạn</option>
                <option value="terminated">Đã chấm dứt</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Số hợp đồng</th>
                        <th>Nhân viên</th>
                        <th>Loại</th>
                        <th>Hiệu lực</th>
                        <th>Hết hạn</th>
                        <th>Còn lại</th>
                        <th>Trạng thái</th>
                        <th class="w-20 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($contracts as $contract)
                        @php $days = $contract->daysUntilExpiry(); @endphp
                        <tr wire:key="ct-{{ $contract->id }}">
                            <td class="font-mono text-xs font-bold">{{ $contract->contract_no }}</td>
                            <td>
                                <div class="min-w-0">
                                    <div class="truncate font-bold">{{ $contract->employee->full_name }}</div>
                                    <div class="truncate text-xs text-brand-muted">
                                        {{ $contract->employee->department?->name ?? '—' }}
                                    </div>
                                </div>
                            </td>
                            <td class="text-brand-muted">{{ $contract->contractType->name }}</td>
                            <td>{{ $contract->effective_from?->format('d/m/Y') }}</td>
                            <td>{{ $contract->effective_to?->format('d/m/Y') ?? 'Không thời hạn' }}</td>
                            <td>
                                @if ($days === null)
                                    <span class="text-brand-muted">—</span>
                                @elseif ($days < 0)
                                    <span class="font-bold text-brand-red">Quá {{ abs($days) }} ngày</span>
                                @elseif ($contract->isExpiring())
                                    <span class="font-bold text-state-warning">{{ $days }} ngày</span>
                                @else
                                    <span class="text-brand-muted">{{ $days }} ngày</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $map = [
                                        'draft' => ['', 'Nháp'],
                                        'active' => ['tag-green', 'Hiệu lực'],
                                        'expiring' => ['tag-amber', 'Sắp hết hạn'],
                                        'expired' => ['tag-red', 'Hết hạn'],
                                        'terminated' => ['', 'Chấm dứt'],
                                    ];
                                    [$cls, $label] = $map[$contract->status] ?? ['', $contract->status];
                                @endphp
                                <span class="tag {{ $cls }}">{{ $label }}</span>
                            </td>
                            <td>
                                <div class="flex justify-end">
                                    <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa hợp đồng"
                                                         wire:click="edit({{ $contract->id }})" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-brand-muted">Không tìm thấy hợp đồng nào.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($contracts->hasPages())
            <div class="border-t border-brand-line p-4">{{ $contracts->links() }}</div>
        @endif
    </div>

    @if ($showModal)
        <x-admin.modal :title="$editingId ? 'Sửa hợp đồng' : 'Thêm hợp đồng'" maxWidth="max-w-3xl">
            <form wire:submit="save" id="contract-form" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="c_emp" class="admin-label">Nhân viên</label>
                    <select id="c_emp" wire:model="employee_id" class="admin-input">
                        <option value="">— Chọn —</option>
                        @foreach ($employees as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->full_name }} ({{ $emp->employee_code }})</option>
                        @endforeach
                    </select>
                    @error('employee_id') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="c_type" class="admin-label">Loại hợp đồng</label>
                    <select id="c_type" wire:model="contract_type_id" class="admin-input">
                        <option value="">— Chọn —</option>
                        @foreach ($contractTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                    @error('contract_type_id') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="c_no" class="admin-label">Số hợp đồng</label>
                    <input id="c_no" type="text" wire:model="contract_no" class="admin-input">
                    @error('contract_no') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="c_signed" class="admin-label">Ngày ký</label>
                    <input id="c_signed" type="date" wire:model="signed_at" class="admin-input">
                </div>

                <div>
                    <label for="c_from" class="admin-label">Hiệu lực từ</label>
                    <input id="c_from" type="date" wire:model="effective_from" class="admin-input">
                    @error('effective_from') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="c_to" class="admin-label">Hết hạn (để trống nếu không xác định)</label>
                    <input id="c_to" type="date" wire:model="effective_to" class="admin-input">
                    @error('effective_to') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="c_status" class="admin-label">Trạng thái</label>
                    <select id="c_status" wire:model="status" class="admin-input">
                        <option value="draft">Nháp</option>
                        <option value="active">Đang hiệu lực</option>
                        <option value="expiring">Sắp hết hạn</option>
                        <option value="expired">Đã hết hạn</option>
                        <option value="terminated">Đã chấm dứt</option>
                    </select>
                </div>

                <div>
                    <label for="c_alert" class="admin-label">Cảnh báo trước (ngày)</label>
                    <input id="c_alert" type="number" wire:model="alert_before_days" class="admin-input"
                           placeholder="Mặc định 30">
                    @error('alert_before_days') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="c_file" class="admin-label">File hợp đồng (PDF/ảnh/Word, tối đa 10MB)</label>
                    <input id="c_file" type="file" wire:model="file"
                           class="admin-input !h-auto !py-2 file:mr-3 file:rounded-admin file:border-0 file:bg-brand-soft file:px-3 file:py-1.5 file:text-xs file:font-bold">
                    @error('file') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    <div wire:loading wire:target="file" class="mt-1 text-xs font-semibold text-brand-muted">Đang tải file lên...</div>
                </div>

                <div class="sm:col-span-2">
                    <label for="c_note" class="admin-label">Ghi chú</label>
                    <textarea id="c_note" wire:model="note" rows="2" class="admin-input !h-auto py-2.5"></textarea>
                </div>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="contract-form" class="admin-btn" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? 'Lưu thay đổi' : 'Tạo hợp đồng' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
