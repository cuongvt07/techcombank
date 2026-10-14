<div>
    <div class="mb-6 flex justify-center">
        <x-brand-logo height="h-9" :subtitle="false" />
    </div>

    <section class="rounded-xl border border-[#e5e7eb] bg-white p-6 shadow-[0_8px_28px_rgba(15,23,42,0.08)] sm:p-8">
        <h1 class="mb-1 text-xl font-bold">Đăng nhập hệ thống</h1>
        <p class="mb-6 text-sm text-brand-muted">Sử dụng tài khoản do quản trị viên cấp.</p>

        <form wire:submit="login" class="grid gap-5">
            <div>
                <div class="relative">
                    <input id="email"
                           type="email"
                           wire:model="email"
                           autocomplete="username"
                           autofocus
                           placeholder=" "
                           class="peer h-[58px] w-full rounded-md border border-[#9aa0a6] bg-white px-4 text-base text-[#202124] outline-none transition placeholder:text-transparent hover:border-[#5f6368] focus:border-2 focus:border-[#0b57d0] focus:ring-0">
                    <label for="email"
                           class="pointer-events-none absolute left-3 top-0 -translate-y-1/2 bg-white px-1 text-sm text-[#0b57d0] transition-[top] duration-[3500ms] ease-out motion-reduce:duration-0 peer-placeholder-shown:top-1/2 peer-placeholder-shown:-translate-y-1/2 peer-placeholder-shown:bg-transparent peer-placeholder-shown:px-1 peer-placeholder-shown:text-base peer-placeholder-shown:text-[#5f6368] peer-focus:top-0 peer-focus:-translate-y-1/2 peer-focus:bg-white peer-focus:text-sm peer-focus:text-[#0b57d0]">
                        Email
                    </label>
                </div>
                @error('email')
                    <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p>
                @enderror
            </div>

            <div x-data="{ showPassword: false }">
                <div class="relative">
                    <input id="password"
                           x-bind:type="showPassword ? 'text' : 'password'"
                           wire:model="password"
                           autocomplete="current-password"
                           placeholder=" "
                           class="peer h-[58px] w-full rounded-md border border-[#9aa0a6] bg-white py-3 pl-4 pr-14 text-base text-[#202124] outline-none transition placeholder:text-transparent hover:border-[#5f6368] focus:border-2 focus:border-[#0b57d0] focus:ring-0">
                    <label for="password"
                           class="pointer-events-none absolute left-3 top-0 -translate-y-1/2 bg-white px-1 text-sm text-[#0b57d0] transition-[top] duration-[3500ms] ease-out motion-reduce:duration-0 peer-placeholder-shown:top-1/2 peer-placeholder-shown:-translate-y-1/2 peer-placeholder-shown:bg-transparent peer-placeholder-shown:px-1 peer-placeholder-shown:text-base peer-placeholder-shown:text-[#5f6368] peer-focus:top-0 peer-focus:-translate-y-1/2 peer-focus:bg-white peer-focus:text-sm peer-focus:text-[#0b57d0]">
                        Mật khẩu
                    </label>
                    <button type="button"
                            x-on:click="showPassword = !showPassword"
                            x-bind:aria-pressed="showPassword.toString()"
                            x-bind:aria-label="showPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'"
                            class="absolute inset-y-0 right-1 flex w-11 items-center justify-center rounded text-[#5f6368] hover:bg-[#f1f3f4] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0b57d0]">
                        <svg x-show="!showPassword" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z" />
                            <circle cx="12" cy="12" r="2.5" />
                        </svg>
                        <svg x-show="showPassword" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M10.58 10.59a2 2 0 0 0 2.83 2.83M9.88 5.24A10.9 10.9 0 0 1 12 5c6.25 0 9.75 7 9.75 7a16.7 16.7 0 0 1-3.02 3.6M6.18 6.18C3.65 7.8 2.25 12 2.25 12s3.5 7 9.75 7a10.4 10.4 0 0 0 4.15-.86" />
                        </svg>
                    </button>
                </div>
                @error('password')
                    <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-[#3c4043]">
                <input type="checkbox"
                       wire:model="remember"
                       class="h-4 w-4 rounded border-brand-line text-brand-red focus:ring-brand-red">
                Ghi nhớ đăng nhập
            </label>

            <button type="submit" class="admin-btn min-h-[46px] w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">Đăng nhập</span>
                <span wire:loading wire:target="login">Đang xử lý...</span>
            </button>
        </form>
    </section>

    <p class="mt-4 text-center text-xs text-brand-muted">
        Gặp sự cố đăng nhập? Liên hệ Phòng Nhân sự hoặc bộ phận IT.
    </p>
</div>
