{{-- Thanh trên hiển thị thông báo và tài khoản quản trị. --}}
<header class="flex min-h-[72px] items-center justify-end gap-3 border-b border-brand-line bg-white px-4 sm:px-8">
    <div class="flex items-center gap-3">
        @php
            $openAlerts = \App\Models\SecurityAlert::open()->count();
        @endphp

        @if ($openAlerts > 0 && Route::has('admin.security-alerts'))
            {{-- Chấm đỏ báo số việc cần xử lý — điểm nhấn thương hiệu có chủ đích --}}
            <a href="{{ route('admin.security-alerts') }}"
               class="tag tag-red"
               title="Cảnh báo bảo mật chưa xử lý">
                {{ $openAlerts }} cảnh báo
            </a>
        @endif

        {{-- Chuông thông báo tài khoản: phiếu hỗ trợ được giao cho mình... --}}
        @livewire('user.notification-bell', ['site' => 'admin'])

        {{-- Nút Đăng xuất đã chuyển vào menu tài khoản ở cuối sidebar (fb7 mục 4).
             Sidebar quản trị luôn hiển thị (kể cả màn hẹp, dạng thu gọn) nên
             không mất lối đăng xuất trên mobile. --}}
    </div>
</header>
