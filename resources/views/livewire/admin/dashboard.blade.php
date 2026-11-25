@php
    use App\Services\DashboardFilter;
    use App\Services\DashboardMetricsService as Metrics;
    use App\Support\PermissionCatalog;
    use App\Support\Reports\ReportPeriod;

    $user = auth()->user();
    $fmtInt = fn ($v) => number_format((float) $v, 0, ',', '.');
    $fmtPct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . '%';
    $nowVn = now(ReportPeriod::TIMEZONE);

    // Màu thanh tiến độ theo ngưỡng: ≥70% đậm trung tính, 40–69% xám, <40% đỏ (cảnh báo)
    $progressColor = fn (float $p) => $p >= 70 ? '#141821' : ($p >= 40 ? '#6b7280' : '#c00016');

    // Chỉ dẫn link khi người xem có quyền Xem màn đích
    $canOpen = fn (string $route) => Route::has($route) && $user?->can(PermissionCatalog::viewPermissionForRoute($route));

    $reportLinks = collect([
        'admin.reports.learning' => 'Tiến độ học tập',
        'admin.reports.quiz' => 'Kết quả kiểm tra',
        'admin.reports.documents' => 'Truy cập tài liệu',
        'admin.reports.security' => 'Bảo mật & đăng nhập',
        'admin.reports.support' => 'Yêu cầu hỗ trợ',
    ])->filter(fn ($label, $route) => $canOpen($route));

    // Link sang báo cáo giữ nguyên kỳ đang xem (cùng tham số của HasReportPeriod)
    $periodParams = array_filter(['ky' => $preset !== '30d' ? $preset : null, 'tu' => $preset === 'custom' ? $from : null, 'den' => $preset === 'custom' ? $to : null]);
    $canLearning = $canOpen('admin.reports.learning');
    $learningUrl = $canLearning ? route('admin.reports.learning', $periodParams) : null;

    $templateUrl = $this->templateUrl();
    $excelUrl = $templateUrl . (str_contains($templateUrl, '?') ? '&' : '?') . 'dinh-dang=xlsx';

    $period = $filter->period;
    $statusTotal = array_sum($statusCounts);
    $statusLabels = Metrics::STATUS_LABELS;
    $statusColors = Metrics::STATUS_COLORS;
    $statusSummary = collect($employmentStatuses)->map(fn ($s) => \App\Models\Employee::statusLabel($s))->implode(', ') ?: 'Tất cả';
    $deptSummary = $departmentId && isset($departmentOptions[$departmentId]) ? trim(ltrim($departmentOptions[$departmentId], ' ›')) : 'tất cả đơn vị';
    $compareText = $filter->compare === DashboardFilter::COMPARE_LAST_YEAR ? 'so với cùng kỳ năm trước' : 'so với kỳ trước';
    $hasFilters = $chips !== [] || $preset !== '30d' || $statusFilter;

    // Yêu cầu Livewire làm đổi số liệu → hiện khung xương (skeleton) cho thẻ và bảng
    $filterTargets = 'setPreset,from,to,compare,departmentId,jobTitleId,courseType,employmentStatuses,resetFilters,clearFilter';
    $tableTargets = $filterTargets . ',filterStatus,gotoPage,nextPage,previousPage,perPage';
@endphp

<div class="dash" x-data="{ filtersOpen: false }">
    {{-- CSS riêng của trang: bố cục, kích thước chữ theo góp ý fb7 (mục 2, 8, 10), bản in.
         Viết thuần để không phụ thuộc lần build Tailwind tiếp theo. --}}
    <style>
        .dash { font-variant-numeric: tabular-nums; }
        .dash-title { font-size: 26px; font-weight: 700; letter-spacing: -0.4px; line-height: 1.25; }
        .dash-sub { font-size: 13.5px; color: #5A6572; }

        /* Thanh lọc một hàng */
        .dash-tabs { display: inline-flex; gap: 2px; padding: 3px; border-radius: 10px; background: #eceef2; }
        .dash-tab { height: 34px; padding: 0 12px; border-radius: 8px; font-size: 13.5px; font-weight: 500; color: #5A6572; white-space: nowrap; }
        .dash-tab:hover { color: #11161C; }
        .dash-tab[aria-pressed="true"] { background: #fff; color: #c00016; font-weight: 600; box-shadow: 0 1px 2px rgba(17, 22, 28, .12); }
        .dash-tab:focus-visible, .dash-chip button:focus-visible, .dash-icon-btn:focus-visible { outline: 2px solid #c00016; outline-offset: 1px; }
        .dash-chip { display: inline-flex; align-items: center; gap: 4px; height: 30px; padding: 0 4px 0 12px; border: 1px solid #E6E9ED;
                     border-radius: 20px; background: #fff; font-size: 12.5px; font-weight: 600; color: #11161C; }
        .dash-chip button { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 999px; color: #5A6572; }
        .dash-chip button:hover { background: #f1f3f5; color: #c00016; }
        .dash-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 600; color: #11161C; }

        /* Thẻ KPI */
        .dash-kpis { display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .dash-kpi { position: relative; display: flex; min-width: 0; flex-direction: column; padding: 18px 20px; text-align: left;
                    border: 1px solid #E6E9ED; border-radius: 12px; background: #fff; }
        button.dash-kpi { cursor: pointer; transition: border-color .15s; }
        button.dash-kpi:hover { border-color: #b9bfc8; }
        .dash-kpi.is-active { border-color: #11161C; box-shadow: inset 0 0 0 1px #11161C; }
        .dash-kpi-label { font-size: 15px; font-weight: 600; color: #11161C; }
        .dash-kpi-value { display: block; margin-top: 8px; font-size: 40px; font-weight: 700; line-height: 1; letter-spacing: -1.2px; color: #11161C; }
        .dash-kpi-value.is-alert { color: #c00016; }
        .dash-kpi-delta { font-size: 13px; font-weight: 600; }
        .dash-kpi-note { display: block; margin-top: 10px; padding-top: 10px; border-top: 1px solid #EDF0F3; font-size: 13.5px; color: #5A6572;
                         overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dash-kpi-flag { position: absolute; top: 14px; right: 14px; padding: 2px 8px; border-radius: 20px; background: #11161C;
                         font-size: 11.5px; font-weight: 700; color: #fff; }
        .dash-kpi-empty { display: flex; flex: 1; align-items: center; gap: 10px; margin-top: 10px; padding: 12px; border-radius: 9px;
                          background: #f6f7f9; font-size: 13.5px; color: #5A6572; }

        /* Khung xương khi đang tải */
        .dash-skel { border-radius: 6px; background: linear-gradient(90deg, #eef0f3 25%, #f6f7f9 50%, #eef0f3 75%);
                     background-size: 200% 100%; animation: dash-shimmer 1.2s linear infinite; }
        @keyframes dash-shimmer { from { background-position: 200% 0; } to { background-position: -200% 0; } }
        @media (prefers-reduced-motion: reduce) { .dash-skel { animation: none; } }

        .dash-grid { display: grid; gap: 16px; }
        .dash-alerts { display: grid; gap: 12px; }
        .dash-alert { display: flex; min-height: 52px; align-items: center; gap: 12px; padding: 10px 16px; border: 1px solid #E6E9ED;
                      border-radius: 12px; background: #fff; font-size: 14px; color: #11161C; }
        a.dash-alert:hover { border-color: #b9bfc8; }

        /* Bảng nhân viên cần nhắc: dòng cố định 52px, chữ dài cắt "…" + tooltip */
        .dash-check { width: 16px; height: 16px; accent-color: #c00016; cursor: pointer; }
        .dash-check:disabled { cursor: not-allowed; opacity: .4; }
        .dash-bar { height: 6px; overflow: hidden; border-radius: 5px; background: #EDF0F3; }
        .dash-bar > span { display: block; height: 100%; border-radius: 5px; }
        .dash-table { min-width: 940px; }
        .dash-table td { height: 52px; padding-top: 0; padding-bottom: 0; }
        .dash-table tbody tr:hover { background: #F6F7F9; }
        .dash-ellipsis { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dash-code { font-weight: 600; color: #3b4350; }
        a.dash-code:hover { color: #c00016; text-decoration: underline; }
        .dash-pager button { display: inline-grid; place-items: center; min-width: 34px; height: 34px; padding: 0 6px; border-radius: 9px;
                             border: 1px solid #E6E9ED; background: #fff; font-size: 13.5px; color: #11161C; }
        .dash-pager button:hover:not(:disabled) { background: #f6f7f9; }
        .dash-pager button[aria-current="page"] { border-color: #11161C; background: #11161C; font-weight: 600; color: #fff; }
        .dash-pager button:disabled { opacity: .4; cursor: not-allowed; }

        @media (min-width: 768px) {
            .dash-alerts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 1280px) {
            .dash-kpis { grid-template-columns: repeat(5, minmax(0, 1fr)); }
            .dash-grid-trend { grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr); }
            .dash-grid-units { grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); }
        }
        .dash-mobile-only { display: none; }
        @media (max-width: 639px) {
            .dash-desktop-only { display: none !important; }
            .dash-mobile-only { display: block; }
            .dash-kpi-value { font-size: 30px; }
            .dash-units-more:not(.is-open) { display: none; }
            .dash-table { min-width: 720px; }
            .dash-sticky { position: sticky; left: 0; z-index: 1; background: #fff; box-shadow: 1px 0 0 #E6E9ED; }
        }
        /* "Xuất PDF" = window.print(): ẩn sidebar, thanh trên, nút và ô chọn */
        @media print {
            body > div > aside, body > div > div > header, .dash-no-print { display: none !important; }
            body { background: #fff !important; }
            main { padding: 0 !important; }
            .dash .admin-panel, .dash-kpi { break-inside: avoid; }
            .dash-print-filters { display: block !important; }
            .dash-table { min-width: 0; }
        }
        .dash-print-filters { display: none; }
    </style>

    {{-- Đầu trang: tiêu đề + 2 nút (Thao tác ⋯, Báo cáo chi tiết) --}}
    <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="dash-title">Tổng quan đào tạo</h2>
            <p class="dash-sub mt-1">Cập nhật {{ $nowVn->format('H:i') }} hôm nay · {{ $fmtInt($headcount) }} nhân sự đang theo dõi</p>
            <p class="dash-print-filters dash-sub mt-1">
                Kỳ {{ $period->label() }} · {{ $compareText }} · {{ $deptSummary }}
                · {{ DashboardFilter::COURSE_TYPES[$filter->courseType] }} · Nhân sự: {{ $statusSummary }}
            </p>
        </div>

        <div class="dash-no-print flex shrink-0 flex-wrap items-center gap-2">
            <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
                <button type="button" x-on:click="open = ! open" class="admin-btn-secondary" aria-haspopup="menu" x-bind:aria-expanded="open">
                    Thao tác @svg('heroicon-m-ellipsis-horizontal', 'h-4 w-4')
                </button>
                <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity role="menu"
                     class="absolute right-0 z-30 mt-1 w-56 rounded-admin border border-brand-line bg-white py-1"
                     style="box-shadow: 0 8px 24px rgba(17, 22, 28, .10)">
                    <button type="button" role="menuitem" wire:click="$refresh" x-on:click="open = false"
                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-brand-soft">
                        @svg('heroicon-o-arrow-path', 'h-4 w-4 text-brand-muted') Làm mới
                    </button>
                    {{-- Xuất PDF = hộp thoại in của trình duyệt (Lưu thành PDF); CSS @media print ẩn sidebar/nút --}}
                    <button type="button" role="menuitem" onclick="window.print()" x-on:click="open = false"
                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-brand-soft">
                        @svg('heroicon-o-printer', 'h-4 w-4 text-brand-muted') Xuất PDF
                    </button>
                    <a href="{{ $excelUrl }}" role="menuitem" x-on:click="open = false"
                       class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-brand-soft">
                        @svg('heroicon-o-table-cells', 'h-4 w-4 text-brand-muted') Xuất Excel
                    </a>
                    <a href="{{ $templateUrl }}" target="_blank" rel="noopener" role="menuitem" x-on:click="open = false"
                       class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-brand-soft">
                        @svg('heroicon-o-document-text', 'h-4 w-4 text-brand-muted') Báo cáo mẫu
                    </a>
                </div>
            </div>
            @if ($reportLinks->isNotEmpty())
                <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
                    <button type="button" x-on:click="open = ! open" class="admin-btn" aria-haspopup="menu" x-bind:aria-expanded="open">
                        Báo cáo chi tiết @svg('heroicon-m-chevron-down', 'h-4 w-4')
                    </button>
                    <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity role="menu"
                         class="absolute right-0 z-30 mt-1 w-56 rounded-admin border border-brand-line bg-white py-1"
                         style="box-shadow: 0 8px 24px rgba(17, 22, 28, .10)">
                        @foreach ($reportLinks as $route => $label)
                            <a href="{{ route($route, $periodParams) }}" wire:navigate role="menuitem"
                               class="block px-3 py-2 text-sm hover:bg-brand-soft">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Thanh lọc một hàng: tab kỳ · nút "Bộ lọc (n)" · chip đang lọc · Đặt lại --}}
    <section class="dash-no-print mb-4 flex flex-wrap items-center gap-2" aria-label="Bộ lọc">
        <div class="dash-tabs" role="group" aria-label="Kỳ báo cáo">
            @foreach (ReportPeriod::PRESETS as $key => $text)
                <button type="button" wire:click="setPreset('{{ $key }}')" class="dash-tab"
                        aria-pressed="{{ $preset === $key ? 'true' : 'false' }}">{{ $text }}</button>
            @endforeach
        </div>

        @if ($preset === 'custom')
            <div class="flex items-center gap-1.5">
                <label class="sr-only" for="dash-from">Từ ngày</label>
                <input id="dash-from" type="date" wire:model.live="from" class="admin-input w-auto" style="height: 40px">
                <span class="text-brand-muted" aria-hidden="true">–</span>
                <label class="sr-only" for="dash-to">Đến ngày</label>
                <input id="dash-to" type="date" wire:model.live="to" class="admin-input w-auto" style="height: 40px">
            </div>
        @endif

        <div class="relative" x-on:keydown.escape.window="filtersOpen = false">
            <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="admin-btn-secondary" style="height: 40px"
                    aria-haspopup="dialog" x-bind:aria-expanded="filtersOpen" aria-controls="dash-filter-pop">
                @svg('heroicon-o-adjustments-horizontal', 'h-4 w-4')
                Bộ lọc{{ count($chips) ? ' (' . count($chips) . ')' : '' }}
            </button>
            <div id="dash-filter-pop" x-show="filtersOpen" x-cloak x-on:click.outside="filtersOpen = false" x-transition.opacity
                 role="dialog" aria-label="Bộ lọc" class="absolute left-0 z-40 mt-2 w-[min(92vw,560px)] rounded-admin border border-brand-line bg-white p-4"
                 style="box-shadow: 0 12px 32px rgba(17, 22, 28, .12)">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="dash-dept" class="dash-field-label">Đơn vị</label>
                        <select id="dash-dept" wire:model.live="departmentId" class="admin-input" title="Chọn một đơn vị là gồm cả các phòng con">
                            <option value="">Tất cả ({{ count($departmentOptions) }} đơn vị)</option>
                            @foreach ($departmentOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="dash-title" class="dash-field-label">Chức danh</label>
                        <select id="dash-title" wire:model.live="jobTitleId" class="admin-input">
                            <option value="">Tất cả</option>
                            @foreach ($jobTitleOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="dash-type" class="dash-field-label">Loại khóa</label>
                        <select id="dash-type" wire:model.live="courseType" class="admin-input">
                            @foreach (DashboardFilter::COURSE_TYPES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="dash-compare" class="dash-field-label">So sánh với</label>
                        <select id="dash-compare" wire:model.live="compare" class="admin-input">
                            @foreach (DashboardFilter::COMPARES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <fieldset class="sm:col-span-2">
                        <legend class="dash-field-label">Trạng thái nhân sự</legend>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            @foreach (\App\Models\Employee::STATUS_LABELS as $key => $label)
                                <label class="flex cursor-pointer items-center gap-2 text-sm">
                                    <input type="checkbox" class="dash-check" wire:model.live="employmentStatuses" value="{{ $key }}">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-1.5 text-xs text-[#5A6572]">Bỏ chọn hết = mọi trạng thái.</p>
                    </fieldset>
                </div>
                <div class="mt-4 flex justify-end border-t border-brand-line pt-3">
                    <button type="button" x-on:click="filtersOpen = false" class="admin-btn-secondary">Xong</button>
                </div>
            </div>
        </div>

        @foreach ($chips as $key => $text)
            <span class="dash-chip">
                {{ $text }}
                <button type="button" wire:click="clearFilter('{{ $key }}')" aria-label="Bỏ lọc {{ $text }}">
                    @svg('heroicon-m-x-mark', 'h-3.5 w-3.5')
                </button>
            </span>
        @endforeach

        @if ($hasFilters)
            <button type="button" wire:click="resetFilters" class="ml-auto text-[13.5px] font-semibold text-[#5A6572] hover:text-brand-red">Đặt lại</button>
        @endif
    </section>

    {{-- 5 chỉ số then chốt --}}
    @php $deltaClass = fn ($tone) => ['good' => 'text-state-success', 'bad' => 'text-brand-red'][$tone] ?? 'text-[#5A6572]'; @endphp
    {{-- Bọc ngoài để class "hidden" của wire:loading không bị display:grid của .dash-kpis đè --}}
    <div class="hidden" wire:loading.delay.class.remove="hidden" wire:target="{{ $filterTargets }}" aria-hidden="true">
        <div class="dash-kpis mb-4">
            @foreach ($cards as $card)
                <div class="dash-kpi">
                    <span class="dash-skel" style="height: 16px; width: 60%"></span>
                    <span class="dash-skel mt-3" style="height: 38px; width: 45%"></span>
                    <span class="dash-skel mt-3" style="height: 28px; width: 100%"></span>
                    <span class="dash-skel mt-3" style="height: 14px; width: 70%"></span>
                </div>
            @endforeach
        </div>
    </div>
    <div wire:loading.delay.class="hidden" wire:target="{{ $filterTargets }}">
    <div class="dash-kpis mb-4">
        @foreach ($cards as $key => $card)
            @php
                $filterKey = $card['filter'] ?? null;
                $active = $filterKey && $statusFilter === $filterKey;
                $tag = $filterKey ? 'button' : 'div';
            @endphp
            <{{ $tag }} @if ($filterKey) type="button" wire:click="filterStatus('{{ $filterKey }}')" aria-pressed="{{ $active ? 'true' : 'false' }}"
                    title="{{ $active ? 'Bấm để bỏ lọc bảng' : 'Bấm để lọc bảng “Nhân viên cần nhắc” theo ' . mb_strtolower($statusLabels[$filterKey]) }}" @endif
                @class(['dash-kpi', 'is-active' => $active])>
                <span class="dash-kpi-label">{{ $card['label'] }}</span>
                @if ($active)
                    <span class="dash-kpi-flag">Đang lọc bảng</span>
                @endif

                @if ($card['empty'])
                    {{-- Chưa đủ dữ liệu để tính: nói rõ thay vì để dấu "—" giữa hàng --}}
                    <span class="dash-kpi-empty">
                        @svg('heroicon-o-inbox', 'h-5 w-5 shrink-0 text-[#6B7480]')
                        <span>{{ $card['empty'] }}</span>
                    </span>
                @else
                    <strong @class(['dash-kpi-value', 'is-alert' => $card['alert']])>{{ $card['value'] }}</strong>
                    <span class="mt-3 flex min-h-[32px] items-end justify-between gap-2">
                        <span class="dash-kpi-delta {{ $deltaClass($card['delta'][1]) }}">
                            {{ $card['delta'][0] }}
                            @if ($loop->first && $card['delta'][0])
                                <span class="block font-normal text-[#5A6572]">{{ $compareText }}</span>
                            @endif
                        </span>
                        <x-admin.chart.sparkline :values="$card['spark']" :color="$card['alert'] ? '#c00016' : '#141821'"
                                                 :width="96" :height="32" :fill="true" :dot="true" :stroke-width="1.8"
                                                 :label="'Xu hướng ' . mb_strtolower($card['label']) . ' trong kỳ'" />
                    </span>
                    <span class="dash-kpi-note" title="{{ $card['note'] }}">{{ $card['note'] }}</span>
                @endif
            </{{ $tag }}>
        @endforeach
    </div>
    </div>

    {{-- Cảnh báo nghiệp vụ đào tạo: ẩn khi bằng 0 --}}
    @php
        $vnToday = $nowVn->copy()->startOfDay();
        $overdueAlert = $alerts['overdue'];
        $compliance = $alerts['compliance'];
        $overdueLink = $canLearning ? route('admin.reports.learning', [
            'moc' => 'due', 'ky' => 'custom', 'tu' => $vnToday->copy()->subDays(365)->toDateString(),
            'den' => $vnToday->copy()->subDays(Metrics::LONG_OVERDUE_DAYS + 1)->toDateString(),
            'trang-thai' => 'overdue', 'loai' => 'mandatory', 'sap-xep' => 'overdue_days', 'chieu' => 'desc',
        ]) : null;
        $complianceLink = $canLearning ? route('admin.reports.learning', [
            'moc' => 'due', 'ky' => 'custom', 'tu' => $vnToday->toDateString(),
            'den' => $vnToday->copy()->addDays(Metrics::EXPIRING_DAYS)->toDateString(), 'loai' => 'mandatory',
        ]) : null;
    @endphp
    @if ($overdueAlert['employees'] > 0 || $compliance['courses'] > 0)
        <div class="dash-alerts mb-4">
            @if ($overdueAlert['employees'] > 0)
                <{{ $overdueLink ? 'a' : 'div' }} @if ($overdueLink) href="{{ $overdueLink }}" wire:navigate @endif class="dash-alert">
                    @svg('heroicon-o-exclamation-triangle', 'h-5 w-5 shrink-0 text-brand-red')
                    <span class="min-w-0 flex-1">
                        <strong class="font-bold text-brand-red">{{ $fmtInt($overdueAlert['employees']) }} nhân viên</strong>
                        quá hạn khóa bắt buộc trên {{ Metrics::LONG_OVERDUE_DAYS }} ngày
                        <span class="text-[#5A6572]">· {{ $fmtInt($overdueAlert['enrollments']) }} lượt</span>
                    </span>
                    @if ($overdueLink)
                        <span class="dash-no-print shrink-0 text-[13.5px] font-semibold">Xem danh sách →</span>
                    @endif
                </{{ $overdueLink ? 'a' : 'div' }}>
            @endif
            @if ($compliance['courses'] > 0)
                <{{ $complianceLink ? 'a' : 'div' }} @if ($complianceLink) href="{{ $complianceLink }}" wire:navigate @endif class="dash-alert">
                    @svg('heroicon-o-clock', 'h-5 w-5 shrink-0 text-state-warning')
                    <span class="min-w-0 flex-1">
                        @if ($compliance['kind'] === 'certificate')
                            <strong class="font-bold">{{ $fmtInt($compliance['courses']) }} khóa tuân thủ</strong>
                            hết hiệu lực chứng chỉ trong {{ Metrics::EXPIRING_DAYS }} ngày
                            <span class="text-[#5A6572]">· {{ $fmtInt($compliance['items']) }} chứng nhận</span>
                        @else
                            <strong class="font-bold">{{ $fmtInt($compliance['courses']) }} khóa bắt buộc</strong>
                            có lượt chưa hoàn thành đến hạn trong {{ Metrics::EXPIRING_DAYS }} ngày tới
                            <span class="text-[#5A6572]">· {{ $fmtInt($compliance['items']) }} lượt</span>
                        @endif
                    </span>
                    @if ($complianceLink)
                        <span class="dash-no-print shrink-0 text-[13.5px] font-semibold">Xem →</span>
                    @endif
                </{{ $complianceLink ? 'a' : 'div' }}>
            @endif
        </div>
    @endif

    {{-- Xu hướng + phân bố trạng thái --}}
    @php
        $compareName = DashboardFilter::COMPARES[$filter->compare];
        $trendEmpty = array_sum($trend['completions']) + array_sum($trend['active']) + array_sum(array_filter($trend['previous'])) === 0;
    @endphp
    <div class="dash-grid dash-grid-trend mb-4">
        <x-admin.report.panel title="Xu hướng học tập"
                              :subtitle="$period->startLocal->format('d/m') . ' – ' . $period->endLocal->format('d/m/Y') . ', theo ngày'"
                              :note="e($compareName) . ': ' . e($filter->comparePeriod()->label()) . ', căn theo thứ tự ngày trong kỳ. Rê chuột lên biểu đồ để xem số từng ngày.'">
            <x-slot:actions>
                <x-admin.chart.legend :items="[
                    ['label' => 'Lượt hoàn thành', 'color' => '#141821', 'type' => 'line'],
                    ['label' => 'Học viên hoạt động', 'color' => '#8a919c', 'type' => 'line'],
                    ['label' => $compareName, 'color' => '#b5bac2', 'type' => 'dashed'],
                ]" />
            </x-slot:actions>

            @if ($trendEmpty)
                <p class="py-12 text-center text-sm text-[#5A6572]">Chưa có lượt hoàn thành hay hoạt động học nào trong kỳ này.</p>
            @else
                <x-admin.chart.combo :labels="$trend['labels']" :height="260" :crosshair="true"
                                     left-title="Lượt hoàn thành / ngày" right-title="Học viên hoạt động"
                                     :lines="[
                                         ['name' => 'Lượt hoàn thành', 'values' => $trend['completions'], 'color' => '#141821', 'axis' => 'left', 'area' => true],
                                         ['name' => 'Học viên hoạt động', 'values' => $trend['active'], 'color' => '#8a919c', 'axis' => 'right'],
                                         ['name' => $compareName . ' (lượt hoàn thành)', 'values' => $trend['previous'], 'color' => '#b5bac2', 'axis' => 'left', 'dashed' => true],
                                     ]"
                                     label="Lượt hoàn thành và học viên hoạt động theo ngày" />
            @endif
        </x-admin.report.panel>

        <x-admin.report.panel title="Phân bố trạng thái ghi danh"
                              :subtitle="$fmtInt($statusTotal) . ' lượt trong phạm vi lọc'"
                              :note="$statusTotal > 0 ? 'Bấm vào một trạng thái để lọc bảng “Nhân viên cần nhắc” bên dưới.' : null">
            @if ($statusTotal === 0)
                <p class="py-12 text-center text-sm text-[#5A6572]">Không có lượt ghi danh nào trong phạm vi lọc.</p>
            @else
                <x-admin.chart.donut :data="collect($statusLabels)->map(fn ($label, $key) => [
                                        'key' => $key, 'label' => $label, 'value' => $statusCounts[$key],
                                        'color' => $statusColors[$key], 'danger' => $key === 'overdue',
                                     ])->values()->all()"
                                     center-label="lượt ghi danh" click-action="filterStatus"
                                     :headers="['label' => 'Trạng thái', 'value' => 'Số lượt', 'percent' => 'Tỷ lệ']"
                                     :active-key="$statusFilter" label="Phân bố trạng thái ghi danh" />
            @endif
        </x-admin.report.panel>
    </div>

    {{-- Đơn vị + khóa cần can thiệp --}}
    @php
        $unitRows = $units['rows']->map(fn ($u) => [
            'label' => $u['name'],
            'segments' => collect($statusLabels)->map(fn ($label, $key) => [
                'value' => $u['total'] > 0 ? $u['counts'][$key] / $u['total'] * 100 : 0,
                'color' => $statusColors[$key],
                'name' => $label,
                'text' => $fmtInt($u['counts'][$key]) . ' lượt',
            ])->values()->all(),
            'value' => $fmtPct($u['rate']),
            'tone' => ! $u['small'] && $u['rate'] < 40 ? 'danger' : null,
            'muted' => $u['small'],
        ]);
        $smallUnits = $units['rows']->where('small', true);
        $scopeName = $filter->departmentId ? 'toàn đơn vị đã chọn' : 'toàn hàng';
        // Cách đọc biểu đồ đưa vào tooltip ⓘ thay vì dòng chữ dưới tiêu đề
        $unitNote = $units['overall'] === null ? null : 'Đơn vị chậm nhất xếp trên. Vạch đứt = tỷ lệ hoàn thành ' . $scopeName . ' ' . $fmtPct($units['overall']) . '.'
            . ($smallUnits->isEmpty() ? '' : '<br>Mẫu nhỏ: ' . $smallUnits->take(3)
                ->map(fn ($u) => e($u['name']) . ' chỉ có ' . $u['total'] . ' lượt ghi danh')->implode('; ')
                . ($smallUnits->count() > 3 ? '; …' : '') . ' trong kỳ — tỷ lệ dễ dao động.');
    @endphp
    <div class="dash-grid dash-grid-units mb-4">
        <x-admin.report.panel title="Tiến độ theo đơn vị" :note="$unitNote">
            @if ($unitRows->isEmpty())
                <p class="py-12 text-center text-sm text-[#5A6572]">Không có lượt ghi danh nào trong phạm vi lọc.</p>
            @else
                <x-admin.chart.hbar class="dash-desktop-only" :rows="$unitRows" :max="100" :benchmark="$units['overall']" label-width="10rem" value-width="5.5rem"
                                     :headers="['label' => 'Đơn vị', 'bar' => 'Phân bố trạng thái', 'value' => 'Hoàn thành']" />

                {{-- Màn hẹp: nhãn nằm trên thanh (không đủ chỗ cho cột nhãn), 4 dòng đầu + "Xem cả" --}}
                <div class="dash-mobile-only" x-data="{ unitsOpen: false }">
                    @foreach ($unitRows as $i => $u)
                        <div class="{{ $i >= 4 ? 'dash-units-more' : '' }} mb-2.5" @if ($i >= 4) x-bind:class="unitsOpen && 'is-open'" @endif
                             style="{{ $u['muted'] ? 'opacity: .5' : '' }}">
                            <div class="mb-1 flex justify-between gap-2 text-[13px]">
                                <span class="min-w-0 truncate">{{ $u['label'] }}</span>
                                <strong class="shrink-0 tabular-nums">{{ $u['value'] }}</strong>
                            </div>
                            <div class="relative flex overflow-hidden rounded-sm" style="height: 12px; background: #EDF0F3">
                                @foreach ($u['segments'] as $seg)
                                    <span style="width: {{ $seg['value'] }}%; background: {{ $seg['color'] }}"></span>
                                @endforeach
                                @if ($units['overall'] !== null)
                                    <span class="absolute border-l border-dashed border-brand-ink" style="top: 0; bottom: 0; left: {{ $units['overall'] }}%"></span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    @if ($unitRows->count() > 4)
                        <button type="button" x-on:click="unitsOpen = ! unitsOpen" class="dash-no-print admin-btn-secondary mt-3 w-full"
                                x-text="unitsOpen ? 'Thu gọn' : 'Xem cả {{ $unitRows->count() }} đơn vị'">Xem cả {{ $unitRows->count() }} đơn vị</button>
                    @endif
                </div>
                <x-admin.chart.legend class="mt-4 border-t border-brand-line pt-3"
                                      :items="collect($statusLabels)->map(fn ($l, $k) => ['label' => $l, 'color' => $statusColors[$k]])->values()->all()" />
            @endif
        </x-admin.report.panel>

        <x-admin.report.panel title="Khóa cần can thiệp"
                              note="Xếp theo số lượt quá hạn giảm dần, chỉ xét khóa có từ {{ Metrics::COURSE_MIN_ENROLLMENTS }} lượt ghi danh trong kỳ. Tiến độ = trung bình % tiến độ các lượt.">
            @if ($learningUrl && $courseCount > 0)
                <x-slot:actions>
                    <a href="{{ $learningUrl }}" wire:navigate class="dash-no-print text-[13.5px] font-semibold text-brand-ink hover:text-brand-red hover:underline">Xem cả {{ $courseCount }} →</a>
                </x-slot:actions>
            @endif

            @if ($courses->isNotEmpty())
                <div class="mb-3 flex items-end gap-3 border-b border-brand-line pb-1.5 text-[11px] font-bold uppercase tracking-wide text-[#5A6572]">
                    <span class="min-w-0 flex-1">Khóa học</span>
                    <span class="w-20 shrink-0 text-right">Tiến độ TB</span>
                    <span class="w-24 shrink-0 text-right" aria-sort="descending">Quá hạn ▼</span>
                </div>
            @endif
            <div class="grid gap-4">
                @forelse ($courses as $course)
                    @php $avg = (float) $course->avg_progress; @endphp
                    <div wire:key="course-{{ $course->id }}">
                        <div class="mb-1.5 flex items-start justify-between gap-3">
                            <span class="dash-ellipsis min-w-0 text-[13.5px] font-semibold" title="{{ $course->title }}">{{ $course->title }}</span>
                            <span class="flex shrink-0 gap-1">
                                @if ($course->is_onboarding)
                                    <span class="tag">Hội nhập</span>
                                @endif
                                @if ($course->mandatory * 2 > $course->total)
                                    <span class="tag">Bắt buộc</span>
                                @endif
                            </span>
                        </div>
                        <div class="flex items-center gap-3 text-[13px]">
                            <div class="dash-bar min-w-0 flex-1" role="progressbar" aria-valuenow="{{ round($avg) }}" aria-valuemin="0" aria-valuemax="100"
                                 aria-label="Tiến độ trung bình {{ $course->title }}">
                                <span style="width: {{ min(100, $avg) }}%; background: {{ $progressColor($avg) }}"></span>
                            </div>
                            <span class="w-20 shrink-0 text-right tabular-nums" style="color: {{ $avg < 40 ? '#c00016' : '#11161C' }}">{{ $fmtPct($avg) }}</span>
                            <span class="w-24 shrink-0 text-right font-semibold text-brand-red tabular-nums">{{ $fmtInt($course->overdue) }} lượt</span>
                        </div>
                    </div>
                @empty
                    <p class="py-12 text-center text-sm text-[#5A6572]">Không có khóa nào (từ {{ Metrics::COURSE_MIN_ENROLLMENTS }} lượt ghi danh) có lượt quá hạn trong phạm vi lọc.</p>
                @endforelse
            </div>
        </x-admin.report.panel>
    </div>

    {{-- Nhân viên cần nhắc --}}
    @php
        $reminder = app(\App\Services\LearningReminderService::class);
        $today = $nowVn->copy()->startOfDay();
        $canRemind = (bool) $user?->can('report_learning.update');
        // Ô chọn chỉ cho lượt nhắc được: chưa hoàn thành, không trong thời gian chờ
        $pageIds = $rows->getCollection()->filter(fn ($r) => $r->status !== 'completed' && $reminder->canRemind($r))
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        $allPageSelected = $pageIds && ! array_diff($pageIds, $selected);
        $colspan = $canRemind ? 8 : 7;
    @endphp
    <section class="admin-panel min-w-0">
        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                <h2 class="text-[18px] font-semibold">Nhân viên cần nhắc</h2>
                <span class="text-[13.5px] text-[#5A6572]">{{ $fmtInt($rows->total()) }} lượt{{ $statusFilter ? '' : ' chưa hoàn thành' }}</span>
                @if ($statusFilter && isset($statusLabels[$statusFilter]))
                    <span class="dash-chip">
                        Đang lọc: {{ $statusLabels[$statusFilter] }}
                        <button type="button" wire:click="filterStatus('{{ $statusFilter }}')" aria-label="Bỏ lọc trạng thái">
                            @svg('heroicon-m-x-mark', 'h-3.5 w-3.5')
                        </button>
                    </span>
                @endif
            </div>
            <div class="dash-no-print flex flex-wrap items-center gap-2">
                @if ($canRemind && $rows->total() > 0)
                    <button type="button" wire:click="remindSelected" class="admin-btn disabled:cursor-not-allowed disabled:opacity-40"
                            x-bind:disabled="$wire.selected.length === 0"
                            data-confirm="Gửi lời nhắc học tới các nhân viên đã chọn? Lượt đã nhắc trong {{ \App\Services\LearningReminderService::COOLDOWN_HOURS }} giờ qua sẽ được bỏ qua."
                            data-confirm-title="Gửi nhắc" data-confirm-ok="Gửi nhắc">
                        @svg('heroicon-o-bell-alert', 'h-4 w-4')
                        Gửi nhắc<span x-show="$wire.selected.length > 0" x-text="' (' + $wire.selected.length + ')'"></span>
                    </button>
                @endif
                @if ($learningUrl)
                    <a href="{{ $learningUrl }}" wire:navigate class="admin-btn-secondary dash-desktop-only">Xem tất cả trong báo cáo →</a>
                @endif
            </div>
        </header>

        @if ($notice)
            <div class="mx-5 mb-3 flex items-center justify-between gap-3 rounded-admin border border-brand-line bg-brand-soft px-3 py-2 text-[13.5px] font-semibold text-brand-ink" role="status">
                <span>{{ $notice }}</span>
                <button type="button" wire:click="$set('notice', null)" class="shrink-0" aria-label="Đóng thông báo">@svg('heroicon-m-x-mark', 'h-4 w-4')</button>
            </div>
        @endif

        <div class="overflow-x-auto border-t border-brand-line">
            <table class="admin-table dash-table">
                <thead>
                    <tr>
                        @if ($canRemind)
                            <th class="dash-no-print" style="width: 44px">
                                <input type="checkbox" class="dash-check" aria-label="Chọn tất cả dòng trong trang"
                                       @checked($allPageSelected) @disabled(! $pageIds) wire:click="togglePage(@js($pageIds))">
                            </th>
                        @endif
                        <th style="width: 96px">Mã NV</th>
                        <th class="dash-sticky" style="width: 170px">Họ tên</th>
                        <th style="width: 170px">Đơn vị</th>
                        <th style="width: 220px">Khóa học</th>
                        <th style="width: 150px">Tiến độ</th>
                        <th style="width: 100px">Hạn</th>
                        <th class="text-right" style="width: 100px" aria-sort="descending">Quá hạn <span aria-hidden="true">▼</span></th>
                    </tr>
                </thead>
                {{-- Khung xương khi đổi bộ lọc / trang --}}
                <tbody class="hidden" wire:loading.delay.class.remove="hidden" wire:target="{{ $tableTargets }}" aria-hidden="true">
                    @foreach (range(1, 6) as $_)
                        <tr>
                            @if ($canRemind)<td class="dash-no-print"><span class="dash-skel block" style="height: 16px; width: 16px"></span></td>@endif
                            @foreach ([60, 80, 70, 85, 75, 60, 40] as $w)
                                <td><span class="dash-skel block" style="height: 12px; width: {{ $w }}%"></span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tbody wire:loading.delay.class="hidden" wire:target="{{ $tableTargets }}">
                    @forelse ($rows as $row)
                        @php
                            $employee = $row->employee;
                            $dept = $employee?->department;
                            $dueDate = $row->due_date ? \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $row->due_date->toDateString(), ReportPeriod::TIMEZONE)->startOfDay() : null;
                            $isDone = $row->status === 'completed';
                            $daysLate = ! $isDone && $dueDate && $dueDate->lt($today) ? (int) $dueDate->diffInDays($today) : null;
                            $daysLeft = ! $isDone && $dueDate && $dueDate->gte($today) ? (int) $today->diffInDays($dueDate) : null;
                            $progress = $isDone ? 100 : (float) $row->progress_percent;
                            $cooling = ! $isDone && ! $reminder->canRemind($row);
                            $coolText = $cooling
                                ? 'Đã nhắc ' . ReportPeriod::local($row->last_reminded_at)?->format('H:i')
                                    . ' · nhắc lại sau ' . ReportPeriod::local(\App\Services\LearningReminderService::nextRemindAt($row->last_reminded_at))?->format('H:i')
                                : null;
                        @endphp
                        <tr wire:key="remind-{{ $row->id }}">
                            @if ($canRemind)
                                <td class="dash-no-print">
                                    @unless ($isDone)
                                        <input type="checkbox" class="dash-check" wire:model="selected" value="{{ $row->id }}"
                                               @disabled($cooling) @if ($coolText) title="{{ $coolText }}" @endif
                                               aria-label="Chọn {{ $employee?->full_name }} – {{ $row->course?->title }}">
                                    @endunless
                                </td>
                            @endif
                            <td>
                                @if ($employee && $canOpen('admin.employees.show'))
                                    <a href="{{ route('admin.employees.show', $employee) }}" wire:navigate class="dash-code">{{ $employee->employee_code }}</a>
                                @else
                                    <span class="dash-code">{{ $employee?->employee_code }}</span>
                                @endif
                            </td>
                            <td class="dash-sticky">
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <span class="dash-ellipsis font-semibold" title="{{ $employee?->full_name }}">{{ $employee?->full_name }}</span>
                                    @if ($coolText)
                                        <span class="shrink-0 text-[#6B7480]" title="{{ $coolText }}">@svg('heroicon-o-clock', 'h-4 w-4')<span class="sr-only">{{ $coolText }}</span></span>
                                    @endif
                                </span>
                            </td>
                            <td><span class="dash-ellipsis text-[#5A6572]" title="{{ $dept?->name }}">{{ $dept?->name ?? '—' }}</span></td>
                            <td><span class="dash-ellipsis" title="{{ $row->course?->title }}">{{ $row->course?->title }}</span></td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="dash-bar min-w-0 flex-1" role="progressbar" aria-valuenow="{{ (int) round($progress) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Tiến độ">
                                        <span style="width: {{ min(100, $progress) }}%; background: {{ $progressColor($progress) }}"></span>
                                    </div>
                                    <span class="w-10 shrink-0 text-right text-[13px] font-semibold tabular-nums" style="color: {{ $progress < 40 ? '#c00016' : '#11161C' }}">{{ (int) round($progress) }}%</span>
                                </div>
                            </td>
                            <td class="tabular-nums">{{ $row->due_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="text-right tabular-nums">
                                @if ($isDone)
                                    <span class="text-[#5A6572]">Hoàn thành</span>
                                @elseif ($daysLate !== null)
                                    <strong class="text-brand-red">{{ $daysLate }} ngày</strong>
                                @elseif ($daysLeft !== null)
                                    <span class="text-[13px] text-[#5A6572]">{{ $daysLeft === 0 ? 'hạn hôm nay' : 'còn ' . $daysLeft . ' ngày' }}</span>
                                @else
                                    <span class="text-[#5A6572]">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $colspan }}" class="text-center text-[#5A6572]">
                                {{ $statusFilter ? 'Không có lượt ghi danh nào ở trạng thái này trong phạm vi lọc.' : 'Không có lượt ghi danh nào cần nhắc trong phạm vi lọc.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Chân bảng: "Hiển thị a–b trong N lượt" · phân trang gọn · số dòng/trang --}}
        @if ($rows->total() > 0)
            @php
                $current = $rows->currentPage();
                $last = $rows->lastPage();
                $pages = collect([1, $last, $current - 1, $current, $current + 1])
                    ->merge($current <= 3 ? [2, 3] : [])->merge($current >= $last - 2 ? [$last - 1, $last - 2] : [])
                    ->filter(fn ($p) => $p >= 1 && $p <= $last)->unique()->sort()->values();
            @endphp
            <footer class="dash-no-print flex flex-wrap items-center justify-between gap-3 border-t border-brand-line px-5 py-3 text-[13.5px] text-[#5A6572]">
                <span>Hiển thị {{ $fmtInt($rows->firstItem()) }}–{{ $fmtInt($rows->lastItem()) }} trong {{ $fmtInt($rows->total()) }} lượt</span>
                <div class="flex flex-wrap items-center gap-4">
                    @if ($last > 1)
                        <nav class="dash-pager flex items-center gap-1" aria-label="Phân trang bảng nhân viên cần nhắc">
                            <button type="button" wire:click="previousPage" @disabled($current === 1) aria-label="Trang trước">‹</button>
                            @foreach ($pages as $i => $p)
                                @if ($i > 0 && $p - $pages[$i - 1] > 1)
                                    <span class="px-1" aria-hidden="true">…</span>
                                @endif
                                <button type="button" wire:click="gotoPage({{ $p }})" @if ($p === $current) aria-current="page" @endif
                                        aria-label="Trang {{ $p }}">{{ $p }}</button>
                            @endforeach
                            <button type="button" wire:click="nextPage" @disabled($current === $last) aria-label="Trang sau">›</button>
                        </nav>
                    @endif
                    <label class="flex items-center gap-2">
                        <span>Mỗi trang</span>
                        <select wire:model.live="perPage" class="admin-input w-auto" style="height: 34px">
                            @foreach (\App\Livewire\Admin\Dashboard::PER_PAGE_OPTIONS as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </footer>
        @endif

        @if ($learningUrl)
            <div class="dash-mobile-only dash-no-print px-4 pb-4">
                <a href="{{ $learningUrl }}" wire:navigate class="admin-btn w-full">Mở báo cáo tiến độ học tập</a>
            </div>
        @endif
    </section>
</div>
