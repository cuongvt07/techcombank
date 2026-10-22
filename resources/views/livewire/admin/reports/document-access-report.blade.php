@php
    $fmt = fn ($n) => $n === null ? '—' : number_format($n, 0, ',', '.');
    $pct = fn ($n) => $n === null ? '—' : number_format($n, 1, ',', '.') . '%';
    $confTag = [
        'public' => '',
        'internal' => '',
        'confidential' => 'tag-amber',
        'restricted' => 'tag-red',
    ];
    $canLinkDocuments = auth()->user()?->can('documents.view');
    $sortIcon = fn ($col) => $sort === $col ? ($direction === 'asc' ? '▲' : '▼') : '';
@endphp

<div>
    <x-admin.page-header title="Báo cáo truy cập và sử dụng tài liệu"
                         subtitle="Lượt xem lặp của cùng người trong 5 phút được gộp thành một lượt">
        <x-slot:actions>
            @can('document_access.view')
                {{-- Màn phân quyền chưa nhận tham số mở sẵn công cụ thử quyền, nên chỉ dẫn sang màn đó --}}
                <a href="{{ route('admin.access-rules') }}" wire:navigate class="admin-btn-secondary">
                    @svg('heroicon-o-shield-check', 'h-4 w-4') Thử quyền truy cập
                </a>
            @endcan
            <x-admin.report.export-menu />
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Bộ lọc --}}
    <section class="admin-panel mb-4 p-4" aria-label="Bộ lọc">
        <div class="flex flex-wrap items-end gap-3">
            <x-admin.report.period-picker :preset="$preset" :from="$from" :to="$to" label="Kỳ" />

            <div>
                <label for="f-doc-category" class="admin-label">Danh mục</label>
                <select id="f-doc-category" wire:model.live="categoryId" class="admin-input w-auto min-w-[150px]">
                    <option value="">Tất cả ({{ $categories->count() }} danh mục)</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="f-doc-conf" class="admin-label">Độ mật</label>
                <select id="f-doc-conf" wire:model.live="confidentiality" class="admin-input w-auto min-w-[150px]">
                    <option value="">Tất cả</option>
                    @foreach ($confidentialityLabels as $value => $text)
                        <option value="{{ $value }}">{{ $text }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="f-doc-action" class="admin-label">Hành động</label>
                <select id="f-doc-action" wire:model.live="action" class="admin-input w-auto min-w-[150px]">
                    <option value="">Tất cả</option>
                    @foreach ($actionLabels as $value => $text)
                        <option value="{{ $value }}">{{ $text }}</option>
                    @endforeach
                </select>
            </div>

            <div class="w-full min-w-[220px] sm:w-auto">
                <x-admin.searchable-select id="f-doc-owner" label="Đơn vị sở hữu" model="ownerDepartmentId"
                                           :options="$departmentOptions" :selected="$ownerDepartmentId"
                                           placeholder="Tất cả" emptyLabel="Tất cả" />
            </div>

            <button type="button" wire:click="resetFilters" class="admin-btn-secondary" style="margin-left: auto">Đặt lại</button>
        </div>
    </section>

    {{-- Chỉ số tóm tắt --}}
    <section class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" aria-label="Chỉ số tóm tắt">
        <x-admin.report.kpi label="Lượt xem" :value="$fmt($kpis['views'])" />
        <x-admin.report.kpi label="Người xem phân biệt" :value="$fmt($kpis['viewers'])" />
        <x-admin.report.kpi label="Lượt tải" :value="$fmt($kpis['downloads'])" />
        <x-admin.report.kpi label="Lượt bị chặn" :value="$fmt($kpis['denied'])"
                            :alert="($kpis['denied'] ?? 0) > 0"
                            :note="$kpis['burst_people'] !== null ? $fmt($kpis['burst_people']) . ' người ≥ ' . $burstThreshold . ' lượt/giờ' : null"
                            :noteTone="($kpis['burst_people'] ?? 0) > 0 ? 'danger' : null" />
        <x-admin.report.kpi label="Đã xuất bản, chưa ai mở" :value="$fmt($kpis['unopened'])" />
        <x-admin.report.kpi label="Quá hạn rà soát" :value="$fmt($kpis['stale'])" note="không đổi bản > 12 tháng" />
    </section>

    <div class="mb-4 grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        {{-- Lượt truy cập theo tuần --}}
        @php
            $hasWeekData = $weeks->sum(fn ($w) => $w['views'] + $w['downloads'] + $w['denied']) > 0;
            $lastIndex = $weeks->count() - 1;
            $lastDenied = $weeks->last()['denied'] ?? 0;
            // Mốc đỏ trên đỉnh cột tuần cuối để gắn nhãn số lượt bị chặn
            $deniedMarker = $lastDenied > 0
                ? [[
                    'name' => 'Bị chặn tuần cuối',
                    'values' => $weeks->map(fn ($w, $i) => $i === $lastIndex ? $w['views'] + $w['downloads'] + $w['denied'] : null)->all(),
                    'color' => '#c00016',
                ]]
                : [];
        @endphp
        <x-admin.report.panel title="Lượt truy cập theo tuần" :subtitle="$period->label()" :note="$weekNote" :noteAlert="$weekNote && $lastDenied > 0">
            <x-slot:actions>
                <x-admin.chart.legend :items="[
                    ['label' => 'Xem', 'color' => '#141821'],
                    ['label' => 'Tải', 'color' => '#6b7280'],
                    ['label' => 'Bị chặn', 'color' => '#c00016'],
                ]" />
            </x-slot:actions>

            @if ($hasWeekData)
                <x-admin.chart.combo :width="640" :labels="$weeks->pluck('label')->all()" :stacked="true" :height="240"
                                     :bars="[
                                         ['name' => 'Xem', 'values' => $weeks->pluck('views')->all(), 'color' => '#141821'],
                                         ['name' => 'Tải', 'values' => $weeks->pluck('downloads')->all(), 'color' => '#6b7280'],
                                         ['name' => 'Bị chặn', 'values' => $weeks->pluck('denied')->all(), 'color' => '#c00016'],
                                     ]"
                                     :lines="$deniedMarker"
                                     :endNote="$lastDenied > 0 ? $fmt($lastDenied) . ' bị chặn' : null"
                                     label="Lượt xem, tải và bị chặn theo tuần" />
            @else
                <p class="py-12 text-center text-sm text-brand-muted">Không có lượt truy cập nào trong kỳ khớp bộ lọc.</p>
            @endif
        </x-admin.report.panel>

        {{-- Top tài liệu được mở --}}
        <x-admin.report.panel title="Top tài liệu được mở"
                              note="Thanh đo là tỷ lệ tiếp cận: người xem phân biệt chia cho số người được cấp quyền xem.">
            <div class="mb-4 inline-flex overflow-hidden rounded-admin border border-brand-line" role="group" aria-label="Thứ tự">
                @foreach (['most' => 'Nhiều nhất', 'least' => 'Ít nhất'] as $mode => $text)
                    <button type="button" wire:click="setTopMode('{{ $mode }}')"
                            aria-pressed="{{ $topMode === $mode ? 'true' : 'false' }}"
                            @class([
                                'h-8 border-l border-brand-line px-3 text-[13px] first:border-l-0 transition-colors',
                                'bg-brand-ink font-semibold text-white' => $topMode === $mode,
                                'bg-white text-brand-ink hover:bg-brand-soft' => $topMode !== $mode,
                            ])>{{ $text }}</button>
                @endforeach
            </div>

            @if ($top->isNotEmpty())
                <x-admin.chart.hbar :max="100" labelWidth="minmax(0, 11rem)" valueWidth="3.5rem"
                                    :rows="$top->map(fn ($r) => [
                                        'label' => $r['title'],
                                        'segments' => [[
                                            'value' => $r['reach'],
                                            'color' => $r['reach'] < $lowReach ? '#c00016' : '#6b7280',
                                            'name' => 'Tỷ lệ tiếp cận',
                                            'text' => $fmt($r['viewers']) . ' / ' . $fmt($r['granted']) . ' người',
                                        ]],
                                        'value' => $pct($r['reach']),
                                    ])->all()" />
            @else
                <p class="py-8 text-center text-sm text-brand-muted">Chưa có tài liệu nào có người được cấp quyền xem.</p>
            @endif
        </x-admin.report.panel>
    </div>

    {{-- Bảng chi tiết --}}
    <section class="admin-panel min-w-0">
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-line px-4 py-3">
            <div class="inline-flex overflow-hidden rounded-admin border border-brand-line" role="tablist">
                <button type="button" role="tab" wire:click="setTab('documents')"
                        aria-selected="{{ $activeTab === 'documents' ? 'true' : 'false' }}"
                        @class([
                            'h-9 px-3.5 text-[13px] transition-colors',
                            'bg-brand-ink font-semibold text-white' => $activeTab === 'documents',
                            'bg-white text-brand-ink hover:bg-brand-soft' => $activeTab !== 'documents',
                        ])>Theo tài liệu</button>
                <button type="button" role="tab" aria-selected="{{ $activeTab === 'log' ? 'true' : 'false' }}"
                        @if ($canViewLog) wire:click="setTab('log')" @else disabled title="Cần quyền security.view" @endif
                        @class([
                            'h-9 border-l border-brand-line px-3.5 text-[13px] transition-colors',
                            'bg-brand-ink font-semibold text-white' => $activeTab === 'log',
                            'bg-white text-brand-ink hover:bg-brand-soft' => $activeTab !== 'log' && $canViewLog,
                            'cursor-not-allowed bg-white text-brand-muted' => ! $canViewLog,
                        ])>Nhật ký truy cập</button>
            </div>

            <span @class([
                'inline-flex items-center gap-1.5 rounded-admin-sm border px-2.5 py-1 text-xs',
                'border-brand-line bg-brand-soft text-brand-muted' => $canViewLog,
                'border-brand-red bg-brand-red-tint text-brand-red' => ! $canViewLog,
            ])>
                @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5')
                Nhật ký cần quyền security.view · kỳ tối đa {{ \App\Livewire\Admin\Reports\DocumentAccessReport::LOG_MAX_DAYS }} ngày
            </span>

            @if ($logPeriodClamped)
                <span class="text-xs font-semibold text-brand-red">Đang xem {{ $period->label() }} — kỳ đã cắt còn 92 ngày gần nhất.</span>
            @endif
        </div>

        @if ($activeTab === 'documents')
            <div class="overflow-x-auto">
                <table class="admin-table" style="min-width: 1120px">
                    <thead>
                        <tr>
                            @foreach ([
                                ['title', 'Tiêu đề', '180', ''],
                                ['category', 'Danh mục', '100', ''],
                                [null, 'Độ mật', '96', ''],
                                ['version', 'Bản', '56', ''],
                                ['published_at', 'Xuất bản', '100', ''],
                                ['granted', 'Được cấp quyền', '84', 'text-right'],
                                ['viewers', 'Người xem', '76', 'text-right'],
                                ['reach', 'Tỷ lệ tiếp cận', '136', ''],
                                ['downloads', 'Tải', '52', 'text-right'],
                                ['denied', 'Bị chặn', '64', 'text-right'],
                                [null, 'Watermark', '104', 'text-center'],
                                [null, 'Dùng trong khóa', '76', 'text-right'],
                            ] as [$col, $text, $width, $align])
                                <th scope="col" class="{{ $align }}" style="width: {{ $width }}px"
                                    @if ($col) aria-sort="{{ $sort === $col ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif>
                                    @if ($col)
                                        <button type="button" wire:click="sortBy('{{ $col }}')" class="inline-flex items-center gap-1 uppercase hover:text-brand-ink">
                                            {{ $text }} <span class="text-brand-red">{{ $sortIcon($col) }}</span>
                                        </button>
                                    @else
                                        {{ $text }}
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documents as $row)
                            <tr wire:key="doc-row-{{ $row['id'] }}">
                                <td>
                                    @if ($canLinkDocuments)
                                        <a href="{{ route('admin.documents', ['q' => $row['title']]) }}" wire:navigate
                                           class="font-semibold text-brand-red hover:underline">{{ $row['title'] }}</a>
                                    @else
                                        <span class="font-semibold">{{ $row['title'] }}</span>
                                    @endif
                                </td>
                                <td class="text-brand-muted">{{ $row['category'] ?? '—' }}</td>
                                <td>
                                    <span class="tag {{ $confTag[$row['confidentiality']] ?? '' }}">{{ $confidentialityLabels[$row['confidentiality']] ?? $row['confidentiality'] }}</span>
                                </td>
                                <td>{{ $row['version'] ? 'v' . $row['version'] : '—' }}</td>
                                <td class="tabular-nums">{{ $row['published_at'] ? \App\Support\Reports\ReportPeriod::local($row['published_at'])->format('d/m/Y') : '—' }}</td>
                                <td class="text-right tabular-nums">{{ $fmt($row['granted']) }}</td>
                                <td class="text-right tabular-nums">{{ $fmt($row['viewers']) }}</td>
                                <td>
                                    @if ($row['reach'] !== null)
                                        @php $low = $row['reach'] < $lowReach; @endphp
                                        <div class="flex items-center gap-2">
                                            <span class="relative h-2 flex-1 overflow-hidden rounded-sm bg-[#eef0f4]">
                                                <span class="absolute inset-y-0 left-0" style="width: {{ min(100, $row['reach']) }}%; background: {{ $low ? '#c00016' : '#141821' }}"></span>
                                            </span>
                                            <span @class(['w-12 text-right tabular-nums', 'font-semibold text-brand-red' => $low])>{{ $pct($row['reach']) }}</span>
                                        </div>
                                    @else
                                        <span class="text-brand-muted" title="Chưa ai được cấp quyền xem">—</span>
                                    @endif
                                </td>
                                <td class="text-right tabular-nums">{{ $row['is_video'] ? '—' : $fmt($row['downloads']) }}</td>
                                <td @class(['text-right tabular-nums', 'font-semibold text-brand-red' => ($row['denied'] ?? 0) > 0])>{{ $fmt($row['denied']) }}</td>
                                <td class="text-center">{{ $row['watermark'] ? 'Có' : 'Không' }}</td>
                                <td class="text-right tabular-nums">{{ $row['course_count'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="py-10 text-center text-sm text-brand-muted">Không có tài liệu đã xuất bản nào khớp bộ lọc.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-brand-line px-4 py-3 text-xs text-brand-muted">
                <span>
                    @if ($documents->total() > 0)
                        Hiển thị {{ $documents->firstItem() }}–{{ $documents->lastItem() }} / {{ $fmt($documents->total()) }} tài liệu đã xuất bản
                    @else
                        0 tài liệu đã xuất bản
                    @endif
                </span>
                <span>Số người được cấp quyền tính lại mỗi ngày</span>
                <span style="margin-left: auto">Nguồn: documents, document_versions, document_access_logs</span>
            </div>

            @if ($documents->hasPages())
                <div class="border-t border-brand-line px-4 py-3">{{ $documents->links(data: ['summary' => false]) }}</div>
            @endif
        @else
            <div class="overflow-x-auto">
                <table class="admin-table" style="min-width: 1000px">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 140px">Thời điểm</th>
                            <th scope="col" style="width: 200px">Nhân viên</th>
                            <th scope="col" style="width: 240px">Tài liệu</th>
                            <th scope="col" style="width: 150px">Hành động</th>
                            <th scope="col" style="width: 130px">IP</th>
                            <th scope="col" style="width: 260px">Mã watermark</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr wire:key="log-row-{{ $log->id }}">
                                <td class="tabular-nums">{{ \App\Support\Reports\ReportPeriod::local($log->accessed_at)->format('d/m/Y H:i') }}</td>
                                <td>
                                    <span class="block font-semibold">{{ $log->employee_name ?? $log->user_name ?? '—' }}</span>
                                    @if ($log->employee_code)
                                        <span class="block text-xs text-brand-muted">{{ $log->employee_code }}</span>
                                    @endif
                                </td>
                                <td>{{ $log->document_title }}</td>
                                <td>
                                    <span @class(['tag', 'tag-red' => $log->action === 'denied'])>{{ $actionLabels[$log->action] ?? $log->action }}</span>
                                    @if ($log->deny_reason)
                                        <span class="mt-1 block text-[11px] text-brand-muted">{{ $log->deny_reason }}</span>
                                    @endif
                                </td>
                                <td class="font-mono text-xs">{{ $log->ip_address ?? '—' }}</td>
                                <td class="font-mono text-xs">{{ $log->watermark_token ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-10 text-center text-sm text-brand-muted">Không có lượt truy cập nào trong kỳ khớp bộ lọc.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-brand-line px-4 py-3 text-xs text-brand-muted">
                <span>{{ $fmt($logs->total()) }} lượt truy cập · {{ $period->label() }}</span>
                <span>Mỗi dòng là một lượt ghi nhận, chưa gộp lượt xem lặp</span>
                <span style="margin-left: auto">Nguồn: document_access_logs</span>
            </div>

            @if ($logs->hasPages())
                <div class="border-t border-brand-line px-4 py-3">{{ $logs->links(data: ['summary' => false]) }}</div>
            @endif
        @endif
    </section>
</div>
