<div class="grid gap-5 lg:grid-cols-2 lg:items-start">
    {{-- Thông tin cá nhân (spec 4.2) --}}
    <section class="user-card p-5">
        <div class="flex items-center gap-3.5">
            <x-user-avatar :employee="$employee" size="h-16 w-16" text="text-xl" />

            <div class="min-w-0 flex-1">
                <h2 class="truncate text-lg font-bold">{{ $employee?->full_name ?? $user->name }}</h2>
                <p class="truncate text-sm text-brand-muted">{{ $user->email }}</p>

                @if ($employee)
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <label class="user-btn-secondary cursor-pointer !min-h-[32px] !px-3 !text-xs">
                            <input type="file" wire:model="avatar" accept="image/*" class="hidden">
                            <span wire:loading.remove wire:target="avatar">Đổi ảnh</span>
                            <span wire:loading wire:target="avatar">Đang tải...</span>
                        </label>

                        @if ($employee->avatar_file_id)
                            <button type="button" wire:click="removeAvatar"
                                    class="text-xs text-brand-muted transition-colors hover:text-brand-red">
                                Gỡ ảnh
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{--
            Xem trước ảnh vừa chọn, bấm Lưu mới ghi vào hệ thống.
            isPreviewable() bắt buộc: chọn nhầm file không phải ảnh thì
            temporaryUrl() ném lỗi và cả trang chết trước khi validate kịp báo.
        --}}
        @if ($avatar && method_exists($avatar, 'isPreviewable') && $avatar->isPreviewable())
            <div class="mt-4 flex items-center gap-3 rounded-user-md border border-brand-line p-3">
                <img src="{{ $avatar->temporaryUrl() }}" alt="Xem trước"
                     class="h-14 w-14 shrink-0 rounded-full object-cover">
                <p class="min-w-0 flex-1 text-xs text-brand-muted">Ảnh mới, chưa lưu.</p>
                <button type="button" wire:click="saveAvatar" class="user-btn shrink-0 !min-h-[34px] !px-3 !text-xs">
                    Lưu ảnh
                </button>
                <button type="button" wire:click="$set('avatar', null)"
                        class="shrink-0 text-xs text-brand-muted hover:text-brand-red">
                    Hủy
                </button>
            </div>
        @endif

        @error('avatar')
            <p class="mt-2 text-xs font-semibold text-brand-red">{{ $message }}</p>
        @enderror

        <dl class="mt-5 grid gap-3 border-t border-brand-line pt-4 text-sm">
            <div class="flex gap-3">
                <dt class="w-36 shrink-0 text-brand-muted">Mã nhân viên</dt>
                <dd class="font-mono">{{ $employee?->employee_code ?? '—' }}</dd>
            </div>
            <div class="flex gap-3">
                <dt class="w-36 shrink-0 text-brand-muted">Phòng ban</dt>
                <dd>{{ $employee?->department?->name ?? '—' }}</dd>
            </div>
            <div class="flex gap-3">
                <dt class="w-36 shrink-0 text-brand-muted">Chức danh</dt>
                <dd>{{ $employee?->jobTitle?->name ?? '—' }}</dd>
            </div>
            <div class="flex gap-3">
                <dt class="w-36 shrink-0 text-brand-muted">Cấp bậc</dt>
                <dd>{{ $employee?->jobGrade?->name ?? '—' }}</dd>
            </div>
            <div class="flex gap-3">
                <dt class="w-36 shrink-0 text-brand-muted">Quản lý trực tiếp</dt>
                <dd>{{ $employee?->manager?->full_name ?? '—' }}</dd>
            </div>
            <div class="flex gap-3">
                <dt class="w-36 shrink-0 text-brand-muted">Ngày vào làm</dt>
                <dd>{{ $employee?->joined_at?->format('d/m/Y') ?? '—' }}</dd>
            </div>
        </dl>

        <p class="mt-4 rounded-user-md bg-brand-soft p-3 text-xs text-brand-muted">
            Thông tin nhân sự do bộ phận Nhân sự quản lý. Nếu có sai sót, hãy gửi yêu cầu hỗ trợ.
        </p>
    </section>

    <div class="grid gap-5">
        {{-- Đổi mật khẩu --}}
        <section class="user-card p-5">
            <h2 class="text-base font-bold">Đổi mật khẩu</h2>

            @if ($user->must_change_password)
                <p class="mt-2 rounded-user-md border border-state-warning bg-state-warning-tint p-3 text-xs font-semibold text-state-warning">
                    Bạn cần đổi mật khẩu do quản trị viên cấp sang mật khẩu riêng.
                </p>
            @endif

            <form wire:submit="changePassword" class="mt-4 grid gap-3.5">
                <div>
                    <label for="p_current" class="mb-1.5 block text-xs font-semibold uppercase text-brand-muted">Mật khẩu hiện tại</label>
                    <input id="p_current" type="password" wire:model="currentPassword" autocomplete="current-password"
                           class="h-11 w-full rounded-user-md border border-brand-line bg-white px-3.5 text-sm focus:border-brand-red focus:outline-none focus:ring-1 focus:ring-brand-red">
                    @error('currentPassword') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="p_new" class="mb-1.5 block text-xs font-semibold uppercase text-brand-muted">Mật khẩu mới</label>
                    <input id="p_new" type="password" wire:model="newPassword" autocomplete="new-password"
                           class="h-11 w-full rounded-user-md border border-brand-line bg-white px-3.5 text-sm focus:border-brand-red focus:outline-none focus:ring-1 focus:ring-brand-red">
                    @error('newPassword') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="p_confirm" class="mb-1.5 block text-xs font-semibold uppercase text-brand-muted">Xác nhận mật khẩu mới</label>
                    <input id="p_confirm" type="password" wire:model="newPasswordConfirmation" autocomplete="new-password"
                           class="h-11 w-full rounded-user-md border border-brand-line bg-white px-3.5 text-sm focus:border-brand-red focus:outline-none focus:ring-1 focus:ring-brand-red">
                    @error('newPasswordConfirmation') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="user-btn w-full sm:w-auto sm:justify-self-start">Đổi mật khẩu</button>
            </form>
        </section>

        {{-- Thiết bị đang đăng nhập — người dùng tự thu hồi (spec 3.3.2) --}}
        <section class="user-card overflow-hidden">
            <h2 class="border-b border-brand-line px-5 py-3.5 text-base font-bold">Thiết bị đang đăng nhập</h2>

            @forelse ($sessions as $session)
                <div class="flex items-center justify-between gap-3 border-b border-brand-line p-4 last:border-b-0">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold">{{ $session->device_label ?? 'Thiết bị không xác định' }}</p>
                        <p class="mt-0.5 text-xs text-brand-muted">
                            {{ $session->ip_address ?? '—' }} ·
                            {{ $session->last_activity_at?->diffForHumans() ?? '—' }}
                        </p>
                    </div>

                    @if ($session->session_id === session()->getId())
                        <span class="tag tag-green shrink-0">Thiết bị này</span>
                    @else
                        <button type="button" wire:click="revokeSession({{ $session->id }})"
                                wire:confirm="Thu hồi phiên đăng nhập trên thiết bị này?"
                                class="user-pill shrink-0 transition-colors hover:bg-brand-red-tint hover:text-brand-red">
                            Thu hồi
                        </button>
                    @endif
                </div>
            @empty
                <p class="p-5 text-sm text-brand-muted">Không có phiên nào đang hoạt động.</p>
            @endforelse
        </section>

        {{-- Lịch sử đăng nhập gần đây --}}
        @if ($recentLogins->isNotEmpty())
            <section class="user-card overflow-hidden">
                <h2 class="border-b border-brand-line px-5 py-3.5 text-base font-bold">Đăng nhập gần đây</h2>

                @foreach ($recentLogins as $login)
                    <div class="flex items-center justify-between gap-3 border-b border-brand-line px-5 py-3 last:border-b-0">
                        <div class="min-w-0">
                            <p class="text-xs font-bold">{{ $login->logged_in_at?->format('d/m/Y H:i') }}</p>
                            <p class="mt-0.5 truncate text-[11px] text-brand-muted">
                                {{ $login->ip_address ?? '—' }} · {{ $login->device_label ?? '—' }}
                            </p>
                        </div>

                        @php
                            $map = [
                                'success' => ['tag-green', 'Thành công'],
                                'failed' => ['tag-amber', 'Thất bại'],
                                'blocked' => ['tag-red', 'Bị chặn'],
                            ];
                            [$cls, $label] = $map[$login->result] ?? ['', $login->result];
                        @endphp
                        <span class="tag {{ $cls }} shrink-0">{{ $label }}</span>
                    </div>
                @endforeach
            </section>
        @endif
    </div>
</div>
