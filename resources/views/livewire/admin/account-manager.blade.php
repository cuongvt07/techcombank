<div>
    <x-admin.page-header title="Quản lý tài khoản"
                         subtitle="Tạo, phân vai trò và khóa tài khoản đăng nhập của nhân viên.">
        <x-slot:actions>
            <button type="button" wire:click="create" class="admin-btn">+ Tạo tài khoản</button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="admin-panel">
        {{-- Thanh lọc đặt ngay trên bảng: filter/search/sort đi kèm dữ liệu (spec 6.4) --}}
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
            <div class="min-w-[240px] flex-1">
                <input type="search"
                       wire:model.live.debounce.400ms="search"
                       class="admin-input"
                       placeholder="Tìm theo tên, email hoặc mã nhân viên...">
            </div>

            <select wire:model.live="statusFilter" class="admin-input w-auto min-w-[160px]">
                <option value="">Mọi trạng thái</option>
                <option value="active">Đang hoạt động</option>
                <option value="locked">Đã khóa</option>
                <option value="disabled">Vô hiệu hóa</option>
            </select>

            <select wire:model.live="roleFilter" class="admin-input w-auto min-w-[180px]">
                <option value="">Mọi vai trò</option>
                @foreach ($roles as $r)
                    <option value="{{ $r->name }}">
                        {{ \App\Enums\RoleName::tryFrom($r->name)?->label() ?? $r->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Nhân viên</th>
                        <th>Mã NV</th>
                        <th>Phòng ban</th>
                        <th>Vai trò</th>
                        <th>Đăng nhập gần nhất</th>
                        <th>Trạng thái</th>
                        <th class="w-24 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td>
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-red-tint text-xs font-semibold text-brand-red">
                                        {{ mb_substr($user->employee?->full_name ?? $user->name, -1) }}
                                    </span>
                                    <div class="min-w-0">
                                        <div class="truncate font-bold">{{ $user->employee?->full_name ?? $user->name }}</div>
                                        <div class="truncate text-xs text-brand-muted">{{ $user->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="font-mono text-xs">{{ $user->employee?->employee_code ?? '—' }}</td>
                            <td class="text-brand-muted">{{ $user->employee?->department?->name ?? '—' }}</td>
                            <td>
                                @foreach ($user->roles as $role)
                                    <span class="tag">{{ \App\Enums\RoleName::tryFrom($role->name)?->label() ?? $role->name }}</span>
                                @endforeach
                            </td>
                            <td class="text-xs text-brand-muted">
                                {{ $user->last_login_at?->format('d/m/Y H:i') ?? 'Chưa đăng nhập' }}
                            </td>
                            <td>
                                @php
                                    $statusMap = [
                                        'active' => ['tag-green', 'Hoạt động'],
                                        'locked' => ['tag-red', 'Đã khóa'],
                                        'disabled' => ['', 'Vô hiệu'],
                                    ];
                                    [$cls, $label] = $statusMap[$user->status] ?? ['', $user->status];
                                @endphp
                                <span class="tag {{ $cls }}">{{ $label }}</span>
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa tài khoản"
                                                         wire:click="edit({{ $user->id }})" />

                                    @if ($user->id !== auth()->id())
                                        @if ($user->status === 'active')
                                            <x-admin.icon-button icon="heroicon-o-lock-closed" label="Khóa tài khoản"
                                                                 variant="danger"
                                                                 wire:click="toggleStatus({{ $user->id }})"
                                                                 wire:confirm="Khóa tài khoản này?" />
                                        @else
                                            <x-admin.icon-button icon="heroicon-o-lock-open" label="Mở khóa tài khoản"
                                                                 wire:click="toggleStatus({{ $user->id }})"
                                                                 wire:confirm="Mở khóa tài khoản này?" />
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-brand-muted">Không tìm thấy tài khoản nào.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="border-t border-brand-line p-4">{{ $users->links() }}</div>
        @endif
    </div>

    {{-- Form tạo/sửa: nghiệp vụ đơn giản nên dùng modal, không biến thành wizard (spec 6.4) --}}
    @if ($showModal)
        <x-admin.modal :title="$editingId ? 'Sửa tài khoản' : 'Tạo tài khoản mới'">
            <form wire:submit="save" id="account-form" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="full_name" class="admin-label">Họ và tên</label>
                    <input id="full_name" type="text" wire:model="full_name" class="admin-input">
                    @error('full_name') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="admin-label">Email đăng nhập</label>
                    <input id="email" type="email" wire:model="email" class="admin-input">
                    @error('email') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="employee_code" class="admin-label">Mã nhân viên</label>
                    <input id="employee_code" type="text" wire:model="employee_code" class="admin-input">
                    @error('employee_code') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="admin-label">
                        Mật khẩu {{ $editingId ? '(để trống nếu giữ nguyên)' : '' }}
                    </label>
                    <input id="password" type="password" wire:model="password" class="admin-input" autocomplete="new-password">
                    @error('password') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="role" class="admin-label">Vai trò</label>
                    <select id="role" wire:model="role" class="admin-input">
                        @foreach ($roles as $r)
                            <option value="{{ $r->name }}">
                                {{ \App\Enums\RoleName::tryFrom($r->name)?->label() ?? $r->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('role') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="status" class="admin-label">Trạng thái</label>
                    <select id="status" wire:model="status" class="admin-input">
                        <option value="active">Đang hoạt động</option>
                        <option value="locked">Đã khóa</option>
                        <option value="disabled">Vô hiệu hóa</option>
                    </select>
                </div>

                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium sm:col-span-2">
                    <input type="checkbox" wire:model="must_change_password"
                           class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                    Bắt buộc đổi mật khẩu ở lần đăng nhập đầu tiên
                </label>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="account-form" class="admin-btn" wire:loading.attr="disabled">
                    {{ $editingId ? 'Lưu thay đổi' : 'Tạo tài khoản' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
