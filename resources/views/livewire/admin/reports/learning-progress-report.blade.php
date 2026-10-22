@php
    use App\Livewire\Admin\Reports\LearningProgressReport as Report;
    use App\Services\Reports\LearningProgressReportService as Service;
    use App\Support\Reports\ReportPeriod;

    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    $pct = fn ($n) => $n === null ? '—' : number_format((float) $n, 1, ',', '.') . '%';
    $updatedAt = \Carbon\CarbonImmutable::now(ReportPeriod::TIMEZONE)->format('H:i d/m/Y');

    // Độ rộng cột (px) — bảng table-fixed nên phải đặt rõ, cộng lại làm min-width để cuộn ngang
    $widths = [
        'code' => 104, 'name' => 160, 'department' => 150, 'job_title' => 150, 'course' => 200,
        'type' => 124, 'source' => 120, 'assigned' => 108, 'due' => 108, 'completed' => 128,
        'lessons' => 92, 'progress' => 160, 'hours' => 88, 'score' => 76, 'status' => 128,
        'overdue_days' => 96, 'reminded' => 136,
    ];
    $rightAligned = ['lessons', 'hours', 'score', 'overdue_days', 'assigned', 'due', 'completed'];
    $tableWidth = 48 + collect($visible)->sum(fn ($k) => $widths[$k]);

    // Dòng "Tổng cộng": nhãn chiếm các cột đầu cho tới cột đầu tiên có số tổng
    $summable = ['lessons', 'progress', 'hours', 'overdue_days'];
    $labelSpan = 1;
    foreach ($visible as $key) {
        if (in_array($key, $summable, true)) break;
        $labelSpan++;
    }
    $footCols = array_slice($visible, $labelSpan - 1);

    $pageIds = $rows->pluck('id')->map(fn ($id) => (string) $id)->all();
    $pageAllSelected = $pageIds !== [] && array_diff($pageIds, $selected) === [];
    $advancedCount = count(array_diff_key($chips, ['completedOn' => true]));

    $stateTag = fn ($row) => match (true) {
        $row->state === 'overdue' => 'tag tag-red',
        $row->state === 'completed' => 'tag tag-green',
        (bool) $row->due_soon => 'tag tag-amber',
        default => 'tag',
    };

    $lastLine = collect($chart['line'])->filter(fn ($v) => $v !== null)->last();

    // Biểu đồ luôn in nhãn ngày cuối: chọn bước nhãn sao cho nhãn cuối không đè nhãn liền trước
    $pointCount = count($chart['labels']);
    $tickEvery = max(1, (int) ceil($pointCount / 8));
    foreach (range($tickEvery, $tickEvery + 3) as $step) {
        $gap = ($pointCount - 1) % $step;
        if ($gap === 0 || $gap >= $step * 0.6) {
            $tickEvery = $step;
            break;
        }
    }
@endphp

<div class="space-y-5">
    <x-admin.page-header title="Báo cáo tiến độ học tập"
        subtitle="Một dòng là một lượt ghi danh · nhân viên × khóa học · cập nhật {{ $updatedAt }}">
        <x-slot:actions>
            @can('report_learning.update')
                <button type="button" wire:click="sendReminders" class="admin-btn-secondary"
                        @disabled($remindCount === 0)
                        data-confirm="{{ $selected !== []
                            ? 'Gửi nhắc cho ' . $num($remindCount) . ' lượt ghi danh đang chọn? Lượt đã hoàn thành hoặc vừa được nhắc trong 12 giờ sẽ được bỏ qua.'
                            : 'Chưa chọn dòng nào — gửi nhắc cho toàn bộ ' . $num($remindCount) . ' lượt chưa hoàn thành khớp bộ lọc? Lượt vừa được nhắc trong 12 giờ sẽ được bỏ qua.' }}"
                        data-confirm-title="Gửi nhắc hàng loạt" data-confirm-ok="Gửi nhắc">
                    Gửi nhắc hàng loạt
                    @if ($selected !== [])
                        <span class="tag tag-red">{{ $num(count($selected)) }} đã chọn</span>
                    @endif
                </button>
            @endcan
            <x-admin.report.export-menu />
        </x-slot:actions>
    </x-admin.page-header>

    @if ($notice)
        <div class="flex items-start justify-between gap-3 rounded-admin border border-state-success bg-state-success-tint px-4 py-3 text-sm font-semibold text-state-success" role="status">
            <span>{{ $notice }}</span>
            <button type="button" wire:click="$set('notice', null)" class="shrink-0" aria-label="Đóng">✕</button>
        </div>
    @endif

    {{-- Bộ lọc --}}
    <section class="admin-panel p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="lp-basis" class="admin-label">Mốc thời gian</label>
                <select id="lp-basis" wire:model.live="basis" class="admin-input w-auto">
                    @foreach (Service::BASIS_LABELS as $key => $text)
                        <option value="{{ $key }}">{{ $text }}</option>
                    @endforeach
                </select>
            </div>

            <x-admin.report.period-picker :preset="$preset" :from="$from" :to="$to" label="Kỳ" />

            <div class="min-w-0 max-w-full">
                <label for="lp-department" class="admin-label">Đơn vị</label>
                <select id="lp-department" wire:model.live="department" class="admin-input w-auto max-w-full">
                    <option value="">Tất cả ({{ $num(count($departments)) }} đơn vị)</option>
                    @foreach ($departments as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="min-w-0 max-w-full">
                <label for="lp-course" class="admin-label">Khóa học</label>
                <select id="lp-course" wire:model.live="course" class="admin-input w-auto max-w-full">
                    <option value="">Tất cả ({{ $num(count($courses)) }} khóa)</option>
                    @foreach ($courses as $id => $title)
                        <option value="{{ $id }}">{{ $title }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="lp-status" class="admin-label">Trạng thái</label>
                <select id="lp-status" wire:model.live="status" class="admin-input w-auto">
                    @foreach (Service::STATUS_FILTERS as $key => $text)
                        <option value="{{ $key }}">{{ $text }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Bộ lọc nâng cao: gom các điều kiện ít dùng để thanh lọc không quá dài --}}
            <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
                <button type="button" x-on:click="open = ! open" class="admin-btn-secondary" x-bind:aria-expanded="open">
                    Bộ lọc nâng cao
                    @if ($advancedCount > 0)
                        <span class="tag tag-red">{{ $advancedCount }}</span>
                    @endif
                    @svg('heroicon-m-chevron-down', 'h-4 w-4')
                </button>
                <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity
                     class="absolute left-0 z-30 mt-1 w-56 space-y-3 rounded-admin border border-brand-line bg-white p-4 shadow-user">
                    <div>
                        <label for="lp-type" class="admin-label">Loại khóa</label>
                        <select id="lp-type" wire:model.live="courseType" class="admin-input">
                            <option value="">Tất cả</option>
                            <option value="mandatory">Bắt buộc</option>
                            <option value="elective">Tự chọn</option>
                        </select>
                    </div>
                    <div>
                        <span class="admin-label">Tiến độ (%)</span>
                        <div class="flex items-center gap-2">
                            <label class="sr-only" for="lp-pmin">Tiến độ từ</label>
                            <input id="lp-pmin" type="number" min="0" max="100" placeholder="0" wire:model.live.debounce.500ms="progressMin" class="admin-input">
                            <span class="text-brand-muted">–</span>
                            <label class="sr-only" for="lp-pmax">Tiến độ đến</label>
                            <input id="lp-pmax" type="number" min="0" max="100" placeholder="100" wire:model.live.debounce.500ms="progressMax" class="admin-input">
                        </div>
                    </div>
                    <div>
                        <label for="lp-source" class="admin-label">Nguồn giao</label>
                        <select id="lp-source" wire:model.live="source" class="admin-input">
                            <option value="">Tất cả</option>
                            <option value="rule">Rule tự động</option>
                            <option value="manual">Giao tay</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="excludeResigned" class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        Bỏ nhân viên đã nghỉ
                    </label>
                </div>
            </div>

            <button type="button" wire:click="resetFilters" class="admin-btn-secondary">Đặt lại</button>
        </div>

        @if ($chips !== [])
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-dashed border-brand-line pt-3 text-xs">
                <span class="font-semibold">Đang áp dụng:</span>
                @foreach ($chips as $key => $text)
                    <button type="button" wire:click="clearFilter('{{ $key }}')"
                            class="inline-flex items-center gap-1.5 rounded-admin-sm border border-brand-line bg-white px-2 py-1 hover:border-brand-red"
                            title="Bỏ bộ lọc này">
                        {{ $text }} <span class="text-brand-red" aria-hidden="true">✕</span>
                        <span class="sr-only">Bỏ bộ lọc</span>
                    </button>
                @endforeach
            </div>
        @endif
    </section>

    {{-- KPI: tính trên tập khớp bộ lọc (không gồm Trạng thái / ô tìm của bảng). Bấm thẻ để lọc bảng. --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-admin.report.kpi label="Tổng lượt ghi danh" :value="$num($kpis['total'])"
            :note="$period->label()"
            wire:click="filterStatus('')" role="button" title="Xem tất cả trạng thái"
            class="cursor-pointer" />
        @foreach (['completed' => 'Hoàn thành', 'in_progress' => 'Đang học', 'not_started' => 'Chưa bắt đầu'] as $key => $label)
            <x-admin.report.kpi :label="$label" :value="$num($kpis[$key])" :note="$pct($kpis[$key . '_pct'])"
                wire:click="filterStatus('{{ $key }}')" role="button" title="Lọc bảng: {{ $label }}"
                :class="\Illuminate\Support\Arr::toCssClasses(['cursor-pointer', 'bg-brand-soft' => $status === $key])" />
        @endforeach
        <x-admin.report.kpi label="Quá hạn" :value="$num($kpis['overdue'])" :alert="$kpis['overdue'] > 0"
            :note="$pct($kpis['overdue_pct']) . ' · ' . $num($kpis['overdue_employees']) . ' nhân viên'"
            :noteTone="$kpis['overdue'] > 0 ? 'danger' : null"
            wire:click="filterStatus('overdue')" role="button" title="Lọc bảng: Quá hạn"
            :class="\Illuminate\Support\Arr::toCssClasses(['cursor-pointer', 'bg-brand-soft' => $status === 'overdue'])" />
        <x-admin.report.kpi label="Hoàn thành đúng hạn" :value="$pct($kpis['on_time_pct'])"
            :note="'mục tiêu ' . $pct($target)"
            :noteTone="$kpis['on_time_pct'] !== null && $kpis['on_time_pct'] < $target ? 'danger' : null"
            title="{{ $num($kpis['on_time']) }}/{{ $num($kpis['judged']) }} lượt hoàn thành trước hoặc đúng hạn, trên số lượt đã có kết quả so với hạn (đã hoàn thành, hoặc đã quá hạn mà chưa xong)" />
    </div>

    {{-- Biểu đồ --}}
    <x-admin.report.panel title="Lượt hoàn thành theo ngày và tỷ lệ lũy kế"
        :subtitle="$period->label()"
        :note="$kpis['total'] > 0
            ? $num($chart['completions']) . ' lượt hoàn thành trong kỳ; tỷ lệ hoàn thành lũy kế cuối kỳ <strong>' . $pct($lastLine) . '</strong> so với mục tiêu ' . $pct($target) . '.'
            : null"
        :noteAlert="$lastLine !== null && $lastLine < $target">
        <x-slot:actions>
            <x-admin.chart.legend :items="[
                ['label' => 'Lượt hoàn thành (trục trái)', 'color' => '#141821'],
                ['label' => 'Tỷ lệ hoàn thành lũy kế (trục phải)', 'color' => '#6b7280', 'type' => 'line'],
                ['label' => 'Mục tiêu ' . $pct($target), 'color' => '#c00016', 'type' => 'dashed'],
            ]" />
        </x-slot:actions>

        @if ($kpis['total'] === 0)
            <p class="py-12 text-center text-sm text-brand-muted">Không có lượt ghi danh nào khớp bộ lọc trong kỳ này.</p>
        @else
            <x-admin.chart.combo
                :labels="$chart['labels']"
                :bars="[['name' => 'Lượt hoàn thành', 'values' => $chart['bars'], 'color' => '#141821']]"
                :lines="[['name' => 'Tỷ lệ lũy kế', 'values' => $chart['line'], 'color' => '#6b7280', 'axis' => 'right']]"
                :target="['value' => $target, 'axis' => 'right', 'label' => 'mục tiêu ' . $pct($target)]"
                :rightMax="100" rightSuffix="%"
                :tickEvery="$tickEvery"
                clickAction="filterCompletedOn"
                :activeIndex="$chartActive === false ? null : $chartActive"
                :endNote="$pct($lastLine)"
                label="Lượt hoàn thành theo ngày và tỷ lệ hoàn thành lũy kế" />
            <p class="mt-2 text-xs text-brand-muted">
                Bấm một cột để lọc bảng bên dưới theo ngày hoàn thành.
                @if ($completedOn !== '')
                    Đang lọc ngày <strong class="text-brand-ink">{{ \Carbon\CarbonImmutable::parse($completedOn)->format('d/m/Y') }}</strong> ·
                    <button type="button" wire:click="clearFilter('completedOn')" class="font-semibold text-brand-red hover:underline">Bỏ lọc ngày</button>
                @endif
            </p>
        @endif
    </x-admin.report.panel>

    {{-- Bảng --}}
    <section class="admin-panel min-w-0">
        <header class="flex flex-wrap items-center gap-3 border-b border-brand-line px-5 py-4">
            <h2 class="text-[15px] font-bold">Danh sách lượt ghi danh</h2>
            <div class="relative w-full max-w-sm">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-brand-muted">
                    @svg('heroicon-o-magnifying-glass', 'h-4 w-4')
                </span>
                <label for="lp-search" class="sr-only">Lọc trong bảng</label>
                <input id="lp-search" type="search" wire:model.live.debounce.400ms="search" class="admin-input pl-10"
                       placeholder="Lọc theo mã NV, họ tên, đơn vị, khóa học…">
            </div>
            <span class="flex-1 text-xs text-brand-muted">{{ $num($rows->total()) }} / {{ $num($kpis['total']) }} dòng khớp bộ lọc</span>

            <div class="flex flex-wrap items-center gap-3">
                <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
                    <button type="button" x-on:click="open = ! open" class="admin-btn-secondary" x-bind:aria-expanded="open">
                        Cột hiển thị ({{ count($visible) }}/{{ count(Report::COLUMNS) }})
                        @svg('heroicon-m-chevron-down', 'h-4 w-4')
                    </button>
                    <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity
                         class="absolute right-0 z-30 mt-1 max-h-64 w-56 overflow-y-auto rounded-admin border border-brand-line bg-white py-1 shadow-user">
                        @foreach (Report::COLUMNS as $key => $label)
                            <label class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm hover:bg-brand-soft">
                                <input type="checkbox" value="{{ $key }}" wire:model.live="columns" class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <label for="lp-per-page" class="text-xs text-brand-muted">Số dòng</label>
                <select id="lp-per-page" wire:model.live="perPage" class="admin-input w-auto">
                    @foreach ($perPageOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>
        </header>

        <div class="overflow-x-auto">
            <table class="admin-table" style="min-width: {{ $tableWidth }}px">
                <colgroup>
                    <col style="width: 48px">
                    @foreach ($visible as $key)
                        <col style="width: {{ $widths[$key] }}px">
                    @endforeach
                </colgroup>
                <thead>
                    <tr>
                        <th>
                            <input type="checkbox" class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red" aria-label="Chọn cả trang"
                                   @checked($pageAllSelected) @disabled($pageIds === [])
                                   wire:click="togglePage(@js($pageIds))">
                        </th>
                        @foreach ($visible as $key)
                            <th @class(['text-right' => in_array($key, $rightAligned, true)])
                                aria-sort="{{ $sort === $key ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                <button type="button" wire:click="sortBy('{{ $key }}')"
                                        @class(['inline-flex items-center gap-1 uppercase hover:text-brand-ink', 'w-full justify-end' => in_array($key, $rightAligned, true), 'text-brand-ink' => $sort === $key])>
                                    {{ Report::COLUMNS[$key] }}
                                    @if ($sort === $key)
                                        <span aria-hidden="true">{{ $direction === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $overdueDays = Report::overdueDays($row, $today);
                            $progress = (float) $row->progress_percent;
                        @endphp
                        <tr wire:key="enr-{{ $row->id }}">
                            <td>
                                <input type="checkbox" value="{{ $row->id }}" wire:model.live="selected"
                                       class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red" aria-label="Chọn {{ $row->employee_code }} – {{ $row->course_title }}">
                            </td>
                            @foreach ($visible as $key)
                                <td @class(['text-right tabular-nums' => in_array($key, $rightAligned, true)])>
                                    @switch($key)
                                        @case('code')
                                            @can('employees.view')
                                                <a href="{{ route('admin.employees.show', $row->employee_id) }}" wire:navigate
                                                   class="font-semibold text-brand-red hover:underline">{{ $row->employee_code }}</a>
                                            @else
                                                <span class="font-semibold text-brand-red">{{ $row->employee_code }}</span>
                                            @endcan
                                            @break
                                        @case('name')
                                            <span class="font-semibold">{{ $row->full_name }}</span>
                                            @if ($row->employment_status === \App\Models\Employee::STATUS_RESIGNED)
                                                <span class="block text-xs text-brand-muted">Đã nghỉ</span>
                                            @endif
                                            @break
                                        @case('department')
                                            <span class="text-brand-muted">{{ $row->department_name ?? '—' }}</span>
                                            @break
                                        @case('job_title')
                                            <span class="text-brand-muted">{{ $row->job_title_name ?? '—' }}</span>
                                            @break
                                        @case('course')
                                            {{ $row->course_title }}
                                            @break
                                        @case('type')
                                            @php $type = Report::typeLabel($row); @endphp
                                            <span @class(['tag uppercase', 'tag-red' => $type === 'Bắt buộc'])>{{ $type }}</span>
                                            @break
                                        @case('source')
                                            {{ $row->course_assignment_rule_id ? 'Rule tự động' : 'Giao tay' }}
                                            @break
                                        @case('assigned')
                                            {{ ReportPeriod::local($row->assigned_at)?->format('d/m/Y') ?? '—' }}
                                            @break
                                        @case('due')
                                            <span @class(['font-semibold text-brand-red' => $row->state === 'overdue'])>{{ $row->due_date?->format('d/m/Y') ?? '—' }}</span>
                                            @break
                                        @case('completed')
                                            {{ ReportPeriod::local($row->completed_at)?->format('d/m/Y') ?? '—' }}
                                            @break
                                        @case('lessons')
                                            {{ $row->completed_lessons }}/{{ $row->total_lessons }}
                                            @break
                                        @case('progress')
                                            <div class="flex items-center gap-2">
                                                <div class="progress-track flex-1" role="progressbar" aria-valuenow="{{ round($progress) }}" aria-valuemin="0" aria-valuemax="100">
                                                    <div class="progress-fill" style="width: {{ min(100, $progress) }}%; {{ $row->state === 'overdue' ? '' : 'background: #141821' }}"></div>
                                                </div>
                                                <span class="w-12 shrink-0 text-right text-xs tabular-nums">{{ $progress >= 100 ? '100%' : $pct($progress) }}</span>
                                            </div>
                                            @break
                                        @case('hours')
                                            {{ number_format($row->time_spent / 3600, 1, ',', '.') }} giờ
                                            @break
                                        @case('score')
                                            {{ $row->final_score !== null ? number_format((float) $row->final_score, 1, ',', '.') : '—' }}
                                            @break
                                        @case('status')
                                            <span class="{{ $stateTag($row) }}">{{ Report::statusLabel($row) }}</span>
                                            @break
                                        @case('overdue_days')
                                            @if ($overdueDays !== null)
                                                <span class="font-semibold text-brand-red">{{ $num($overdueDays) }}</span>
                                            @else
                                                <span class="text-brand-muted">—</span>
                                            @endif
                                            @break
                                        @case('reminded')
                                            @if ($row->last_reminded_at)
                                                {{ ReportPeriod::local($row->last_reminded_at)->format('d/m/Y H:i') }}
                                            @else
                                                <span class="text-brand-muted">Chưa nhắc</span>
                                            @endif
                                            @break
                                    @endswitch
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($visible) + 1 }}" class="py-10 text-center text-sm text-brand-muted">
                                Không có lượt ghi danh nào khớp bộ lọc.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($totals['total'] > 0)
                    <tfoot>
                        <tr class="bg-brand-soft font-bold">
                            <td colspan="{{ $labelSpan }}">Tổng cộng {{ $num($totals['total']) }} dòng khớp bộ lọc</td>
                            @foreach ($footCols as $key)
                                <td @class(['text-right tabular-nums' => in_array($key, $rightAligned, true)])>
                                    @switch($key)
                                        @case('lessons')
                                            {{ $num($totals['lessons_done']) }}/{{ $num($totals['lessons_total']) }}
                                            @break
                                        @case('progress')
                                            <span title="Tiến độ trung bình">TB {{ $pct($totals['avg_progress']) }}</span>
                                            @break
                                        @case('hours')
                                            {{ number_format($totals['hours'], 1, ',', '.') }} giờ
                                            @break
                                        @case('overdue_days')
                                            <span @class(['text-brand-red' => $totals['overdue'] > 0]) title="Số lượt quá hạn">{{ $num($totals['overdue']) }} lượt</span>
                                            @break
                                    @endswitch
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        {{-- Phân trang (tự vẽ để có chữ tiếng Việt "Hiển thị a–b / N dòng") --}}
        @if ($rows->total() > 0)
            @php
                $current = $rows->currentPage();
                $last = $rows->lastPage();
                $pages = collect([1, $last, $current - 1, $current, $current + 1])
                    ->filter(fn ($p) => $p >= 1 && $p <= $last)->unique()->sort()->values();
                $pagerBtn = 'inline-flex h-8 w-9 items-center justify-center rounded-admin-sm border text-[13px]';
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-line px-5 py-3">
                <span class="text-xs text-brand-muted">Hiển thị {{ $num($rows->firstItem()) }}–{{ $num($rows->lastItem()) }} / {{ $num($rows->total()) }} dòng</span>
                @if ($last > 1)
                    <nav class="flex flex-wrap items-center gap-1" aria-label="Phân trang">
                        <button type="button" wire:click="previousPage" @disabled($current === 1)
                                @class([$pagerBtn, 'border-brand-line bg-white hover:bg-brand-soft', 'opacity-40' => $current === 1]) aria-label="Trang trước">‹</button>
                        @foreach ($pages as $i => $p)
                            @if ($i > 0 && $p - $pages[$i - 1] > 1)
                                <span class="px-1 text-brand-muted">…</span>
                            @endif
                            <button type="button" wire:click="gotoPage({{ $p }})"
                                    @if ($p === $current) aria-current="page" @endif
                                    @class([$pagerBtn, 'border-brand-red bg-brand-red font-semibold text-white' => $p === $current,
                                        'border-brand-line bg-white hover:bg-brand-soft' => $p !== $current])>{{ $p }}</button>
                        @endforeach
                        <button type="button" wire:click="nextPage" @disabled($current === $last)
                                @class([$pagerBtn, 'border-brand-line bg-white hover:bg-brand-soft', 'opacity-40' => $current === $last]) aria-label="Trang sau">›</button>
                    </nav>
                @endif
            </div>
        @endif
    </section>
</div>
