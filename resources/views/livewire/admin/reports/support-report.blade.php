@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', '.');
    $fmt1 = fn ($n) => number_format((float) $n, 1, ',', '.');
    $hidden = array_flip($hiddenColumns);
    $show = fn (string $col) => ! isset($hidden[$col]);
    $slaText = collect($slaHours)->map(fn ($h, $p) => \App\Models\SupportRequest::PRIORITY_LABELS[$p] . ' ' . $h . ' giờ')->implode(' · ');

    // Delta tỷ lệ đúng SLA so với kỳ trước, theo điểm phần trăm
    $slaDelta = null;
    $slaTone = 'neutral';
    if ($kpi['sla_rate'] !== null && $kpi['sla_rate_prev'] !== null) {
        $diff = round($kpi['sla_rate'] - $kpi['sla_rate_prev'], 1);
        $slaDelta = ($diff > 0 ? '▲ ' : ($diff < 0 ? '▼ ' : '')) . $fmt1(abs($diff)) . ' pp';
        $slaTone = $diff > 0 ? 'good' : ($diff < 0 ? 'bad' : 'neutral');
    }

    // Link chia sẻ từ thẻ KPI: giữ kỳ, đổi bộ lọc bảng
    $kpiLink = fn (array $params) => route('admin.reports.support', array_filter([
        'ky' => $preset, 'tu' => $preset === 'custom' ? $from : null, 'den' => $preset === 'custom' ? $to : null,
        'chu_de' => $topic ?: null, 'uu_tien' => $priority ?: null,
    ]) + $params);

    $overdueText = function ($seconds) {
        $hours = (int) floor($seconds / 3600);
        if ($hours < 48) {
            return max(1, $hours) . ' giờ';
        }
        $rest = $hours % 24;

        return intdiv($hours, 24) . ' ngày' . ($rest ? ' ' . $rest . ' giờ' : '');
    };

    $sortMark = fn (string $field) => $sort === $field ? ($direction === 'desc' ? '▼' : '▲') : '';
    // Chỉ phiếu đang mở và chưa có người phụ trách mới chọn được để phân công
    $assignable = fn ($row) => $row->assigned_to === null
        && in_array($row->status, [\App\Models\SupportRequest::STATUS_NEW, \App\Models\SupportRequest::STATUS_IN_PROGRESS], true);
    $pageIds = $rows->getCollection()->filter($assignable)->pluck('id')->map(fn ($id) => (string) $id)->values();
    $hasTicketLink = auth()->user()->can('support.view');
    $tableTitle = $overSla ? 'Yêu cầu quá SLA' : 'Danh sách yêu cầu';
@endphp

<div>
    <x-admin.page-header title="Báo cáo yêu cầu hỗ trợ"
                         subtitle="SLA theo độ ưu tiên: {{ $slaText }} (giờ lịch)">
        <x-slot:actions>
            @can('report_support.update')
                <button type="button" wire:click="openAssign" class="admin-btn-secondary">
                    @svg('heroicon-o-user-plus', 'h-4 w-4')
                    Phân công hàng loạt
                    @if (count($selected))
                        <span class="tag tag-red">{{ count($selected) }}</span>
                    @endif
                </button>
            @endcan
            <x-admin.report.export-menu />
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Bộ lọc --}}
    <div class="admin-panel mb-4 p-4">
        <div class="flex flex-wrap items-end gap-3">
            <x-admin.report.period-picker :preset="$preset" :from="$from" :to="$to" label="Kỳ (theo ngày gửi)" />

            <div>
                <label for="sr-topic" class="admin-label">Chủ đề</label>
                <select id="sr-topic" wire:model.live="topic" class="admin-input w-auto min-w-[160px]">
                    <option value="">Tất cả ({{ $topicOptions->count() }} chủ đề)</option>
                    @foreach ($topicOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="sr-priority" class="admin-label">Độ ưu tiên</label>
                <select id="sr-priority" wire:model.live="priority" class="admin-input w-auto min-w-[120px]">
                    <option value="">Tất cả</option>
                    @foreach (\App\Models\SupportRequest::PRIORITY_LABELS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="sr-status" class="admin-label">Trạng thái</label>
                <select id="sr-status" wire:model.live="status" class="admin-input w-auto min-w-[160px]">
                    <option value="open">Mở + Đang xử lý</option>
                    @foreach (\App\Models\SupportRequest::STATUS_LABELS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                    <option value="">Tất cả</option>
                </select>
            </div>

            <div>
                <label for="sr-assignee" class="admin-label">Người phụ trách</label>
                <select id="sr-assignee" wire:model.live="assignee" class="admin-input w-auto min-w-[160px]">
                    <option value="">Tất cả</option>
                    <option value="none">Chưa phân công</option>
                    @foreach ($assigneeOptions as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>

            <label class="flex h-10 cursor-pointer items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="overSla" class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                Chỉ quá SLA
            </label>

            <button type="button" wire:click="resetFilters" class="admin-btn-secondary" style="margin-left: auto">Đặt lại</button>
        </div>
    </div>

    {{-- Chỉ số --}}
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-admin.report.kpi label="Yêu cầu mới" :value="$fmt($kpi['new'])" note="gửi trong kỳ" />
        <x-admin.report.kpi label="Đang mở" :value="$fmt($kpi['open'])" :alert="$kpi['open_over_sla'] > 0"
                            hint="Mới + đang xử lý; quá SLA = đã quá hạn xử lý mà chưa xong"
                            :note="$fmt($kpi['open_over_sla']) . ' quá SLA'" :noteTone="$kpi['open_over_sla'] > 0 ? 'danger' : null"
                            :href="$kpiLink(['trang_thai' => 'open', 'qua_sla' => 1])" />
        <x-admin.report.kpi label="Đã giải quyết" :value="$fmt($kpi['resolved'])" note="xong trong kỳ" />
        <x-admin.report.kpi label="Thời gian giải quyết" :value="$kpi['median_hours'] !== null ? $fmt1($kpi['median_hours']) : '—'"
                            :note="$kpi['median_hours'] !== null ? 'giờ, trung vị' : 'chưa có phiếu xong'" />
        <x-admin.report.kpi label="Tỷ lệ đúng SLA" :value="$kpi['sla_rate'] !== null ? $fmt1($kpi['sla_rate']) . '%' : '—'"
                            hint="Phiếu giải quyết trước hạn SLA / tổng phiếu đã giải quyết"
                            :delta="$slaDelta" :deltaTone="$slaTone"
                            :note="$kpi['sla_rate'] === null ? 'chưa có phiếu xong' : null" />
        <x-admin.report.kpi label="Chưa phân công" :value="$fmt($kpi['unassigned'])"
                            :note="$kpi['unassigned'] > 0 ? 'chưa có người nhận' : 'đã có người nhận hết'"
                            :noteTone="$kpi['unassigned'] > 0 ? 'danger' : null"
                            :href="$kpiLink(['trang_thai' => 'open', 'phu_trach' => 'none', 'qua_sla' => 0])" />
    </div>

    {{-- Biểu đồ --}}
    <div class="mb-4 grid gap-4 xl:grid-cols-2">
        <x-admin.report.panel title="Yêu cầu mới và đã giải quyết theo tuần" :note="$weekly['note']" :noteAlert="(bool) $weekly['note']">
            <x-slot:actions>
                <x-admin.chart.legend :items="[
                    ['label' => 'Mới', 'color' => '#141821'],
                    ['label' => 'Đã giải quyết', 'color' => '#d1d5db'],
                    ['label' => 'Tồn đọng', 'color' => '#c00016', 'type' => 'line'],
                ]" />
            </x-slot:actions>

            @if (array_sum($weekly['new']) + array_sum($weekly['resolved']) + array_sum($weekly['backlog']) === 0)
                <p class="py-10 text-center text-sm text-brand-muted">Chưa có yêu cầu nào trong kỳ.</p>
            @else
                <x-admin.chart.combo :width="560"
                    :labels="$weekly['labels']"
                    :bars="[
                        ['name' => 'Mới', 'values' => $weekly['new'], 'color' => '#141821'],
                        ['name' => 'Đã giải quyết', 'values' => $weekly['resolved'], 'color' => '#d1d5db'],
                    ]"
                    :lines="[['name' => 'Tồn đọng', 'values' => $weekly['backlog'], 'color' => '#c00016', 'dots' => true]]"
                    :endNote="$fmt(last($weekly['backlog'])) . ' tồn đọng'"
                    :height="240"
                    label="Yêu cầu mới, đã giải quyết và tồn đọng theo tuần" />
            @endif
        </x-admin.report.panel>

        <x-admin.report.panel title="Phân bố theo chủ đề" subtitle="số phiếu gửi trong kỳ · nhãn phụ: trung vị giờ xử lý"
                              :note="$topics['note']" :noteAlert="$topics['alert']">
            @if (empty($topics['rows']))
                <p class="py-10 text-center text-sm text-brand-muted">Chưa có yêu cầu nào trong kỳ.</p>
            @else
                @php
                    $limit = $slaHours[\App\Models\SupportRequest::PRIORITY_NORMAL];
                    $topicRows = collect($topics['rows'])->map(function ($r) use ($fmt, $fmt1, $limit) {
                        $slow = $r['median_hours'] !== null && $r['median_hours'] > $limit;

                        return [
                            'label' => $r['topic'],
                            'segments' => [['value' => $r['count'], 'color' => $slow ? '#c00016' : '#141821', 'name' => 'Số phiếu']],
                            'value' => $fmt($r['count']),
                            'sub' => $r['median_hours'] !== null ? $fmt1($r['median_hours']) . ' giờ' : '—',
                            'subTone' => $slow ? 'danger' : null,
                        ];
                    });
                @endphp
                <x-admin.chart.hbar :rows="$topicRows" labelWidth="minmax(5rem, 11rem)" valueWidth="2.5rem" subWidth="4.5rem" />
            @endif
        </x-admin.report.panel>
    </div>

    {{-- Bảng --}}
    <section class="admin-panel min-w-0">
        <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-2.5">
                <h2 class="text-[15px] font-bold">{{ $tableTitle }}</h2>
                <span class="text-xs text-brand-muted">{{ $fmt($rows->total()) }} yêu cầu{{ $sort === 'overdue' && $direction === 'desc' ? ' · xếp theo thời gian quá hạn giảm dần' : '' }}</span>
                @if ($overSla)
                    {{-- Định nghĩa "quá SLA" ngay cạnh tiêu đề: người xem hay hỏi đây là gì --}}
                    <p class="basis-full text-xs text-brand-muted">
                        <strong class="text-brand-ink">Quá SLA</strong> = yêu cầu chưa được giải quyết xong khi đã quá hạn xử lý cam kết
                        theo độ ưu tiên (Cao 8 giờ, Thường 24 giờ, Thấp 72 giờ kể từ lúc gửi).
                        Ô chọn chỉ có ở yêu cầu chưa có người phụ trách.
                    </p>
                @endif
            </div>
            <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
                <button type="button" x-on:click="open = ! open" class="admin-btn-secondary" aria-haspopup="menu" x-bind:aria-expanded="open">
                    Cột hiển thị @svg('heroicon-m-chevron-down', 'h-4 w-4')
                </button>
                <div x-show="open" x-cloak x-on:click.outside="open = false"
                     class="absolute right-0 z-30 mt-1 w-56 rounded-admin border border-brand-line bg-white py-1 shadow-user">
                    @foreach (\App\Livewire\Admin\Reports\SupportReport::COLUMNS as $key => $label)
                        <label wire:key="col-{{ $key }}" class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-brand-soft">
                            <input type="checkbox" class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red"
                                   @checked($show($key))
                                   x-on:change="$wire.set('hiddenColumns', $event.target.checked
                                        ? $wire.hiddenColumns.filter(c => c !== '{{ $key }}')
                                        : [...$wire.hiddenColumns, '{{ $key }}'])">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        </header>

        <div class="overflow-x-auto border-t border-brand-line">
            <table class="admin-table" style="min-width: 1100px">
                <thead>
                    <tr>
                        <th class="w-10">
                            @can('report_support.update')
                                <input type="checkbox" aria-label="Chọn cả trang"
                                       class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red"
                                       @checked($pageIds->isNotEmpty() && $pageIds->diff($selected)->isEmpty())
                                       x-on:change="$wire.set('selected', $event.target.checked
                                            ? [...new Set([...$wire.selected, ...@js($pageIds)])]
                                            : $wire.selected.filter(id => ! @js($pageIds).includes(id)))">
                            @endcan
                        </th>
                        <th class="w-24">Mã yêu cầu</th>
                        @if ($show('created_at'))
                            <th class="w-20"><button type="button" wire:click="sortBy('created_at')" class="uppercase">Ngày gửi {{ $sortMark('created_at') }}</button></th>
                        @endif
                        @if ($show('employee'))<th class="w-36">Người gửi</th>@endif
                        @if ($show('topic'))<th class="w-24">Chủ đề</th>@endif
                        @if ($show('subject'))<th>Tiêu đề</th>@endif
                        @if ($show('priority'))
                            <th class="w-24"><button type="button" wire:click="sortBy('priority')" class="uppercase">Ưu tiên {{ $sortMark('priority') }}</button></th>
                        @endif
                        @if ($show('status'))<th class="w-28">Trạng thái</th>@endif
                        @if ($show('assignee'))<th class="w-28">Phụ trách</th>@endif
                        @if ($show('sla_due_at'))
                            <th class="w-20"><button type="button" wire:click="sortBy('sla_due_at')" class="uppercase">Hạn SLA {{ $sortMark('sla_due_at') }}</button></th>
                        @endif
                        @if ($show('overdue'))
                            <th class="w-24 text-right"><button type="button" wire:click="sortBy('overdue')" class="uppercase">Quá hạn {{ $sortMark('overdue') }}</button></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $created = \App\Support\Reports\ReportPeriod::local($row->created_at);
                            $due = \App\Support\Reports\ReportPeriod::local($row->sla_due_at);
                            $isOpen = in_array($row->status, [\App\Models\SupportRequest::STATUS_NEW, \App\Models\SupportRequest::STATUS_IN_PROGRESS], true);
                            $late = $row->overdue_seconds !== null && $row->overdue_seconds > 0;
                        @endphp
                        <tr wire:key="sr-row-{{ $row->id }}">
                            <td>
                                @can('report_support.update')
                                    @if ($assignable($row))
                                        <input type="checkbox" wire:model.live="selected" value="{{ $row->id }}" aria-label="Chọn {{ $row->ticket_no }}"
                                               class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                                    @endif
                                @endcan
                            </td>
                            <td class="font-semibold text-brand-red">
                                {{-- Màn Phiếu hỗ trợ chưa mở thẳng chi tiết theo URL: link lọc đúng phiếu đó, bấm tiêu đề để xem --}}
                                @if ($hasTicketLink)
                                    <a href="{{ route('admin.support', ['q' => $row->ticket_no, 'statusFilter' => '']) }}" wire:navigate
                                       class="text-brand-red hover:underline" title="Mở ở màn Phiếu hỗ trợ">{{ $row->ticket_no }}</a>
                                @else
                                    {{ $row->ticket_no }}
                                @endif
                            </td>
                            @if ($show('created_at'))
                                <td class="tabular-nums" title="{{ $created?->format('d/m/Y H:i') }}">{{ $created?->format('d/m') }}<br><span class="text-brand-muted">{{ $created?->format('H:i') }}</span></td>
                            @endif
                            @if ($show('employee'))
                                <td>
                                    {{ $row->employee?->full_name ?? '—' }}
                                    @if ($row->employee?->department)
                                        <span class="text-brand-muted">· {{ $row->employee->department->name }}</span>
                                    @endif
                                </td>
                            @endif
                            @if ($show('topic'))<td class="text-brand-muted">{{ $row->topic }}</td>@endif
                            @if ($show('subject'))<td>{{ $row->subject }}</td>@endif
                            @if ($show('priority'))
                                <td>
                                    <span @class([
                                        'tag whitespace-nowrap',
                                        'tag-red font-semibold' => $row->priority === 'high',
                                        'border border-brand-line bg-white text-brand-muted' => $row->priority === 'low',
                                    ])>{{ \App\Models\SupportRequest::PRIORITY_LABELS[$row->priority] ?? $row->priority }}</span>
                                </td>
                            @endif
                            @if ($show('status'))
                                <td>
                                    <span @class([
                                        'tag whitespace-nowrap',
                                        'tag-red' => $row->status === 'new',
                                        'tag-amber' => $row->status === 'in_progress',
                                        'tag-green' => $row->status === 'resolved',
                                    ])>{{ \App\Models\SupportRequest::STATUS_LABELS[$row->status] ?? $row->status }}</span>
                                </td>
                            @endif
                            @if ($show('assignee'))
                                <td>
                                    @if ($row->assignedTo)
                                        {{ $row->assignedTo->name }}
                                    @else
                                        <span class="font-semibold text-brand-red">Chưa phân công</span>
                                    @endif
                                </td>
                            @endif
                            @if ($show('sla_due_at'))
                                <td @class(['tabular-nums', 'text-brand-red' => $late])>
                                    @if ($due)
                                        {{ $due->format('d/m') }}<br><span @class(['text-brand-muted' => ! $late])>{{ $due->format('H:i') }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                            @if ($show('overdue'))
                                <td class="text-right tabular-nums">
                                    @if ($row->overdue_seconds === null)
                                        <span class="text-brand-muted">—</span>
                                    @elseif ($late)
                                        <span class="font-semibold text-brand-red">{{ $overdueText($row->overdue_seconds) }}</span>
                                        @unless ($isOpen)
                                            <span class="block text-xs text-brand-muted">đã xong</span>
                                        @endunless
                                    @elseif ($isOpen)
                                        <span class="text-brand-muted">còn {{ $overdueText(-$row->overdue_seconds) }}</span>
                                    @else
                                        <span class="text-state-success">Đúng hạn</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="py-10 text-center text-sm text-brand-muted">
                                {{ $overSla ? 'Không có yêu cầu quá SLA khớp bộ lọc.' : 'Không có yêu cầu khớp bộ lọc.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-line px-5 py-3 text-xs text-brand-muted">
            <span>
                @if ($rows->total() > 0)
                    Hiển thị {{ $rows->firstItem() }}–{{ $rows->lastItem() }} / {{ $fmt($rows->total()) }} {{ $overSla ? 'yêu cầu quá SLA' : 'yêu cầu' }} ·
                @endif
                Nội dung chi tiết chỉ mở được khi có quyền xem trường nhạy cảm
            </span>
            <span>Cập nhật {{ \App\Support\Reports\ReportPeriod::nowLabel() }}</span>
        </div>
        @if ($rows->hasPages())
            <div class="border-t border-brand-line px-5 py-3">{{ $rows->links(data: ['summary' => false]) }}</div>
        @endif
    </section>

    {{-- Phân công hàng loạt --}}
    @can('report_support.update')
        <x-admin.modal wireModel="showAssign" title="Phân công hàng loạt" maxWidth="max-w-lg">
            @if (count($selected) === 0)
                <p class="text-sm text-brand-muted">Hãy tick chọn yêu cầu ở bảng bên dưới trước, rồi bấm lại "Phân công hàng loạt".</p>
            @else
                <p class="mb-4 text-sm">Gán <strong>{{ count($selected) }} yêu cầu</strong> đang chọn cho một người phụ trách.
                    Phiếu "Mới" sẽ chuyển sang "Đang xử lý"; phiếu đã giải quyết / đã đóng được bỏ qua.</p>
                <label for="sr-assign-to" class="admin-label">Người phụ trách</label>
                <select id="sr-assign-to" wire:model.live="assignTo" class="admin-input">
                    <option value="">— Chọn người phụ trách —</option>
                    @foreach ($assignableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
                @error('assignTo')<p class="mt-1 text-xs text-brand-red">{{ $message }}</p>@enderror
                @error('selected')<p class="mt-1 text-xs text-brand-red">{{ $message }}</p>@enderror
            @endif

            <x-slot:footer>
                <button type="button" wire:click="$set('showAssign', false)" class="admin-btn-secondary">Hủy</button>
                @if (count($selected) > 0)
                    @php $assigneeName = $assignTo ? $assignableUsers->firstWhere('id', (int) $assignTo)?->name : null; @endphp
                    <button type="button" wire:click="bulkAssign" class="admin-btn" @disabled(! $assignTo)
                            data-confirm="Phân công {{ count($selected) }} yêu cầu cho {{ $assigneeName ?? 'người đã chọn' }}?"
                            data-confirm-title="Phân công hàng loạt" data-confirm-ok="Phân công">
                        Phân công
                    </button>
                @endif
            </x-slot:footer>
        </x-admin.modal>
    @endcan
</div>
