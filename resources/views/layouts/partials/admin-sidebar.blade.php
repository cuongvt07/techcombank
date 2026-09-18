{{--
    Sidebar bám đúng cây module ở spec mục 2: Nhân sự / Tài liệu nội bộ / Bảo mật.
    Mục đang chọn nhận viền đỏ mỏng bên trái — điểm nhấn thương hiệu duy nhất
    ở vùng điều hướng, không thêm chi tiết trang trí nào khác (spec 6.4).
--}}
@php
    // Nhóm điều hướng khai báo tập trung để thêm/bớt module không phải sửa markup.
    $navGroups = [
        'Quản lý nhân sự' => [
            ['route' => 'admin.dashboard', 'label' => 'Tổng quan', 'icon' => 'squares-2x2', 'permission' => null],
            ['route' => 'admin.accounts', 'label' => 'Tài khoản', 'icon' => 'key', 'permission' => 'accounts.view'],
            ['route' => 'admin.employees', 'label' => 'Hồ sơ nhân sự', 'icon' => 'users', 'permission' => 'employees.view'],
            ['route' => 'admin.contracts', 'label' => 'Hợp đồng', 'icon' => 'document-check', 'permission' => 'contracts.view'],
            ['route' => 'admin.roles', 'label' => 'Phân quyền', 'icon' => 'lock-closed', 'permission' => 'roles.manage'],
        ],
        'Tài liệu nội bộ' => [
            ['route' => 'admin.files', 'label' => 'Kho tài nguyên', 'icon' => 'folder', 'permission' => 'documents.view'],
            ['route' => 'admin.documents', 'label' => 'Thư viện tài liệu', 'icon' => 'document-duplicate', 'permission' => 'documents.view'],
            ['route' => 'admin.courses', 'label' => 'Bộ tài liệu ban hành', 'icon' => 'academic-cap', 'permission' => 'courses.view'],
            ['route' => 'admin.quizzes', 'label' => 'Bài kiểm tra', 'icon' => 'clipboard-document-check', 'permission' => 'quizzes.view'],
        ],
        'Sự kiện' => [
            ['route' => 'admin.announcements', 'label' => 'Sự kiện & thông báo', 'icon' => 'megaphone', 'permission' => 'announcements.view'],
        ],
        'Bảo mật & vận hành' => [
            ['route' => 'admin.access-rules', 'label' => 'Phân quyền tài liệu', 'icon' => 'shield-check', 'permission' => 'documents.manage-access'],
            ['route' => 'admin.security', 'label' => 'Trung tâm bảo mật', 'icon' => 'exclamation-triangle', 'permission' => 'security.view'],
            ['route' => 'admin.reports', 'label' => 'Báo cáo tiến độ', 'icon' => 'chart-bar', 'permission' => 'reports.view'],
            ['route' => 'admin.support', 'label' => 'Phiếu hỗ trợ', 'icon' => 'lifebuoy', 'permission' => 'support.manage'],
            ['route' => 'admin.settings', 'label' => 'Cấu hình chung', 'icon' => 'cog-6-tooth', 'permission' => 'settings.manage'],
        ],
    ];
@endphp

<aside class="flex w-[268px] shrink-0 flex-col bg-gradient-to-b from-brand-black to-[#101318] p-4 pt-5">
    <div class="border-b border-white/10 px-2 pb-5">
        <x-brand-logo height="h-7" :on-dark="true" />
    </div>

    <nav class="mt-4 grid gap-1.5">
        @foreach ($navGroups as $groupLabel => $items)
            <div class="mx-2.5 mb-1.5 mt-4 text-[11px] font-semibold uppercase tracking-wider text-white/[.44]">
                {{ $groupLabel }}
            </div>

            @foreach ($items as $item)
                {{-- Route chưa dựng thì ẩn mục thay vì để link chết --}}
                @continue(! Route::has($item['route']))
                @continue($item['permission'] && ! auth()->user()?->can($item['permission']))

                @php $active = request()->routeIs($item['route']); @endphp
                <a href="{{ route($item['route']) }}"
                   class="admin-nav-item {{ $active ? 'admin-nav-item-active' : '' }}"
                   @if ($active) aria-current="page" @endif>
                    @svg('heroicon-' . ($active ? 's' : 'o') . '-' . $item['icon'], 'h-5 w-5 shrink-0')
                    <span class="truncate">{{ $item['label'] }}</span>
                </a>
            @endforeach
        @endforeach
    </nav>

    {{-- Thẻ tài khoản đặt cuối sidebar, đẩy xuống bằng mt-auto --}}
    <div class="mt-auto flex items-center gap-2.5 rounded-admin-lg border border-white/[.16] bg-white/[.06] p-3.5">
        <x-user-avatar :employee="auth()->user()?->employee" size="h-9 w-9" text="text-xs" />

        <div class="min-w-0">
            <strong class="block truncate text-sm font-bold text-white">
                {{ auth()->user()?->employee?->full_name ?? auth()->user()?->name }}
            </strong>
            <span class="block truncate text-xs text-white/[.58]">
                {{ auth()->user()?->getRoleNames()->map(fn ($r) => \App\Enums\RoleName::tryFrom($r)?->label() ?? $r)->join(', ') }}
            </span>
        </div>
    </div>
</aside>
