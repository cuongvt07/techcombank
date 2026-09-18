<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold">Tổng quan đào tạo</h2>
            <p class="mt-1 text-sm text-brand-muted">
                Tiến độ toàn công ty, xu hướng hoàn thành và việc cần xử lý.
            </p>
        </div>

        {{-- Chọn kỳ phân tích: ảnh hưởng chỉ số so sánh và biểu đồ xu hướng --}}
        <div class="flex shrink-0 items-center gap-1 rounded-admin border border-brand-line bg-white p-1">
            @foreach ([7 => '7 ngày', 30 => '30 ngày', 90 => '90 ngày'] as $days => $label)
                <button type="button" wire:click="setPeriod({{ $days }})"
                        @class([
                            'whitespace-nowrap rounded-admin-sm px-3 py-1.5 text-xs font-semibold transition-colors',
                            'bg-brand-red text-white' => $period === $days,
                            'text-brand-muted hover:text-brand-ink' => $period !== $days,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Việc cần xử lý đặt trên cùng: ai mở dashboard cũng để trả lời
         "hôm nay tôi phải làm gì" trước khi quan tâm số liệu tổng --}}
    @php
        $hasActions = collect($actions)->sum() > 0;
    @endphp

    @if ($hasActions)
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['key' => 'overdue_enrollments', 'label' => 'Khóa học quá hạn', 'icon' => 'heroicon-o-clock', 'route' => 'admin.employees'],
                ['key' => 'expiring_contracts', 'label' => 'Hợp đồng sắp hết hạn', 'icon' => 'heroicon-o-document-check', 'route' => 'admin.contracts'],
                ['key' => 'open_alerts', 'label' => 'Cảnh báo bảo mật', 'icon' => 'heroicon-o-exclamation-triangle', 'route' => 'admin.security'],
                ['key' => 'open_tickets', 'label' => 'Phiếu hỗ trợ chờ xử lý', 'icon' => 'heroicon-o-lifebuoy', 'route' => null],
            ] as $item)
                @continue($actions[$item['key']] === 0)

                @php
                    $canLink = $item['route'] && Route::has($item['route']) && auth()->user()?->can('reports.view');
                @endphp

                <{{ $canLink ? 'a' : 'div' }}
                    @if ($canLink) href="{{ route($item['route']) }}" @endif
                    class="flex items-center gap-3 rounded-admin border border-brand-red bg-brand-red-tint p-3.5 transition-colors {{ $canLink ? 'hover:bg-brand-red hover:text-white group' : '' }}">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-admin bg-white text-brand-red">
                        @svg($item['icon'], 'h-5 w-5')
                    </span>
                    <div class="min-w-0">
                        <strong class="block text-xl font-bold leading-none text-brand-red {{ $canLink ? 'group-hover:text-white' : '' }}">
                            {{ $actions[$item['key']] }}
                        </strong>
                        <span class="mt-1 block truncate text-xs font-semibold text-brand-red {{ $canLink ? 'group-hover:text-white' : '' }}">
                            {{ $item['label'] }}
                        </span>
                    </div>
                </{{ $canLink ? 'a' : 'div' }}>
            @endforeach
        </div>
    @endif

    {{-- Chỉ số tổng quan kèm so sánh kỳ trước --}}
    <div class="mb-4 grid grid-cols-2 gap-3 xl:grid-cols-4">
        @foreach ([
            ['key' => 'active_employees', 'label' => 'Nhân viên hoạt động', 'suffix' => ''],
            ['key' => 'completed', 'label' => 'Lượt hoàn thành', 'suffix' => ''],
            ['key' => 'new_assignments', 'label' => 'Khóa được giao mới', 'suffix' => ''],
            ['key' => 'unfinished', 'label' => 'Bắt buộc chưa xong', 'suffix' => ''],
        ] as $card)
            @php
                $m = $summary[$card['key']];

                // Số việc còn dở dang tô vàng để nhận ra ngay giữa các chỉ số khác;
                // 0 thì để màu thường vì không còn gì phải xử lý
                $needsAttention = $card['key'] === 'unfinished' && $m['value'] > 0;
            @endphp

            <div class="admin-panel p-4 {{ $needsAttention ? 'border-state-warning' : '' }}">
                <span class="text-[11px] font-semibold uppercase text-brand-muted">{{ $card['label'] }}</span>
                <strong class="mt-1.5 block text-3xl font-bold leading-none {{ $needsAttention ? 'text-state-warning' : '' }}">
                    {{ number_format($m['value']) }}
                </strong>

                @if ($m['delta'] !== null)
                    <div class="mt-2 flex items-center gap-1 text-xs font-semibold
                                {{ $m['trend'] === 'up' ? 'text-state-success' : ($m['trend'] === 'down' ? 'text-brand-red' : 'text-brand-muted') }}">
                        @if ($m['trend'] === 'up')
                            @svg('heroicon-o-arrow-trending-up', 'h-4 w-4')
                        @elseif ($m['trend'] === 'down')
                            @svg('heroicon-o-arrow-trending-down', 'h-4 w-4')
                        @endif
                        {{ abs($m['delta']) }}% so với kỳ trước
                    </div>
                @else
                    <div class="mt-2 text-xs text-brand-muted">Trong {{ $period }} ngày qua</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Xu hướng + phân bố trạng thái --}}
    <div class="mb-4 grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div class="admin-panel min-w-0">
            <div class="admin-panel-head">
                <h3>Lượt hoàn thành theo ngày</h3>
                <span class="text-xs text-brand-muted">{{ min(30, $period) }} ngày gần nhất</span>
            </div>
            <div class="p-4">
                <x-admin.bar-chart :data="$trend" :height="150" label="Lượt hoàn thành khóa học theo ngày" />
            </div>
        </div>

        <div class="admin-panel min-w-0">
            <div class="admin-panel-head">
                <h3>Trạng thái học tập</h3>
            </div>
            <div class="p-4">
                <x-admin.donut-chart :data="$statusBreakdown" label="Phân bố trạng thái các lượt giao khóa học" />
            </div>
        </div>
    </div>

    {{-- Phòng ban + khóa học cần can thiệp --}}
    <div class="mb-4 grid gap-4 xl:grid-cols-2">
        <div class="admin-panel min-w-0">
            <div class="admin-panel-head">
                <h3>Tiến độ theo phòng ban</h3>
            </div>

            <div class="grid gap-3.5 p-4">
                @forelse ($departments as $dept)
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-3 text-[13px]">
                            <span class="min-w-0 truncate font-semibold">{{ $dept->name }}</span>
                            <span class="flex shrink-0 items-center gap-2 text-xs text-brand-muted">
                                <span>{{ $dept->employee_count }} người</span>
                                @if ($dept->overdue_count > 0)
                                    <span class="tag tag-red">{{ $dept->overdue_count }} quá hạn</span>
                                @endif
                                <strong class="w-9 text-right font-bold text-brand-ink">{{ (int) $dept->avg_percent }}%</strong>
                            </span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill {{ $dept->avg_percent >= 80 ? '!bg-state-success' : '' }}"
                                 style="width: {{ (int) $dept->avg_percent }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-brand-muted">Chưa có dữ liệu tiến độ.</p>
                @endforelse
            </div>
        </div>

        <div class="admin-panel min-w-0">
            <div class="admin-panel-head">
                <h3>Khóa học cần can thiệp</h3>
                <span class="text-xs text-brand-muted">Tiến độ thấp nhất</span>
            </div>

            <div class="grid gap-2.5 p-4">
                @forelse ($strugglingCourses as $course)
                    <div class="flex items-center gap-3 rounded-admin border border-brand-line p-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[13px] font-semibold">{{ $course->title }}</p>
                            <p class="mt-0.5 text-xs text-brand-muted">
                                {{ $course->assigned_count }} người được giao
                                @if ($course->overdue_count > 0)
                                    · <span class="font-semibold text-brand-red">{{ $course->overdue_count }} quá hạn</span>
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <div class="progress-track w-16">
                                <div class="progress-fill" style="width: {{ (int) $course->avg_percent }}%"></div>
                            </div>
                            <strong class="w-9 text-right text-sm font-bold">{{ (int) $course->avg_percent }}%</strong>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-brand-muted">
                        Chưa đủ dữ liệu. Cần khóa học được giao cho ít nhất 3 người.
                    </p>
                @endforelse

                {{-- Tỉ lệ đạt bài kiểm tra, gắn ở đây vì cùng chủ đề chất lượng đào tạo --}}
                @if ($quizStats['total'] > 0)
                    <div class="mt-1 flex items-center justify-between gap-3 rounded-admin bg-brand-soft p-3">
                        <span class="text-[13px] font-semibold">Tỉ lệ đạt bài kiểm tra</span>
                        <span class="flex items-center gap-2">
                            <strong class="text-lg font-bold {{ $quizStats['rate'] >= 70 ? 'text-state-success' : 'text-state-warning' }}">
                                {{ $quizStats['rate'] }}%
                            </strong>
                            <span class="text-xs text-brand-muted">
                                {{ $quizStats['passed'] }}/{{ $quizStats['total'] }} lượt
                            </span>
                        </span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Danh sách chi tiết cần theo dõi --}}
    <div class="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div class="admin-panel min-w-0">
            <div class="admin-panel-head">
                <h3>Nhân viên cần nhắc hạn</h3>
                @if (Route::has('admin.employees'))
                    <a href="{{ route('admin.employees') }}"
                       class="whitespace-nowrap text-xs font-semibold text-brand-red hover:underline">
                        Xem toàn bộ
                    </a>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Nhân viên</th>
                            <th>Khóa học</th>
                            <th class="w-32">Tiến độ</th>
                            <th class="w-24">Hạn</th>
                            <th class="w-28">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($trackedEmployees as $enrollment)
                            <tr wire:key="track-{{ $enrollment->id }}">
                                <td>
                                    <div class="flex items-center gap-2.5">
                                        <x-user-avatar :employee="$enrollment->employee" size="h-8 w-8" text="text-[11px]" />
                                        <div class="min-w-0">
                                            @if (Route::has('admin.employees.show'))
                                                <a href="{{ route('admin.employees.show', $enrollment->employee) }}"
                                                   class="block truncate font-bold hover:text-brand-red">
                                                    {{ $enrollment->employee->full_name }}
                                                </a>
                                            @else
                                                <div class="truncate font-bold">{{ $enrollment->employee->full_name }}</div>
                                            @endif
                                            <div class="truncate text-xs text-brand-muted">
                                                {{ $enrollment->employee->department?->name ?? '—' }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="max-w-[200px] truncate">{{ $enrollment->course->title }}</td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="progress-track w-16">
                                            <div class="progress-fill" style="width: {{ (float) $enrollment->progress_percent }}%"></div>
                                        </div>
                                        <span class="text-xs font-semibold">{{ (int) $enrollment->progress_percent }}%</span>
                                    </div>
                                </td>
                                <td class="text-xs text-brand-muted">
                                    {{ $enrollment->due_date?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td>
                                    @php
                                        $statusMap = [
                                            \App\Models\Enrollment::STATUS_OVERDUE => ['tag-red', 'Quá hạn'],
                                            \App\Models\Enrollment::STATUS_IN_PROGRESS => ['tag-amber', 'Đang học'],
                                            \App\Models\Enrollment::STATUS_NOT_STARTED => ['', 'Chưa bắt đầu'],
                                        ];
                                        [$cls, $label] = $statusMap[$enrollment->status] ?? ['', $enrollment->status];
                                    @endphp
                                    <span class="tag {{ $cls }}">{{ $label }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-brand-muted">
                                    Không có khóa học bắt buộc nào đang chờ hoàn thành.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="admin-panel min-w-0">
            <div class="admin-panel-head">
                <h3>Hợp đồng sắp hết hạn</h3>
                @if (Route::has('admin.contracts') && auth()->user()?->can('contracts.view'))
                    <a href="{{ route('admin.contracts') }}"
                       class="whitespace-nowrap text-xs font-semibold text-brand-red hover:underline">
                        Quản lý
                    </a>
                @endif
            </div>

            <div class="grid gap-2.5 p-4">
                @forelse ($expiringContracts as $contract)
                    @php $days = $contract->daysUntilExpiry(); @endphp

                    <div class="flex min-h-[44px] items-center justify-between gap-3 rounded-admin border border-brand-line px-3 text-[13px]">
                        <div class="min-w-0">
                            <span class="block truncate font-semibold">{{ $contract->employee->full_name }}</span>
                            <span class="block truncate font-mono text-[11px] text-brand-muted">{{ $contract->contract_no }}</span>
                        </div>
                        <span class="tag {{ $days !== null && $days <= 7 ? 'tag-red' : 'tag-amber' }}">
                            còn {{ $days }} ngày
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-brand-muted">Không có hợp đồng nào sắp hết hạn.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
