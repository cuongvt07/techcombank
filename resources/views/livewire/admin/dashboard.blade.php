@php
    use App\Services\DashboardFilter;
    use App\Services\DashboardMetricsService as Metrics;
    use App\Support\PermissionCatalog;
    use App\Support\Reports\ReportPeriod;

    $user = auth()->user();
    $fmtInt = fn ($v) => number_format((float) $v, 0, ',', '.');
    $fmtPct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . '%';

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
    $learningUrl = $canOpen('admin.reports.learning') ? route('admin.reports.learning', $periodParams) : null;

    $period = $filter->period;
    $statusTotal = array_sum($statusCounts);
    $statusLabels = Metrics::STATUS_LABELS;
    $statusColors = Metrics::STATUS_COLORS;
    $statusSummary = collect($employmentStatuses)->map(fn ($s) => \App\Models\Employee::statusLabel($s))->implode(', ') ?: 'Tất cả';
    $mobileSummary = (ReportPeriod::PRESETS[$preset] ?? $period->label()) . ', '
        . ($departmentId && isset($departmentOptions[$departmentId]) ? trim(ltrim($departmentOptions[$departmentId], ' ›')) : 'tất cả đơn vị');
@endphp

<div x-data="{ filtersOpen: false, unitsOpen: false }" class="dash">
    {{-- CSS riêng của trang: bố cục đáp ứng + bản in. Viết thuần để không phụ thuộc
         lần build Tailwind tiếp theo. --}}
    <style>
        .dash-kpis { display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .dash-kpi-list, .dash-mobile-only { display: none; }
        .dash-grid { display: grid; gap: 16px; }
        .dash-actions { display: grid; gap: 12px; }
        .dash-check { width: 16px; height: 16px; accent-color: #c00016; cursor: pointer; }
        .dash-bar { height: 6px; overflow: hidden; border-radius: 2px; background: #eef0f4; }
        .dash-bar > span { display: block; height: 100%; }
        .dash-table { min-width: 1040px; }
        @media (min-width: 768px) {
            .dash-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 1280px) {
            .dash-kpis { grid-template-columns: repeat(5, minmax(0, 1fr)); }
            .dash-grid-trend { grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr); }
            .dash-grid-units { grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); }
        }
        @media (max-width: 639px) {
            .dash-kpis, .dash-desktop-only { display: none !important; }
            .dash-kpi-list, .dash-mobile-only { display: block; }
            /* Màn hẹp: bộ lọc thu sau nút "Bộ lọc", đơn vị chỉ hiện 4 dòng đầu */
            .dash-filters:not(.is-open), .dash-units-more:not(.is-open) { display: none; }
            .dash-hide-sm { display: none; }
            .dash-table { min-width: 720px; }
            .dash-sticky { position: sticky; left: 0; z-index: 1; background: #fff; box-shadow: 1px 0 0 #e5e7eb; }
        }
        /* "Tải PDF tóm tắt" = window.print(): ẩn sidebar, thanh trên, nút và cột thao tác */
        @media print {
            body > div > aside, body > div > div > header, .dash-no-print { display: none !important; }
            body { background: #fff !important; }
            main { padding: 0 !important; }
            .dash .admin-panel { break-inside: avoid; }
            .dash-filters { display: none !important; }
            .dash-print-filters { display: block !important; }
            .dash-table { min-width: 0; }
        }
        .dash-print-filters { display: none; }
    </style>

    {{-- Đầu trang --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-2xl font-bold">Tổng quan đào tạo</h2>
            <p class="mt-1 text-sm text-brand-muted">Dữ liệu cập nhật lúc {{ ReportPeriod::nowLabel() }} (GMT+7)</p>
            <p class="dash-print-filters mt-1 text-sm text-brand-muted">
                Kỳ {{ $period->label() }} · so với {{ DashboardFilter::COMPARES[$filter->compare] }} · {{ $mobileSummary }}
                · {{ DashboardFilter::COURSE_TYPES[$filter->courseType] }} · Nhân sự: {{ $statusSummary }}
            </p>
        </div>

        <div class="dash-no-print flex shrink-0 flex-wrap items-center gap-2">
            <button type="button" wire:click="$refresh" class="admin-btn-secondary">
                @svg('heroicon-o-arrow-path', 'h-4 w-4') Làm mới
            </button>
            {{-- PDF tóm tắt dùng hộp thoại in của trình duyệt (Lưu thành PDF): CSS @media print
                 ở trên ẩn sidebar/nút. Không sinh PDF ở server để khỏi thêm thư viện. --}}
            <button type="button" onclick="window.print()" class="admin-btn-secondary dash-desktop-only">
                @svg('heroicon-o-printer', 'h-4 w-4') Tải PDF tóm tắt
            </button>
            @if ($reportLinks->isNotEmpty())
                <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
                    <button type="button" x-on:click="open = ! open" class="admin-btn" aria-haspopup="menu" x-bind:aria-expanded="open">
                        Báo cáo chi tiết @svg('heroicon-m-chevron-down', 'h-4 w-4')
                    </button>
                    <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity
                         class="absolute right-0 z-30 mt-1 w-56 rounded-admin border border-brand-line bg-white py-1 shadow-user" role="menu">
                        @foreach ($reportLinks as $route => $label)
                            <a href="{{ route($route, $periodParams) }}" wire:navigate role="menuitem"
                               class="block px-3 py-2 text-sm hover:bg-brand-soft">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Màn hẹp: bộ lọc thu gọn sau một nút --}}
    <div class="dash-mobile-only dash-no-print mb-3">
        <button type="button" x-on:click="filtersOpen = ! filtersOpen" x-bind:aria-expanded="filtersOpen"
                class="admin-btn-secondary w-full justify-between">
            <span class="flex min-w-0 items-center gap-2 truncate">
                @svg('heroicon-o-funnel', 'h-4 w-4 shrink-0') <span class="truncate">Bộ lọc · {{ $mobileSummary }}</span>
            </span>
            @if ($activeFilterCount > 0)
                <span class="tag tag-red">{{ $activeFilterCount }}</span>
            @endif
        </button>
    </div>

    {{-- Thanh lọc: mọi khối bên dưới áp dụng --}}
    <section class="dash-filters admin-panel mb-4 p-4" x-bind:class="filtersOpen && 'is-open'">
        <div class="flex flex-wrap items-end gap-3">
            <x-admin.report.period-picker :preset="$preset" :from="$from" :to="$to" label="Kỳ báo cáo" />

            <div>
                <label for="dash-compare" class="admin-label">So sánh với</label>
                <select id="dash-compare" wire:model.live="compare" class="admin-input" style="width: 11rem">
                    @foreach (DashboardFilter::COMPARES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="dash-dept" class="admin-label">Đơn vị</label>
                <select id="dash-dept" wire:model.live="departmentId" class="admin-input" style="width: 14rem"
                        title="Chọn một đơn vị là gồm cả các phòng con">
                    <option value="">Tất cả ({{ count($departmentOptions) }} đơn vị)</option>
                    @foreach ($departmentOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="dash-title" class="admin-label">Chức danh</label>
                <select id="dash-title" wire:model.live="jobTitleId" class="admin-input" style="width: 12rem">
                    <option value="">Tất cả</option>
                    @foreach ($jobTitleOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="dash-type" class="admin-label">Loại khóa</label>
                <select id="dash-type" wire:model.live="courseType" class="admin-input" style="width: 12rem">
                    @foreach (DashboardFilter::COURSE_TYPES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
                <span class="admin-label">Trạng thái nhân sự</span>
                <button type="button" x-on:click="open = ! open" class="admin-btn-secondary" x-bind:aria-expanded="open">
                    Trạng thái: {{ $statusSummary }} @svg('heroicon-m-chevron-down', 'h-4 w-4')
                </button>
                <div x-show="open" x-cloak x-on:click.outside="open = false"
                     class="absolute left-0 z-30 mt-1 w-56 rounded-admin border border-brand-line bg-white p-2 shadow-user">
                    @foreach (\App\Models\Employee::STATUS_LABELS as $key => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-admin-sm px-2 py-1.5 text-sm hover:bg-brand-soft">
                            <input type="checkbox" class="dash-check" wire:model.live="employmentStatuses" value="{{ $key }}">
                            {{ $label }}
                        </label>
                    @endforeach
                    <p class="border-t border-brand-line px-2 pt-2 text-[11px] text-brand-muted">Bỏ chọn hết = mọi trạng thái.</p>
                </div>
            </div>

            <button type="button" wire:click="resetFilters" class="admin-btn-secondary">Đặt lại</button>
        </div>
    </section>

    {{-- 5 chỉ số then chốt — thẻ (màn rộng) --}}
    <div class="dash-kpis mb-4">
        @foreach ($cards as $card)
            <x-admin.report.kpi :label="$card['label']" :value="$card['value']"
                                :delta="$card['delta'][0]" :delta-tone="$card['delta'][1]"
                                :note="$card['note']" :spark="$card['spark']" :alert="$card['alert']" />
        @endforeach
    </div>

    {{-- 5 chỉ số then chốt — dạng dòng (màn hẹp) --}}
    <div class="dash-kpi-list mb-4">
        <h3 class="admin-label">Chỉ số then chốt</h3>
        <div class="admin-panel overflow-hidden">
            @foreach ($cards as $card)
                @php $tone = ['good' => 'text-state-success', 'bad' => 'text-brand-red'][$card['delta'][1]] ?? 'text-brand-muted'; @endphp
                <div @class([
                        'flex items-center justify-between gap-3 border-b border-brand-line px-3 py-3 last:border-b-0',
                        'bg-brand-red-tint' => $card['alert'],
                    ]) @if ($card['alert']) style="box-shadow: inset 2px 0 0 #c00016" @endif>
                    <span @class(['min-w-0 text-[13px]', 'font-semibold' => $card['alert']])>{{ $card['short'] ?? $card['label'] }}</span>
                    <span class="flex shrink-0 items-center gap-2">
                        <x-admin.chart.sparkline :values="$card['spark']" :color="$card['alert'] ? '#c00016' : '#141821'" :width="44" :height="18" />
                        <span class="text-right">
                            <strong @class(['block text-base font-bold leading-none tabular-nums', 'text-brand-red' => $card['alert']])>{{ $card['value'] }}</strong>
                            @if ($card['delta'][0])
                                <span class="block text-[11px] font-semibold {{ $tone }}">{{ $card['delta'][0] }}</span>
                            @endif
                        </span>
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Việc cần xử lý: ẩn dòng bằng 0 --}}
    @php
        $actionRows = collect([
            ['count' => $actions['open_alerts'], 'text' => 'cảnh báo bảo mật chưa đóng', 'route' => 'admin.security'],
            ['count' => $actions['over_sla'], 'text' => 'yêu cầu hỗ trợ đang mở quá SLA', 'route' => 'admin.support'],
        ])->where('count', '>', 0);
    @endphp
    @if ($actionRows->isNotEmpty())
        <h3 class="dash-mobile-only admin-label">Việc cần xử lý</h3>
        <div class="dash-actions mb-4">
            @foreach ($actionRows as $item)
                @php $link = $canOpen($item['route']); @endphp
                <{{ $link ? 'a' : 'div' }} @if ($link) href="{{ route($item['route']) }}" wire:navigate @endif
                    class="admin-panel flex min-h-[52px] items-center gap-3 px-4 py-2.5 {{ $link ? 'transition-colors hover:border-brand-red' : '' }}">
                    <span class="h-2 w-2 shrink-0 rounded-full bg-brand-red"></span>
                    <strong class="w-8 shrink-0 text-lg font-bold tabular-nums">{{ $fmtInt($item['count']) }}</strong>
                    <span class="min-w-0 flex-1 text-[13px] font-semibold">{{ $item['text'] }}</span>
                    @if ($link)
                        <span class="dash-no-print shrink-0 text-[13px] font-semibold text-brand-red">Xử lý →</span>
                    @endif
                </{{ $link ? 'a' : 'div' }}>
            @endforeach
        </div>
    @endif

    {{-- Xu hướng + phân bố trạng thái --}}
    <div class="dash-grid dash-grid-trend mb-4">
        {{-- Biểu đồ ngày quá dày ở màn hẹp, ẩn như thiết kế mobile --}}
        <x-admin.report.panel title="Xu hướng học tập" class="dash-desktop-only"
                              :subtitle="$period->startLocal->format('d/m') . ' – ' . $period->endLocal->format('d/m/Y') . ', theo ngày'"
                              note="Trục trái: lượt hoàn thành/ngày · Trục phải: học viên hoạt động · Kỳ so sánh: {{ DashboardFilter::COMPARES[$filter->compare] }} ({{ $filter->comparePeriod()->label() }})">
            <x-slot:actions>
                <x-admin.chart.legend :items="[
                    ['label' => 'Lượt hoàn thành', 'color' => '#141821', 'type' => 'line'],
                    ['label' => 'Học viên hoạt động', 'color' => '#6b7280', 'type' => 'line'],
                    ['label' => DashboardFilter::COMPARES[$filter->compare], 'color' => '#9ca3af', 'type' => 'dashed'],
                ]" />
            </x-slot:actions>

            @if (array_sum($trend['completions']) + array_sum($trend['active']) + array_sum(array_filter($trend['previous'])) === 0)
                <p class="py-12 text-center text-sm text-brand-muted">Chưa có lượt hoàn thành hay hoạt động học nào trong kỳ này.</p>
            @else
                <x-admin.chart.combo :labels="$trend['labels']" :height="250"
                                     :lines="[
                                         ['name' => 'Lượt hoàn thành', 'values' => $trend['completions'], 'color' => '#141821', 'axis' => 'left'],
                                         ['name' => 'Học viên hoạt động', 'values' => $trend['active'], 'color' => '#6b7280', 'axis' => 'right', 'dots' => count($trend['labels']) <= 31],
                                         ['name' => DashboardFilter::COMPARES[$filter->compare], 'values' => $trend['previous'], 'color' => '#9ca3af', 'axis' => 'left', 'dashed' => true],
                                     ]"
                                     :end-note="(string) end($trend['completions'])"
                                     label="Lượt hoàn thành và học viên hoạt động theo ngày" />
            @endif
        </x-admin.report.panel>

        <x-admin.report.panel title="Phân bố trạng thái ghi danh"
                              :subtitle="$fmtInt($statusTotal) . ' lượt trong phạm vi lọc'"
                              :note="$statusTotal > 0 ? 'Bấm một lát để lọc bảng “Nhân viên cần nhắc” bên dưới theo trạng thái đó.' : null">
            @if ($statusTotal === 0)
                <p class="py-12 text-center text-sm text-brand-muted">Không có lượt ghi danh nào trong phạm vi lọc.</p>
            @else
                <x-admin.chart.donut :data="collect($statusLabels)->map(fn ($label, $key) => [
                                        'key' => $key, 'label' => $label, 'value' => $statusCounts[$key],
                                        'color' => $statusColors[$key], 'danger' => $key === 'overdue',
                                     ])->values()->all()"
                                     center-label="lượt ghi danh" click-action="filterStatus"
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
            'muted' => $u['small'],
        ]);
        $smallUnits = $units['rows']->where('small', true);
        $unitNote = $smallUnits->isEmpty() ? null : 'Mẫu nhỏ: ' . $smallUnits->take(3)
            ->map(fn ($u) => e($u['name']) . ' chỉ có ' . $u['total'] . ' lượt ghi danh')->implode('; ')
            . ($smallUnits->count() > 3 ? '; …' : '') . ' trong kỳ — tỷ lệ dễ dao động.';
        $scopeName = $filter->departmentId ? 'toàn đơn vị đã chọn' : 'toàn hàng';
    @endphp
    <div class="dash-grid dash-grid-units mb-4">
        <x-admin.report.panel title="Tiến độ theo đơn vị"
                              :subtitle="$units['overall'] === null ? null : 'đơn vị chậm nhất xếp trên · vạch đứt = ' . $scopeName . ' ' . $fmtPct($units['overall'])"
                              :note="$unitNote">
            @if ($unitRows->isEmpty())
                <p class="py-12 text-center text-sm text-brand-muted">Không có lượt ghi danh nào trong phạm vi lọc.</p>
            @else
                <x-admin.chart.hbar class="dash-desktop-only" :rows="$unitRows" :max="100" :benchmark="$units['overall']" label-width="10rem" />

                {{-- Màn hẹp: nhãn nằm trên thanh (không đủ chỗ cho cột nhãn), 4 dòng đầu + "Xem cả" --}}
                <div class="dash-mobile-only">
                    @foreach ($unitRows as $i => $u)
                        <div class="{{ $i >= 4 ? 'dash-units-more' : '' }} mb-2.5" @if ($i >= 4) x-bind:class="unitsOpen && 'is-open'" @endif
                             style="{{ $u['muted'] ? 'opacity: .5' : '' }}">
                            <div class="mb-1 flex justify-between gap-2 text-[13px]">
                                <span class="min-w-0 truncate">{{ $u['label'] }}</span>
                                <strong class="shrink-0 tabular-nums">{{ $u['value'] }}</strong>
                            </div>
                            <div class="relative flex overflow-hidden rounded-sm" style="height: 12px; background: #eef0f4">
                                @foreach ($u['segments'] as $seg)
                                    <span style="width: {{ $seg['value'] }}%; background: {{ $seg['color'] }}"></span>
                                @endforeach
                                @if ($units['overall'] !== null)
                                    <span class="absolute border-l border-dashed border-brand-ink" style="top: 0; bottom: 0; left: {{ $units['overall'] }}%"></span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                @if ($unitRows->count() > 4)
                    <button type="button" x-on:click="unitsOpen = ! unitsOpen"
                            class="dash-mobile-only dash-no-print admin-btn-secondary mt-3 w-full"
                            x-text="unitsOpen ? 'Thu gọn' : 'Xem cả {{ $unitRows->count() }} đơn vị'">Xem cả {{ $unitRows->count() }} đơn vị</button>
                @endif
                <x-admin.chart.legend class="mt-4 border-t border-brand-line pt-3"
                                      :items="collect($statusLabels)->map(fn ($l, $k) => ['label' => $l, 'color' => $statusColors[$k]])->values()->all()" />
            @endif
        </x-admin.report.panel>

        <x-admin.report.panel title="Khóa cần can thiệp"
                              note="Xếp theo số lượt quá hạn giảm dần, chỉ xét khóa có từ {{ Metrics::COURSE_MIN_ENROLLMENTS }} lượt ghi danh trong kỳ. Tiến độ = trung bình % tiến độ các lượt.">
            @if ($learningUrl && $courseCount > 0)
                <x-slot:actions>
                    <a href="{{ $learningUrl }}" wire:navigate class="dash-no-print text-[13px] font-semibold text-brand-red hover:underline">Xem cả {{ $courseCount }} →</a>
                </x-slot:actions>
            @endif

            <div class="grid gap-4">
                @forelse ($courses as $course)
                    <div wire:key="course-{{ $course->id }}">
                        <div class="mb-1.5 flex items-start justify-between gap-3">
                            <span class="min-w-0 text-[13px] font-semibold">{{ $course->title }}</span>
                            <span class="flex shrink-0 gap-1">
                                @if ($course->is_onboarding)
                                    <span class="tag">ONBOARDING</span>
                                @endif
                                @if ($course->mandatory * 2 > $course->total)
                                    <span class="tag tag-red">BẮT BUỘC</span>
                                @endif
                            </span>
                        </div>
                        <div class="flex items-center gap-3 text-[13px]">
                            <div class="dash-bar min-w-0 flex-1"><span style="width: {{ min(100, (float) $course->avg_progress) }}%; background: #141821"></span></div>
                            <span class="w-14 shrink-0 text-right tabular-nums">{{ $fmtPct($course->avg_progress) }}</span>
                            <span class="w-24 shrink-0 text-right font-semibold text-brand-red tabular-nums">{{ $fmtInt($course->overdue) }} quá hạn</span>
                        </div>
                    </div>
                @empty
                    <p class="py-12 text-center text-sm text-brand-muted">Không có khóa nào (từ {{ Metrics::COURSE_MIN_ENROLLMENTS }} lượt ghi danh) có lượt quá hạn trong phạm vi lọc.</p>
                @endforelse
            </div>
        </x-admin.report.panel>
    </div>

    {{-- Nhân viên cần nhắc --}}
    @php
        $reminder = app(\App\Services\LearningReminderService::class);
        $today = now(ReportPeriod::TIMEZONE)->startOfDay();
        $pageIds = $rows->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $allPageSelected = $pageIds && ! array_diff($pageIds, $selected);
        $canRemind = $user?->can('report_learning.update');
    @endphp
    <section class="admin-panel min-w-0">
        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                <h2 class="text-[15px] font-bold">Nhân viên cần nhắc</h2>
                @if ($statusFilter && isset($statusLabels[$statusFilter]))
                    <button type="button" wire:click="filterStatus('{{ $statusFilter }}')"
                            class="inline-flex items-center gap-1 rounded-admin-sm border border-brand-red bg-brand-red-tint px-2 py-1 text-xs font-semibold text-brand-red"
                            title="Bỏ lọc trạng thái">
                        Đang lọc: {{ $statusLabels[$statusFilter] }} ✕
                    </button>
                @endif
                <span class="text-xs text-brand-muted">
                    {{ $fmtInt($rows->total()) }} lượt{{ $statusFilter ? '' : ' chưa hoàn thành' }} · xếp theo số ngày quá hạn giảm dần
                </span>
            </div>
            <div class="dash-no-print flex flex-wrap items-center gap-2">
                @can('report_learning.update')
                    @if ($rows->total() > 0)
                        <button type="button" wire:click="remindSelected" class="admin-btn-secondary dash-desktop-only"
                                x-bind:disabled="$wire.selected.length === 0"
                                data-confirm="Gửi lời nhắc học tới các nhân viên đã chọn? Lượt đã nhắc trong {{ \App\Services\LearningReminderService::COOLDOWN_HOURS }} giờ qua sẽ được bỏ qua."
                                data-confirm-title="Gửi nhắc hàng loạt" data-confirm-ok="Gửi nhắc">
                            Gửi nhắc hàng loạt <span x-show="$wire.selected.length > 0" x-text="'(' + $wire.selected.length + ')'"></span>
                        </button>
                    @endif
                @endcan
                @if ($learningUrl)
                    <a href="{{ $learningUrl }}" wire:navigate class="admin-btn dash-desktop-only">Xem tất cả trong báo cáo →</a>
                @endif
            </div>
        </header>

        @if ($notice)
            <div class="mx-5 mb-3 flex items-center justify-between gap-3 rounded-admin border border-state-success bg-state-success-tint px-3 py-2 text-[13px] font-semibold text-state-success">
                <span>{{ $notice }}</span>
                <button type="button" wire:click="$set('notice', null)" class="shrink-0" aria-label="Đóng">✕</button>
            </div>
        @endif

        <div class="overflow-x-auto border-t border-brand-line">
            <table class="admin-table dash-table">
                <thead>
                    <tr>
                        @if ($canRemind)
                            <th class="dash-hide-sm dash-no-print" style="width: 40px">
                                @if ($pageIds)
                                    <input type="checkbox" class="dash-check" aria-label="Chọn cả trang"
                                           @checked($allPageSelected) wire:click="togglePage(@js($pageIds))">
                                @endif
                            </th>
                        @endif
                        <th class="dash-hide-sm" style="width: 84px">Mã NV</th>
                        <th class="dash-sticky" style="width: 130px">Họ tên</th>
                        <th style="width: 150px">Đơn vị</th>
                        <th style="width: 160px">Khóa học</th>
                        <th style="width: 110px">Tiến độ</th>
                        <th style="width: 90px">Hạn</th>
                        <th class="text-right" style="width: 76px">Quá hạn</th>
                        <th style="width: 100px">Trạng thái</th>
                        <th class="dash-no-print text-right" style="width: 100px">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $employee = $row->employee;
                            $dept = $employee?->department;
                            $dueDate = $row->due_date ? \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $row->due_date->toDateString(), ReportPeriod::TIMEZONE)->startOfDay() : null;
                            $isDone = $row->status === 'completed';
                            $daysLate = ! $isDone && $dueDate && $dueDate->lt($today) ? (int) $dueDate->diffInDays($today) : null;
                            $daysLeft = ! $isDone && $dueDate && $dueDate->gte($today) ? (int) $today->diffInDays($dueDate) : null;
                            [$tagClass, $tagText] = match (true) {
                                $isDone => ['tag-green', 'Hoàn thành'],
                                $daysLate !== null => ['tag-red', 'Quá hạn'],
                                $daysLeft !== null && $daysLeft <= 7 => ['tag-amber', 'Sắp đến hạn'],
                                $row->status === 'not_started' || ($row->status === 'overdue' && (float) $row->progress_percent == 0) => ['', 'Chưa bắt đầu'],
                                default => ['', 'Đang học'],
                            };
                            $progress = $isDone ? 100 : (float) $row->progress_percent;
                        @endphp
                        <tr wire:key="remind-{{ $row->id }}">
                            @if ($canRemind)
                                <td class="dash-hide-sm dash-no-print">
                                    @unless ($isDone)
                                        <input type="checkbox" class="dash-check" wire:model="selected" value="{{ $row->id }}"
                                               aria-label="Chọn {{ $employee?->full_name }}">
                                    @endunless
                                </td>
                            @endif
                            <td class="dash-hide-sm">
                                @if ($employee && $canOpen('admin.employees.show'))
                                    <a href="{{ route('admin.employees.show', $employee) }}" wire:navigate class="font-semibold text-brand-red hover:underline">{{ $employee->employee_code }}</a>
                                @else
                                    <span class="font-semibold">{{ $employee?->employee_code }}</span>
                                @endif
                            </td>
                            <td class="dash-sticky font-semibold">{{ $employee?->full_name }}</td>
                            <td class="text-brand-muted">
                                {{ $dept ? ($dept->parent ? $dept->parent->name . ' › ' : '') . $dept->name : '—' }}
                            </td>
                            <td>{{ $row->course?->title }}</td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="dash-bar min-w-0 flex-1">
                                        <span style="width: {{ min(100, $progress) }}%; background: {{ $daysLate !== null ? '#c00016' : '#141821' }}"></span>
                                    </div>
                                    <span class="w-9 shrink-0 text-right text-xs tabular-nums">{{ (int) round($progress) }}%</span>
                                </div>
                            </td>
                            <td class="tabular-nums">{{ $row->due_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="text-right tabular-nums">
                                @if ($daysLate !== null)
                                    <strong class="text-brand-red">{{ $daysLate }} ngày</strong>
                                @elseif ($daysLeft !== null)
                                    <span class="text-xs text-brand-muted">{{ $daysLeft === 0 ? 'hạn hôm nay' : 'còn ' . $daysLeft . ' ngày' }}</span>
                                @else
                                    <span class="text-brand-muted">—</span>
                                @endif
                            </td>
                            <td><span class="tag {{ $tagClass }}">{{ $tagText }}</span></td>
                            <td class="dash-no-print text-right">
                                @if ($isDone)
                                    <span class="text-brand-muted">—</span>
                                @elseif (! $reminder->canRemind($row))
                                    <span class="inline-block rounded-admin-sm border border-brand-line bg-brand-soft px-2 py-1 text-xs text-brand-muted"
                                          title="Đã nhắc lúc {{ ReportPeriod::local($row->last_reminded_at)?->format('H:i d/m/Y') }} — nhắc lại sau {{ \App\Services\LearningReminderService::COOLDOWN_HOURS }} giờ">
                                        Đã nhắc {{ ReportPeriod::local($row->last_reminded_at)?->format('H:i') }}
                                    </span>
                                @else
                                    @can('report_learning.update')
                                        <button type="button" wire:click="remind({{ $row->id }})" wire:loading.attr="disabled"
                                                class="admin-btn-secondary" style="min-height: 32px">Gửi nhắc</button>
                                    @else
                                        <span class="text-brand-muted">—</span>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-brand-muted">
                                {{ $statusFilter ? 'Không có lượt ghi danh nào ở trạng thái này trong phạm vi lọc.' : 'Không có lượt ghi danh nào cần nhắc trong phạm vi lọc.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="dash-mobile-only border-t border-brand-line px-4 py-2 text-[11px] text-brand-muted">↔ Vuốt ngang để xem thêm cột · cột họ tên luôn đứng yên</p>

        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-line px-5 py-3 text-xs text-brand-muted">
            <span>
                @if ($rows->total() > 0 && ! $rows->hasPages())
                    Hiển thị {{ $rows->firstItem() }}–{{ $rows->lastItem() }} trong {{ $fmtInt($rows->total()) }} lượt
                @endif
            </span>
            <span class="dash-desktop-only">Nguồn: enrollments, lesson_progresses · Múi giờ GMT+7</span>
        </footer>
        @if ($rows->hasPages())
            <div class="dash-no-print border-t border-brand-line px-5 py-3">{{ $rows->links(data: ['summary' => false]) }}</div>
        @endif

        @if ($learningUrl)
            <div class="dash-mobile-only dash-no-print px-4 pb-4">
                <a href="{{ $learningUrl }}" wire:navigate class="admin-btn w-full">Mở báo cáo tiến độ học tập</a>
            </div>
        @endif
    </section>
</div>
