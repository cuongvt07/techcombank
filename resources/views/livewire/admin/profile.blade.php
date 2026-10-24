<div>
    <x-admin.page-header title="Thông tin cá nhân"
                         subtitle="Cập nhật thông tin tài khoản quản trị và mật khẩu của bạn." />

    <div class="grid gap-5 xl:grid-cols-2">
        <section class="admin-panel p-5 sm:p-6">
            <h2 class="text-base font-bold">Thông tin cá nhân</h2>
            <p class="mt-1 text-sm text-brand-muted">Tên và email được đồng bộ với hồ sơ nhân sự nếu tài khoản có liên kết.</p>

            <form wire:submit="saveProfile" class="mt-5 grid gap-4">
                <div>
                    <label for="admin-profile-name" class="admin-label">Họ và tên</label>
                    <input id="admin-profile-name" type="text" wire:model="name" autocomplete="name" class="admin-input">
                    @error('name') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="admin-profile-email" class="admin-label">Email đăng nhập</label>
                    <input id="admin-profile-email" type="email" wire:model="email" autocomplete="email" class="admin-input">
                    @error('email') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="admin-profile-phone" class="admin-label">Số điện thoại</label>
                    <input id="admin-profile-phone" type="tel" wire:model="phone" autocomplete="tel" class="admin-input">
                    @error('phone') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                @if ($employee)
                    <div class="grid gap-2 rounded-admin bg-brand-soft p-3 text-sm sm:grid-cols-2">
                        <p><span class="text-brand-muted">Mã nhân viên:</span> <strong>{{ $employee->employee_code }}</strong></p>
                        <p><span class="text-brand-muted">Đơn vị:</span> <strong>{{ $employee->department?->name ?? '—' }}</strong></p>
                    </div>
                @endif

                <button type="submit" class="admin-btn justify-self-start">Lưu thông tin</button>
            </form>
        </section>

        <section class="admin-panel p-5 sm:p-6">
            <h2 class="text-base font-bold">Đổi mật khẩu</h2>
            <p class="mt-1 text-sm text-brand-muted">Nhập mật khẩu hiện tại để xác nhận thay đổi.</p>

            <form wire:submit="changePassword" class="mt-5 grid gap-4">
                <div>
                    <label for="admin-current-password" class="admin-label">Mật khẩu hiện tại</label>
                    <input id="admin-current-password" type="password" wire:model="currentPassword" autocomplete="current-password" class="admin-input">
                    @error('currentPassword') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="admin-new-password" class="admin-label">Mật khẩu mới</label>
                    <input id="admin-new-password" type="password" wire:model="newPassword" autocomplete="new-password" class="admin-input">
                    @error('newPassword') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="admin-confirm-password" class="admin-label">Xác nhận mật khẩu mới</label>
                    <input id="admin-confirm-password" type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" class="admin-input">
                    @error('newPasswordConfirmation') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="admin-btn justify-self-start">Đổi mật khẩu</button>
            </form>
        </section>
    </div>
</div>
