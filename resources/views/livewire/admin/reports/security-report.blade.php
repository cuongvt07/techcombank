@use('App\Models\SecurityAlert')
@use('App\Services\Reports\SecurityReportService', 'S')
@use('App\Support\Reports\ReportPeriod')

@php
    $num = fn ($n) => number_format((int) $n, 0, ',', '.');
    $delta = $kpis['fail_rate_delta'];
    $failWorse = $delta !== null && $delta > 0;
    $hasLogins = $kpis['logins'] > 0;
    $lastDay = $daily->last();
@endphp

<div>
    <x-admin.page-header title="Báo cáo bảo mật và đăng nhập"
                         subtitle="Màn hình thống kê và báo cáo định kỳ · xử lý từng cảnh báo tại Trung tâm bảo mật">
        <x-slot:actions>
            @can('security.view')
                <a href="{{ route('admin.security') }}" wire:navigate class="admin-btn-secondary">
                    @svg('heroicon-o-shield-check', 'h-4 w-4') Mở Trung tâm bảo mật
                </a>
            @endcan
            <x-admin.report.export-menu />
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Bộ lọc. Không có "Khu vực": login_histories không lưu vị trí/khu vực đăng nhập. --}}
    <section class="admin-panel mb-4 flex flex-wrap items-end gap-3 p-4" aria-label="Bộ lọc">
        <div>
            <x-admin.report.period-picker :preset="$preset" :from="$from" :to="$to" label="Kỳ (tối đa 92 ngày)" />
            <p class="mt-1 text-xs text-brand-muted">
                {{ $period->label() }}
                @if ($clamped)
                    <span class="font-semibold text-brand-red">· đã rút còn {{ \App\Livewire\Admin\Reports\SecurityReport::REPORT_MAX_DAYS }} ngày</span>
                @endif
            </p>
        </div>

        <div class="w-full min-w-[200px] sm:w-auto">
            <label class="admin-label" for="sec-type">Loại cảnh báo</label>
            <select id="sec-type" wire:model.live="type" class="admin-input">
                <option value="">Tất cả ({{ count(SecurityAlert::TYPE_LABELS) }} loại)</option>
                @foreach (SecurityAlert::TYPE_LABELS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="w-full min-w-[160px] sm:w-auto">
            <label class="admin-label" for="sec-severity">Mức độ</label>
            <select id="sec-severity" wire:model.live="severity" class="admin-input">
                <option value="">Tất cả</option>
                @foreach (S::SEVERITY_LABELS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="w-full min-w-[160px] sm:w-auto">
            <label class="admin-label" for="sec-status">Trạng thái</label>
            <select id="sec-status" wire:model.live="status" class="admin-input">
                <option value="">Tất cả</option>
                @foreach (S::STATUS_LABELS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <button type="button" wire:click="resetFilters" class="admin-btn-secondary" style="margin-left: auto">Đặt lại</button>
    </section>

    {{-- Chỉ số tóm tắt --}}
    <section class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" aria-label="Chỉ số tóm tắt">
        <x-admin.report.kpi label="Lần đăng nhập" :value="$num($kpis['logins'])"
                            :note="$hasLogins ? $num($kpis['failed']) . ' lượt thất bại / bị chặn' : 'Chưa có lượt nào trong kỳ'" />

        <x-admin.report.kpi label="Tỷ lệ thất bại" :value="S::pct($kpis['fail_rate'])"
                            :delta="$delta === null ? null : ($delta > 0 ? '▲ ' : ($delta < 0 ? '▼ ' : '')) . number_format(abs($delta), 1, ',', '.') . ' pp'"
                            :deltaTone="$failWorse ? 'bad' : ($delta !== null && $delta < 0 ? 'good' : 'neutral')"
                            :note="$delta === null ? 'Kỳ trước chưa có dữ liệu' : 'so với kỳ trước'"
                            :alert="$failWorse" />

        <x-admin.report.kpi label="Tài khoản bị khóa" :value="$num($kpis['locked_accounts'])"
                            :note="'Hiện tại · ' . $num($kpis['blocked_attempts']) . ' lượt bị chặn'" />

        <x-admin.report.kpi label="Cảnh báo mới" :value="$num($kpis['alerts'])"
                            :note="$num($kpis['alerts_unclosed']) . ' chưa đóng'"
                            :noteTone="$kpis['alerts_unclosed'] > 0 ? 'danger' : null" />

        <x-admin.report.kpi label="MTTA (ghi nhận)" :value="S::hours($kpis['mtta_hours'])"
                            :note="$kpis['mtta_count'] ? 'giờ, trung vị · ' . $num($kpis['mtta_count']) . ' cảnh báo' : 'Chưa có lượt ghi nhận'" />

        <x-admin.report.kpi label="MTTR (đóng)" :value="S::hours($kpis['mttr_hours'])"
                            :note="$kpis['mttr_count'] ? 'giờ, trung vị · ' . $num($kpis['mttr_count']) . ' cảnh báo' : 'Chưa có lượt đóng'" />
    </section>

    <div class="mb-4 grid gap-4 xl:grid-cols-2">
        {{-- Đăng nhập theo ngày --}}
        <x-admin.report.panel title="Đăng nhập theo ngày" :note="$dailyInsight" :noteAlert="(bool) $dailyInsight">
            <x-slot:actions>
                <x-admin.chart.legend :items="[
                    ['label' => 'Thành công', 'color' => '#6b7280'],
                    ['label' => 'Thất bại', 'color' => '#c00016'],
                    ['label' => 'Tỷ lệ thất bại', 'color' => '#141821', 'type' => 'line'],
                ]" />
            </x-slot:actions>

            @if ($hasLogins)
                <x-admin.chart.combo :width="560"
                    label="Đăng nhập theo ngày"
                    :labels="$daily->pluck('label')"
                    :stacked="true"
                    :bars="[
                        ['name' => 'Thành công', 'values' => $daily->pluck('success')->all(), 'color' => '#6b7280'],
                        ['name' => 'Thất bại', 'values' => $daily->pluck('failed')->all(), 'color' => '#c00016'],
                    ]"
                    :lines="[
                        ['name' => 'Tỷ lệ thất bại', 'values' => $daily->pluck('rate')->all(), 'color' => '#141821', 'axis' => 'right', 'dots' => $daily->count() <= 31],
                    ]"
                    rightSuffix="%"
                    :height="240"
                    :endNote="$lastDay && $lastDay['rate'] !== null ? S::pct($lastDay['rate']) : null" />
            @else
                <p class="grid h-48 place-items-center rounded-admin bg-brand-soft text-sm text-brand-muted">Không có lượt đăng nhập nào trong kỳ.</p>
            @endif
        </x-admin.report.panel>

        {{-- Cảnh báo theo loại và mức độ --}}
        <x-admin.report.panel title="Cảnh báo theo loại và mức độ" :note="$typeInsight" :noteAlert="(bool) $typeInsight">
            <x-slot:actions>
                <x-admin.chart.legend :items="[
                    ['label' => 'Cao (gồm Nghiêm trọng)', 'color' => '#c00016'],
                    ['label' => 'Trung bình', 'color' => '#6b7280'],
                    ['label' => 'Thấp', 'color' => '#d1d5db'],
                ]" />
            </x-slot:actions>

            @if ($byType->isNotEmpty())
                <x-admin.chart.hbar labelWidth="11rem" valueWidth="2.5rem" barHeight="h-4"
                    :rows="$byType->map(fn ($r) => [
                        'label' => $r['label'],
                        'value' => $num($r['total']),
                        'segments' => [
                            ['value' => $r['high'], 'color' => '#c00016', 'name' => 'Cao'],
                            ['value' => $r['medium'], 'color' => '#6b7280', 'name' => 'Trung bình'],
                            ['value' => $r['low'], 'color' => '#d1d5db', 'name' => 'Thấp'],
                        ],
                    ])->all()" />
            @else
                <p class="grid h-48 place-items-center rounded-admin bg-brand-soft text-sm text-brand-muted">Không có cảnh báo nào khớp bộ lọc trong kỳ.</p>
            @endif
        </x-admin.report.panel>
    </div>

    {{-- Giờ đăng nhập trong tuần --}}
    <x-admin.report.panel class="mb-4" title="Giờ đăng nhập trong tuần"
                          subtitle="GMT+7 · ô viền đỏ là truy cập ngoài {{ sprintf('%02d:00–%02d:00', S::WORK_HOUR_START, S::WORK_HOUR_END) }} với trên {{ S::OFF_HOURS_THRESHOLD }} lần"
                          :note="$hasLogins ? $heatmapInsight : null" :noteAlert="$flagged !== []">
        <x-slot:actions>
            <div class="flex items-center gap-1.5 text-xs text-brand-muted" aria-hidden="true">
                Thưa
                @foreach (['#f5f6f8', '#d6d9df', '#9aa0aa', '#565c66', '#141821'] as $shade)
                    <span class="h-3 w-4 rounded-sm border border-brand-line" style="background: {{ $shade }}"></span>
                @endforeach
                Dày
            </div>
        </x-slot:actions>

        @if ($hasLogins)
            {{-- Bọc cuộn ngang + bề rộng tối thiểu: trên điện thoại bảng 24 cột không bị bóp ô --}}
            <div class="overflow-x-auto">
            <div style="min-width: 740px">
            <x-admin.chart.heatmap label="Lượt đăng nhập theo thứ và giờ"
                                   :rows="array_values(S::WEEKDAY_LABELS)"
                                   :columns="array_map(fn ($h) => sprintf('%02d', $h), range(0, 23))"
                                   :values="$heatmap"
                                   :flagged="$flagged"
                                   clickAction="selectHeatCell" />
            </div>
            </div>
        @else
            <p class="grid h-32 place-items-center rounded-admin bg-brand-soft text-sm text-brand-muted">Không có lượt đăng nhập nào trong kỳ.</p>
        @endif
    </x-admin.report.panel>

    {{-- Bảng chi tiết: Cảnh báo / Lịch sử đăng nhập --}}
    <section class="admin-panel min-w-0">
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
            <div class="inline-flex overflow-hidden rounded-admin border border-brand-ink" role="tablist" aria-label="Bảng chi tiết">
                @foreach (['alerts' => 'Cảnh báo', 'logins' => 'Lịch sử đăng nhập'] as $key => $label)
                    <button type="button" role="tab" wire:click="setTab('{{ $key }}')"
                            aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            @class([
                                'h-10 px-3.5 text-[13px] font-semibold transition-colors',
                                'bg-brand-ink text-white' => $tab === $key,
                                'bg-white text-brand-ink hover:bg-brand-soft' => $tab !== $key,
                            ])>{{ $label }}</button>
                @endforeach
            </div>

            <span class="text-[13px] text-brand-muted">
                @if ($tab === 'logins')
                    {{ $num($rows->total()) }} lượt đăng nhập trong kỳ
                @else
                    {{ $num($rows->total()) }} cảnh báo phát sinh trong kỳ
                @endif
            </span>

            @if ($tab === 'logins' && $loginWeekday && $loginHour !== null)
                <span class="inline-flex items-center gap-1.5 rounded-admin border border-brand-red bg-brand-red-tint px-2.5 py-1 text-xs font-semibold text-brand-red">
                    {{ S::WEEKDAY_LABELS[$loginWeekday] ?? '' }} · {{ S::hourRange($loginHour) }}
                    <button type="button" wire:click="clearHeatCell" aria-label="Bỏ lọc khung giờ" class="hover:text-brand-ink">
                        @svg('heroicon-m-x-mark', 'h-3.5 w-3.5')
                    </button>
                </span>
            @endif
        </div>

        <div class="overflow-x-auto">
            @if ($tab === 'logins')
                <table class="admin-table" style="min-width: 860px">
                    <thead>
                        <tr>
                            <th class="w-36">
                                <button type="button" wire:click="sortByTime" class="inline-flex items-center gap-1 uppercase hover:text-brand-ink">
                                    Thời điểm {{ $sortDir === 'asc' ? '▲' : '▼' }}
                                </button>
                            </th>
                            <th>Tài khoản</th>
                            <th class="w-36">IP</th>
                            <th>Thiết bị</th>
                            <th class="w-28">Kết quả</th>
                            <th>Lý do thất bại</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $login)
                            <tr wire:key="login-{{ $login->id }}">
                                <td class="tabular-nums">{{ ReportPeriod::local($login->logged_in_at)->format('d/m/Y H:i') }}</td>
                                <td>
                                    <div class="font-semibold">{{ $login->user?->name ?? '—' }}</div>
                                    <div class="text-xs text-brand-muted">{{ $this->employeeCode($login->user?->employee?->employee_code, $canSeeCodes) }}</div>
                                </td>
                                <td class="tabular-nums">{{ $login->ip_address ?? '—' }}</td>
                                <td class="text-xs">{{ $login->device_label ?? \Illuminate\Support\Str::limit((string) $login->user_agent, 60) ?: '—' }}</td>
                                <td>
                                    <span @class(['tag', 'tag-green' => $login->result === 'success', 'tag-red' => $login->result !== 'success'])>
                                        {{ S::RESULT_LABELS[$login->result] ?? $login->result }}
                                    </span>
                                </td>
                                <td class="text-xs text-brand-muted">{{ $login->failure_reason ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-brand-muted">Không có lượt đăng nhập nào khớp bộ lọc.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                <table class="admin-table" style="min-width: 1080px">
                    <thead>
                        <tr>
                            <th class="w-28">Mã</th>
                            <th class="w-28">
                                <button type="button" wire:click="sortByTime" class="inline-flex items-center gap-1 uppercase hover:text-brand-ink">
                                    Phát sinh {{ $sortDir === 'asc' ? '▲' : '▼' }}
                                </button>
                            </th>
                            <th class="w-36">Loại</th>
                            <th class="w-28">Mức độ</th>
                            <th class="w-24">Người dùng</th>
                            <th>Tóm tắt</th>
                            <th class="w-32">Trạng thái</th>
                            <th class="w-28">Người xử lý</th>
                            <th class="w-24 text-right">Thời gian xử lý</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $alert)
                            @php
                                $overdue = S::isOpenOverdue($alert);
                                $statusClass = match (true) {
                                    $overdue => 'tag tag-red',
                                    $alert->status === 'open' => 'tag tag-amber',
                                    $alert->status === 'resolved' => 'tag bg-brand-ink text-white',
                                    default => 'tag',
                                };
                                $severityClass = match ($alert->severity) {
                                    'critical', 'high' => 'tag tag-red',
                                    'medium' => 'tag tag-amber',
                                    default => 'tag',
                                };
                                $handling = S::handlingHours($alert);
                                // Link về đúng tab con của Trung tâm bảo mật theo trạng thái cảnh báo
                                $centerTab = in_array($alert->status, ['open', 'acknowledged'], true) ? $alert->status : 'closed';
                            @endphp
                            <tr wire:key="alert-{{ $alert->id }}">
                                <td class="font-semibold">
                                    @can('security.view')
                                        <a href="{{ route('admin.security', ['canh-bao' => $centerTab]) }}" wire:navigate class="text-brand-red hover:underline">{{ S::alertCode($alert) }}</a>
                                    @else
                                        {{ S::alertCode($alert) }}
                                    @endcan
                                </td>
                                <td class="tabular-nums">{{ ReportPeriod::local($alert->created_at)->format('d/m H:i') }}</td>
                                <td>{{ $alert->typeLabel() }}</td>
                                <td><span class="{{ $severityClass }}">{{ S::SEVERITY_LABELS[$alert->severity] ?? $alert->severity }}</span></td>
                                <td class="tabular-nums">{{ $this->employeeCode($alert->user?->employee?->employee_code, $canSeeCodes) }}</td>
                                <td class="text-xs">{{ $alert->detail ?: $alert->title }}</td>
                                <td><span class="{{ $statusClass }}">{{ $this->statusLabel($alert) }}</span></td>
                                <td>{{ $alert->handledBy?->name ?? '—' }}</td>
                                <td class="text-right tabular-nums">{{ $handling === null ? '—' : S::hours($handling) . ' giờ' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-brand-muted">Không có cảnh báo nào khớp bộ lọc trong kỳ.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-brand-line px-4 py-3 text-xs text-brand-muted">
            <span>
                @if ($rows->hasPages())
                    {{-- Phân trang bên dưới đã ghi số dòng --}}
                @elseif ($rows->total() > 0)
                    Hiển thị {{ $num($rows->firstItem()) }}–{{ $num($rows->lastItem()) }} / {{ $num($rows->total()) }} {{ $tab === 'logins' ? 'lượt' : 'cảnh báo' }}
                @else
                    Không có dữ liệu
                @endif
            </span>
            @unless ($canSeeCodes)
                <span>Mã nhân viên được che vì tài khoản này chưa có quyền xem hồ sơ nhân sự</span>
            @endunless
            <span style="margin-left: auto">Nguồn: {{ $tab === 'logins' ? 'login_histories' : 'security_alerts' }}</span>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-brand-line px-4 py-3">{{ $rows->links(data: ['summary' => false]) }}</div>
        @endif
    </section>
</div>
