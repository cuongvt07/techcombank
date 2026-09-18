{{--
    Top bar chỉ chứa tìm kiếm — thông báo — tài khoản (spec 6.4).
    Không đặt điều hướng ở đây để tránh trùng vai trò với sidebar.
--}}
<header class="flex min-h-[72px] items-center gap-4 border-b border-brand-line bg-white/[.94] px-6">
    <div class="min-w-0 flex-1 md:max-w-[520px]">
        <label for="global-search" class="sr-only">Tìm kiếm</label>
        <input id="global-search"
               type="search"
               class="admin-input"
               placeholder="Tìm nhân viên, khóa học, mã hợp đồng, phiếu hỗ trợ...">
    </div>

    <div class="ml-auto flex items-center gap-3">
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
