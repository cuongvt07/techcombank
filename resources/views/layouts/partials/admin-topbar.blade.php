{{-- Thanh trên hiển thị thông báo và tài khoản quản trị. --}}
<header class="flex min-h-[72px] items-center justify-end gap-3 border-b border-brand-line bg-white/[.94] px-6">
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

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="admin-btn-secondary">Đăng xuất</button>
        </form>
    </div>
</header>
