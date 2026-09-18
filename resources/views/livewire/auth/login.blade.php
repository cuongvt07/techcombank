<div>
    <div class="mb-6 flex justify-center">
        <x-brand-logo height="h-9" :subtitle="false" />
    </div>

    <div class="admin-panel p-6 shadow-admin">
        <h1 class="mb-1 text-xl font-bold">Đăng nhập hệ thống</h1>
        <p class="mb-5 text-sm text-brand-muted">Sử dụng tài khoản do quản trị viên cấp.</p>

        <form wire:submit="login" class="grid gap-4">
            <div>
                <label for="email" class="admin-label">Email</label>
                <input id="email"
                       type="email"
                       wire:model="email"
                       autocomplete="username"
                       autofocus
                       class="admin-input"
                       placeholder="ten.ban@techcombank.local">
                @error('email')
                    <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="admin-label">Mật khẩu</label>
                <input id="password"
                       type="password"
                       wire:model="password"
                       autocomplete="current-password"
                       class="admin-input">
                @error('password')
                    <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex cursor-pointer items-center gap-2 text-sm font-medium">
                <input type="checkbox"
                       wire:model="remember"
                       class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                Ghi nhớ đăng nhập
            </label>

            <button type="submit" class="admin-btn w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">Đăng nhập</span>
                <span wire:loading wire:target="login">Đang xử lý...</span>
            </button>
        </form>
    </div>

    <p class="mt-4 text-center text-xs text-brand-muted">
        Gặp sự cố đăng nhập? Liên hệ Phòng Nhân sự hoặc bộ phận IT.
    </p>
</div>
