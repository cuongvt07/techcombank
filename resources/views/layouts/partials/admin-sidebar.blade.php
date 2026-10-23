{{--
    Sidebar bám đúng cây module ở spec mục 2: Nhân sự / Tài liệu nội bộ / Bảo mật.
    Mục đang chọn nhận viền đỏ mỏng bên trái — điểm nhấn thương hiệu duy nhất
    ở vùng điều hướng, không thêm chi tiết trang trí nào khác (spec 6.4).
--}}
@php
    // Nhóm điều hướng khai báo tập trung để thêm/bớt module không phải sửa markup.
    $navGroups = [
        'Quản lý nhân sự' => [
            ['route' => 'admin.dashboard', 'label' => 'Tổng quan', 'icon' => 'squares-2x2', 'permission' => 'dashboard.view'],
            ['route' => 'admin.accounts', 'label' => 'Tài khoản', 'icon' => 'key', 'permission' => 'accounts.view'],
            ['route' => 'admin.employees', 'label' => 'Hồ sơ nhân sự', 'icon' => 'users', 'permission' => 'employees.view'],
            ['route' => 'admin.roles', 'label' => 'Phân quyền', 'icon' => 'lock-closed', 'permission' => 'roles.view'],
        ],
        'Tài liệu nội bộ' => [
            ['route' => 'admin.files', 'label' => 'Kho tài nguyên', 'icon' => 'folder', 'permission' => 'files.view'],
            ['route' => 'admin.documents', 'label' => 'Thư viện & phân quyền', 'icon' => 'document-duplicate', 'permission' => 'documents.view'],
            ['route' => 'admin.courses', 'label' => 'Bộ tài liệu ban hành', 'icon' => 'academic-cap', 'permission' => 'courses.view'],
            ['route' => 'admin.quizzes', 'label' => 'Bài kiểm tra', 'icon' => 'clipboard-document-check', 'permission' => 'quizzes.view'],
        ],
        'Sự kiện' => [
            ['route' => 'admin.announcements', 'label' => 'Sự kiện & thông báo', 'icon' => 'megaphone', 'permission' => 'announcements.view'],
        ],
        'Báo cáo' => [
            ['route' => 'admin.reports.learning', 'label' => 'Tiến độ học tập', 'icon' => 'chart-bar', 'permission' => 'report_learning.view'],
            ['route' => 'admin.reports.quiz', 'label' => 'Kết quả kiểm tra', 'icon' => 'clipboard-document-list', 'permission' => 'report_quiz.view'],
            ['route' => 'admin.reports.documents', 'label' => 'Truy cập tài liệu', 'icon' => 'document-magnifying-glass', 'permission' => 'report_documents.view'],
            ['route' => 'admin.reports.security', 'label' => 'Bảo mật & đăng nhập', 'icon' => 'shield-check', 'permission' => 'report_security.view'],
            ['route' => 'admin.reports.support', 'label' => 'Yêu cầu hỗ trợ', 'icon' => 'chat-bubble-left-right', 'permission' => 'report_support.view'],
        ],
        'Bảo mật & vận hành' => [
            ['route' => 'admin.security', 'label' => 'Trung tâm bảo mật', 'icon' => 'exclamation-triangle', 'permission' => 'security.view'],
            ['route' => 'admin.support', 'label' => 'Phiếu hỗ trợ', 'icon' => 'lifebuoy', 'permission' => 'support.view'],
            ['route' => 'admin.settings', 'label' => 'Cấu hình chung', 'icon' => 'cog-6-tooth', 'permission' => 'settings.view'],
        ],
    ];
@endphp

<aside x-data="{
           collapsed: localStorage.getItem('admin-sidebar-collapsed') === 'true',
           toggle() {
               this.collapsed = !this.collapsed;
               localStorage.setItem('admin-sidebar-collapsed', String(this.collapsed));
           }
       }"
       x-bind:class="collapsed ? 'w-[76px] px-2' : 'w-[268px] px-4'"
       class="sticky top-0 z-30 flex h-screen shrink-0 flex-col overflow-hidden bg-gradient-to-b from-brand-black to-[#101318] pt-5 transition-[width] duration-200">
    <div class="flex min-h-[52px] items-center border-b border-white/10 px-2 pb-4"
         x-bind:class="collapsed ? 'justify-center' : 'justify-between'">
        <span x-show="!collapsed" x-transition.opacity>
            <x-brand-logo height="h-7" :on-dark="true" />
        </span>
        <span x-show="collapsed" x-cloak>
            <x-brand-logo variant="mark" height="h-7" :on-dark="true" />
        </span>
        <button type="button"
                x-on:click="toggle()"
                x-bind:aria-label="collapsed ? 'Mở rộng thanh điều hướng' : 'Thu gọn thanh điều hướng'"
                x-bind:title="collapsed ? 'Mở rộng menu' : 'Thu gọn menu'"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-admin text-white/70 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">
            <svg x-show="!collapsed" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7M20 19l-7-7 7-7" />
            </svg>
            <svg x-show="collapsed" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7M4 5l7 7-7 7" />
            </svg>
        </button>
    </div>

    <nav aria-label="Điều hướng quản trị" class="mt-4 min-h-0 flex-1 space-y-1.5 overflow-y-auto overflow-x-hidden pb-4">
        @foreach ($navGroups as $groupLabel => $items)
            <div x-show="!collapsed" class="mx-2.5 mb-1.5 mt-4 text-[11px] font-semibold uppercase tracking-wider text-white/[.44]">
                {{ $groupLabel }}
            </div>

            @foreach ($items as $item)
                {{-- Route chưa dựng thì ẩn mục thay vì để link chết --}}
                @continue(! Route::has($item['route']))
                @continue($item['permission'] && ! auth()->user()?->can($item['permission']))

                @php $active = request()->routeIs($item['route'], $item['route'] . '.*'); @endphp
                <a href="{{ route($item['route']) }}"
                   x-bind:title="collapsed ? @js($item['label']) : null"
                   x-bind:aria-label="collapsed ? @js($item['label']) : null"
                   x-bind:class="collapsed ? 'justify-center px-2' : 'px-3'"
                   class="admin-nav-item {{ $active ? 'admin-nav-item-active' : '' }}"
                   @if ($active) aria-current="page" @endif>
                    @svg('heroicon-' . ($active ? 's' : 'o') . '-' . $item['icon'], 'h-5 w-5 shrink-0')
                    <span x-show="!collapsed" x-transition.opacity class="truncate">{{ $item['label'] }}</span>
                </a>
            @endforeach
        @endforeach
    </nav>

    {{-- Thẻ tài khoản đặt cuối sidebar, đẩy xuống bằng mt-auto --}}
    <div class="mt-auto flex items-center gap-2.5 rounded-admin-lg border border-white/[.16] bg-white/[.06] p-3.5"
         x-bind:class="collapsed ? 'justify-center px-1.5' : ''">
        <x-user-avatar :employee="auth()->user()?->employee" size="h-9 w-9" text="text-xs" />

        <div x-show="!collapsed" x-transition.opacity class="min-w-0">
            <strong class="block truncate text-sm font-bold text-white">
                {{ auth()->user()?->employee?->full_name ?? auth()->user()?->name }}
            </strong>
            <span class="block truncate text-xs text-white/[.58]">
                {{ auth()->user()?->getRoleNames()->map(fn ($r) => \App\Enums\RoleName::tryFrom($r)?->label() ?? $r)->join(', ') }}
            </span>
        </div>
    </div>
</aside>
