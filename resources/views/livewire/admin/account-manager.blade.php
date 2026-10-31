<div>
    <x-admin.page-header title="Quản lý tài khoản"
                         subtitle="Tạo, phân vai trò và khóa tài khoản đăng nhập của nhân viên.">
        <x-slot:actions>
            @can('accounts.create')
            <button type="button" wire:click="create" class="admin-btn">+ Tạo tài khoản</button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="admin-panel">
        {{-- Thanh lọc đặt ngay trên bảng: filter/search/sort đi kèm dữ liệu (spec 6.4) --}}
        <div class="flex flex-wrap items-end gap-3 border-b border-brand-line p-4">
            <div class="min-w-[240px] flex-1">
                <label for="f-account-manager-search-0" class="admin-label">Tìm kiếm</label>
                <input id="f-account-manager-search-0" type="search"
                       wire:model="search" wire:keydown.enter="applySearch"
                       class="admin-input"
                       placeholder="Tìm theo tên, email hoặc mã nhân viên...">
            </div>

            <div>
                <label for="f-account-manager-statusFilter-1" class="admin-label">Trạng thái</label>
                <select id="f-account-manager-statusFilter-1" wire:model.live="statusFilter" class="admin-input w-auto min-w-[160px]">
                    <option value="">Mọi trạng thái</option>
                    <option value="active">Đang hoạt động</option>
                    <option value="locked">Đã khóa</option>
                    <option value="disabled">Vô hiệu hóa</option>
                </select>
            </div>

            <div>
                <label for="f-account-manager-departmentFilter" class="admin-label">Phòng ban</label>
                <select id="f-account-manager-departmentFilter" wire:model.live="departmentFilter" class="admin-input w-auto min-w-[200px]">
                    <option value="">Mọi phòng ban</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="f-account-manager-roleFilter-2" class="admin-label">Vai trò</label>
                <select id="f-account-manager-roleFilter-2" wire:model.live="roleFilter" class="admin-input w-auto min-w-[180px]">
                    <option value="">Mọi vai trò</option>
                    @foreach ($roles as $r)
                        <option value="{{ $r->name }}">
                            {{ \App\Enums\RoleName::tryFrom($r->name)?->label() ?? $r->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="ml-auto flex items-end gap-2">
                <button type="button" wire:click="applySearch" class="admin-btn">
                    @svg('heroicon-o-magnifying-glass', 'h-4 w-4')
                    Tìm kiếm
                </button>
                <button type="button" wire:click="resetFilters" class="admin-btn-secondary">Đặt lại</button>
            </div>
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
                        <th class="w-32 text-right">Thao tác</th>
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
                                    @can('accounts.update')
                                    <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa tài khoản"
                                                         wire:click="edit({{ $user->id }})" />
                                    @endcan

                                    @if ($user->id !== auth()->id())
                                        @if ($user->status === 'active')
                                            @can('accounts.update')
                                            <x-admin.icon-button icon="heroicon-o-lock-closed" label="Khóa tài khoản"
                                                                 variant="danger"
                                                                 wire:click="prepareStatusChange({{ $user->id }})" />
                                            @endcan
                                        @else
                                            @can('accounts.update')
                                            <x-admin.icon-button icon="heroicon-o-lock-open" label="Mở khóa tài khoản"
                                                                 wire:click="prepareStatusChange({{ $user->id }})" />
                                            @endcan
                                        @endif
                                        @can('accounts.delete')
                                        <x-admin.icon-button icon="heroicon-o-trash" label="Xóa tài khoản"
                                                             variant="danger"
                                                             wire:click="prepareDelete({{ $user->id }})" />
                                        @endcan
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
            <form wire:submit="requestSave" id="account-form" class="grid gap-4 sm:grid-cols-2">
                {{-- Bước 1: chọn hồ sơ nhân sự — thông tin cá nhân lấy từ hồ sơ, không nhập lại --}}
                <div class="sm:col-span-2">
                    @if ($editingId && $selectedEmployee && $selectedEmployee->user_id === $editingId)
                        <span class="admin-label">Mã nhân viên</span>
                        <p class="admin-input flex items-center bg-brand-soft font-mono text-brand-muted">{{ $selectedEmployee->employee_code }}</p>
                    @else
                        <x-admin.searchable-select id="employee_id" model="employee_id"
                            label="Mã nhân viên" :required="true"
                            :options="$employeeOptions" :selected="$employee_id"
                            placeholder="Gõ mã hoặc tên nhân viên..." emptyLabel="— Chọn hồ sơ nhân sự —" />
                        <p class="mt-1 text-xs text-brand-muted">
                            Chỉ hiện hồ sơ đang làm việc chưa có tài khoản. Chưa có hồ sơ?
                            @can('employees.create')
                                <a href="{{ route('admin.employees') }}" wire:navigate class="font-semibold text-brand-red hover:underline">Tạo hồ sơ nhân sự</a> trước.
                            @else
                                Cần tạo hồ sơ nhân sự trước.
                            @endcan
                        </p>
                    @endif
                    @error('employee_id') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                @if ($selectedEmployee)
                    <div class="grid gap-3 rounded-admin border border-brand-line bg-brand-soft p-3 sm:col-span-2 sm:grid-cols-2">
                        <x-admin.detail-field label="Họ và tên">{{ $selectedEmployee->full_name }}</x-admin.detail-field>
                        <x-admin.detail-field label="Phòng ban">{{ $selectedEmployee->department?->name ?? '—' }}</x-admin.detail-field>
                        <x-admin.detail-field label="Chức danh">{{ $selectedEmployee->jobTitle?->name ?? '—' }}</x-admin.detail-field>
                        <x-admin.detail-field label="Trạng thái làm việc">{{ \App\Models\Employee::statusLabel($selectedEmployee->employment_status) }}</x-admin.detail-field>
                    </div>
                @endif

                {{-- Bước 2: thông tin đăng nhập --}}
                <div>
                    <label for="email" class="admin-label">Email đăng nhập <span class="text-brand-red" aria-hidden="true">*</span></label>
                    {{-- Kiểm tra trùng ngay khi gõ (dừng gõ 0,5 giây), không đợi bấm lưu --}}
                    <input id="email" type="email" wire:model.live.debounce.500ms="email" autocomplete="off"
                           placeholder="ten.nhanvien@techcombank.com.vn"
                           @class(['admin-input', '!border-brand-red' => $errors->has('email')])>
                    <div wire:loading wire:target="email" class="mt-1 text-xs text-brand-muted">Đang kiểm tra email...</div>
                    <div wire:loading.remove wire:target="email">
                        @error('email')
                            <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p>
                        @else
                            @if (filled($email))
                                <p class="mt-1 flex items-center gap-1 text-xs font-semibold text-state-success">
                                    @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Email dùng được
                                </p>
                            @elseif (! $editingId && $selectedEmployee)
                                <p class="mt-1 text-xs text-brand-muted">Hồ sơ chưa có email — nhập email đăng nhập cho nhân viên.</p>
                            @endif
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="password" class="admin-label">
                        Mật khẩu {!! $editingId ? '(để trống nếu giữ nguyên)' : '<span class="text-brand-red" aria-hidden="true">*</span>' !!}
                    </label>
                    <input id="password" type="password" wire:model="password" class="admin-input" autocomplete="new-password">
                    @error('password') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                @if ($editingId === auth()->id())
                    <div class="rounded-admin border border-brand-line bg-brand-soft p-3 text-sm sm:col-span-2">
                        <strong>Tài khoản của bạn</strong>
                        <p class="mt-1 text-xs text-brand-muted">Bạn có thể sửa tên, email và mật khẩu. Vai trò và trạng thái được giữ nguyên để tránh tự khóa quyền quản trị.</p>
                    </div>
                @else
                    <div>
                        <label for="role" class="admin-label">Vai trò <span class="text-brand-red" aria-hidden="true">*</span></label>
                        <select id="role" wire:model="role" class="admin-input">
                            @foreach ($roles as $r)
                                <option value="{{ $r->name }}" @selected($role === $r->name)>
                                    {{ \App\Enums\RoleName::tryFrom($r->name)?->label() ?? $r->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('role') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="status" class="admin-label">Trạng thái <span class="text-brand-red" aria-hidden="true">*</span></label>
                        <select id="status" wire:model="status" class="admin-input">
                            <option value="active">Đang hoạt động</option>
                            <option value="locked">Đã khóa</option>
                            <option value="disabled">Vô hiệu hóa</option>
                        </select>
                    </div>
                @endif

                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium sm:col-span-2">
                    <input type="checkbox" wire:model="must_change_password"
                           class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                    Bắt buộc đổi mật khẩu ở lần đăng nhập đầu tiên
                </label>
                <p class="text-xs text-brand-muted sm:col-span-2"><span class="text-brand-red">*</span> Trường bắt buộc</p>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="account-form" class="admin-btn" wire:loading.attr="disabled">
                    {{ $editingId ? 'Xem xác nhận lưu' : 'Xem xác nhận tạo' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($showSaveConfirm)
        <x-admin.modal wireModel="showSaveConfirm" title="Xác nhận lưu tài khoản" maxWidth="max-w-md">
            <p class="text-sm text-brand-muted">
                {{ $editingId ? 'Bạn có chắc muốn lưu các thay đổi cho tài khoản này?' : 'Bạn có chắc muốn tạo tài khoản mới với thông tin vừa nhập?' }}
            </p>
            <x-slot:footer>
                <button type="button" wire:click="$set('showSaveConfirm', false)" class="admin-btn-secondary">Quay lại</button>
                <button type="button" wire:click="save" class="admin-btn" wire:loading.attr="disabled">Xác nhận</button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($showActionConfirm)
        <x-admin.modal wireModel="showActionConfirm" :title="$pendingActionTitle" maxWidth="max-w-md">
            <p class="text-sm text-brand-muted">{{ $pendingActionMessage }}</p>
            <x-slot:footer>
                <button type="button" wire:click="$set('showActionConfirm', false)" class="admin-btn-secondary">Hủy</button>
                <button type="button" wire:click="confirmPendingAction" class="admin-btn" wire:loading.attr="disabled">Xác nhận</button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
