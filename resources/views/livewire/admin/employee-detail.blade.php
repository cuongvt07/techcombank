<div>
    <a href="{{ route('admin.employees') }}"
       class="mb-3 inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold text-brand-muted hover:text-brand-red">
        @svg('heroicon-o-chevron-left', 'h-4 w-4')
        Về danh sách nhân sự
    </a>

    {{-- Header bản ghi: danh tính + trạng thái + hành động, kiểu hồ sơ CRM --}}
    <div class="admin-panel mb-4 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 gap-4">
                <x-user-avatar :employee="$employee" size="h-14 w-14" text="text-xl" />

                <div class="min-w-0">
                    <h2 class="truncate text-xl font-bold">{{ $employee->full_name }}</h2>

                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-brand-muted">
                        <span class="font-mono text-xs">{{ $employee->employee_code }}</span>
                        @if ($employee->department)
                            <span>·</span>
                            <span>{{ $employee->department->name }}</span>
                        @endif
                        @if ($employee->jobTitle)
                            <span>·</span>
                            <span>{{ $employee->jobTitle->name }}</span>
                        @endif
                    </div>

                    <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                        @php
                            $statusMap = [
                                'probation' => ['tag-amber', 'Thử việc'],
                                'official' => ['tag-green', 'Chính thức'],
                                'suspended' => ['', 'Tạm ngưng'],
                                'resigned' => ['tag-red', 'Đã nghỉ việc'],
                            ];
                            [$cls, $label] = $statusMap[$employee->employment_status] ?? ['', $employee->employment_status];
                        @endphp
                        <span class="tag {{ $cls }}">{{ $label }}</span>

                        @if ($employee->jobGrade)
                            <span class="tag">{{ $employee->jobGrade->name }}</span>
                        @endif

                        @if ($employee->is_new_hire)
                            <span class="tag tag-amber">Nhân viên mới</span>
                        @endif

                        @if ($employee->user && $employee->user->status !== 'active')
                            <span class="tag tag-red">Tài khoản bị khóa</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" wire:click="openAssign" class="admin-btn">
                    @svg('heroicon-o-plus', 'h-4 w-4')
                    Giao khóa học
                </button>
            </div>
        </div>
    </div>

    {{-- Chỉ số nhanh của bản ghi --}}
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-6">
        <div class="admin-panel p-3.5">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Khóa được giao</span>
            <strong class="mt-1 block text-2xl font-bold leading-none">{{ $stats['assigned'] }}</strong>
        </div>
        <div class="admin-panel p-3.5">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Đã hoàn thành</span>
            <strong class="mt-1 block text-2xl font-bold leading-none text-state-success">{{ $stats['completed'] }}</strong>
        </div>
        {{-- Chưa xong: vàng cảnh báo. Quá hạn: đỏ, mức nghiêm trọng hơn --}}
        <div class="admin-panel p-3.5 {{ $stats['unfinished'] > 0 ? 'border-state-warning' : '' }}">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Chưa hoàn thành</span>
            <strong class="mt-1 block text-2xl font-bold leading-none {{ $stats['unfinished'] > 0 ? 'text-state-warning' : '' }}">
                {{ $stats['unfinished'] }}
            </strong>
        </div>
        <div class="admin-panel p-3.5 {{ $stats['overdue'] > 0 ? 'border-brand-red' : '' }}">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Quá hạn</span>
            <strong class="mt-1 block text-2xl font-bold leading-none {{ $stats['overdue'] > 0 ? 'text-brand-red' : '' }}">
                {{ $stats['overdue'] }}
            </strong>
        </div>
        <div class="admin-panel p-3.5">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Tiến độ TB</span>
            <strong class="mt-1 block text-2xl font-bold leading-none">{{ (int) $stats['avg_progress'] }}%</strong>
        </div>
        <div class="admin-panel p-3.5">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Chứng nhận</span>
            <strong class="mt-1 block text-2xl font-bold leading-none">{{ $stats['certificates'] }}</strong>
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px] xl:items-start">
        <div class="admin-panel min-w-0">
            <div class="flex flex-wrap gap-1 border-b border-brand-line p-2">
                @foreach ([
                    'overview' => 'Tổng quan',
                    'courses' => 'Khóa học',
                    'lessons' => 'Chi tiết bài học',
                    'quizzes' => 'Kết quả kiểm tra',
                    'contracts' => 'Hợp đồng',
                    'activity' => 'Đăng nhập',
                ] as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')"
                            @class([
                                'whitespace-nowrap rounded-admin px-3 py-2 text-[13px] font-semibold transition-colors',
                                'bg-brand-red text-white' => $tab === $key,
                                'text-brand-ink hover:bg-brand-soft' => $tab !== $key,
                            ])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($tab === 'overview')
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div>
                        <h3 class="mb-3 text-sm font-bold">Thông tin cá nhân</h3>
                        <dl class="grid gap-2.5 text-sm">
                            <div class="flex gap-3">
                                <dt class="w-32 shrink-0 text-brand-muted">Email</dt>
                                <dd class="min-w-0 truncate">{{ $employee->email ?? '—' }}</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="w-32 shrink-0 text-brand-muted">Điện thoại</dt>
                                <dd>{{ $employee->phone ?? '—' }}</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="w-32 shrink-0 text-brand-muted">Ngày sinh</dt>
                                <dd>{{ $employee->date_of_birth?->format('d/m/Y') ?? '—' }}</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="w-32 shrink-0 text-brand-muted">Giới tính</dt>
                                <dd>
                                    @php
                                        $genderMap = ['male' => 'Nam', 'female' => 'Nữ', 'other' => 'Khác'];
                                    @endphp
                                    {{ $genderMap[$employee->gender] ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div>
                        <h3 class="mb-3 text-sm font-bold">Vị trí công tác</h3>
                        <dl class="grid gap-2.5 text-sm">
                            <div class="flex gap-3">
                                <dt class="w-32 shrink-0 text-brand-muted">Phòng ban</dt>
                                <dd class="min-w-0 truncate">{{ $employee->department?->full_path ?? '—' }}</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="w-32 shrink-0 text-brand-muted">Chức danh</dt>
                                <dd>{{ $employee->jobTitle?->name ?? '—' }}</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="w-32 shrink-0 text-brand-muted">Cấp bậc</dt>
                                <dd>{{ $employee->jobGrade?->name ?? '—' }}</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="w-32 shrink-0 text-brand-muted">Quản lý</dt>
                                <dd>{{ $employee->manager?->full_name ?? '—' }}</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="w-32 shrink-0 text-brand-muted">Ngày vào làm</dt>
                                <dd>{{ $employee->joined_at?->format('d/m/Y') ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    @if ($certificates->isNotEmpty())
                        <div class="sm:col-span-2">
                            <h3 class="mb-3 text-sm font-bold">Chứng nhận đã cấp</h3>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($certificates as $certificate)
                                    <div class="flex items-start gap-2.5 rounded-admin border border-brand-line p-3">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-admin bg-state-success-tint text-state-success">
                                            @svg('heroicon-o-trophy', 'h-4 w-4')
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-[13px] font-semibold">{{ $certificate->course?->title }}</p>
                                            <p class="mt-0.5 font-mono text-[11px] text-brand-muted">{{ $certificate->certificate_no }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

            @elseif ($tab === 'courses')
                <div class="overflow-x-auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Khóa học</th>
                                <th class="w-40">Tiến độ</th>
                                <th class="w-28">Hạn</th>
                                <th class="w-28">Trạng thái</th>
                                <th class="w-16 text-right">Gỡ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($enrollments as $enrollment)
                                <tr wire:key="enr-{{ $enrollment->id }}">
                                    <td>
                                        <div class="min-w-0">
                                            <div class="truncate font-bold">{{ $enrollment->course?->title }}</div>
                                            <div class="text-xs text-brand-muted">
                                                {{ $enrollment->is_mandatory ? 'Bắt buộc' : 'Tự chọn' }}
                                                · giao {{ $enrollment->assigned_at?->format('d/m/Y') }}
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <div class="progress-track w-20">
                                                <div class="progress-fill {{ $enrollment->isCompleted() ? '!bg-state-success' : '' }}"
                                                     style="width: {{ (float) $enrollment->progress_percent }}%"></div>
                                            </div>
                                            <span class="text-xs font-semibold">{{ (int) $enrollment->progress_percent }}%</span>
                                        </div>
                                        <div class="mt-1 text-[11px] text-brand-muted">
                                            {{ $enrollment->completed_lessons }}/{{ $enrollment->total_lessons }} bài
                                        </div>
                                    </td>
                                    <td class="text-xs text-brand-muted">
                                        {{ $enrollment->due_date?->format('d/m/Y') ?? '—' }}
                                    </td>
                                    <td>
                                        @php
                                            $map = [
                                                'not_started' => ['', 'Chưa bắt đầu'],
                                                'in_progress' => ['tag-amber', 'Đang học'],
                                                'completed' => ['tag-green', 'Hoàn thành'],
                                                'overdue' => ['tag-red', 'Quá hạn'],
                                                'cancelled' => ['', 'Đã gỡ'],
                                            ];
                                            [$scls, $slabel] = $map[$enrollment->status] ?? ['', $enrollment->status];
                                        @endphp
                                        <span class="tag {{ $scls }}">{{ $slabel }}</span>
                                    </td>
                                    <td>
                                        <div class="flex justify-end">
                                            @if ($enrollment->status !== 'cancelled')
                                                <x-admin.icon-button icon="heroicon-o-x-mark" label="Gỡ khóa học"
                                                                     variant="danger"
                                                                     wire:click="unassign({{ $enrollment->id }})"
                                                                     wire:confirm="Gỡ khóa học này? Lịch sử học tập vẫn được giữ." />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-brand-muted">Chưa được giao khóa học nào.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            @elseif ($tab === 'lessons')
                <div class="overflow-x-auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Bài học</th>
                                <th>Khóa học</th>
                                <th class="w-32">Lần đầu vào</th>
                                <th class="w-28">Thời gian học</th>
                                <th class="w-28">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lessonProgress as $progress)
                                <tr wire:key="lp-{{ $progress->id }}">
                                    <td class="font-bold">{{ $progress->lesson?->title ?? '—' }}</td>
                                    <td class="max-w-[200px] truncate text-brand-muted">
                                        {{ $progress->lesson?->course?->title ?? '—' }}
                                    </td>
                                    <td class="text-xs text-brand-muted">
                                        {{ $progress->first_accessed_at?->format('d/m/Y H:i') ?? '—' }}
                                    </td>
                                    <td class="text-brand-muted">
                                        @if ($progress->time_spent_seconds > 0)
                                            {{ $progress->time_spent_seconds >= 60
                                                ? intdiv($progress->time_spent_seconds, 60) . ' phút'
                                                : $progress->time_spent_seconds . ' giây' }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $map = [
                                                'completed' => ['tag-green', 'Hoàn thành'],
                                                'in_progress' => ['tag-amber', 'Đang học'],
                                                'not_started' => ['', 'Chưa vào'],
                                            ];
                                            [$lcls, $llabel] = $map[$progress->status] ?? ['', $progress->status];
                                        @endphp
                                        <span class="tag {{ $lcls }}">{{ $llabel }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-brand-muted">
                                        Nhân viên chưa vào bài học nào.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            @elseif ($tab === 'quizzes')
                <div class="overflow-x-auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Bài kiểm tra</th>
                                <th class="w-20">Lần</th>
                                <th class="w-24">Điểm</th>
                                <th class="w-28">Kết quả</th>
                                <th class="w-40">Thời điểm nộp</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($quizAttempts as $attempt)
                                <tr wire:key="qa-{{ $attempt->id }}">
                                    <td class="font-bold">{{ $attempt->quiz?->title ?? '—' }}</td>
                                    <td class="text-brand-muted">{{ $attempt->attempt_no }}</td>
                                    <td class="font-semibold">{{ (int) $attempt->percentage }}%</td>
                                    <td>
                                        <span class="tag {{ $attempt->is_passed ? 'tag-green' : 'tag-red' }}">
                                            {{ $attempt->is_passed ? 'Đạt' : 'Chưa đạt' }}
                                        </span>
                                    </td>
                                    <td class="text-xs text-brand-muted">
                                        {{ $attempt->submitted_at?->format('d/m/Y H:i') ?? '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-brand-muted">Chưa có lượt kiểm tra nào.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            @elseif ($tab === 'contracts')
                <div class="overflow-x-auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Số hợp đồng</th>
                                <th>Loại</th>
                                <th class="w-28">Hiệu lực</th>
                                <th class="w-28">Hết hạn</th>
                                <th class="w-28">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($contracts as $contract)
                                <tr wire:key="ct-{{ $contract->id }}">
                                    <td class="font-mono text-xs font-semibold">{{ $contract->contract_no }}</td>
                                    <td class="text-brand-muted">{{ $contract->contractType?->name }}</td>
                                    <td class="text-xs">{{ $contract->effective_from?->format('d/m/Y') }}</td>
                                    <td class="text-xs">{{ $contract->effective_to?->format('d/m/Y') ?? 'Không thời hạn' }}</td>
                                    <td>
                                        @php
                                            $map = [
                                                'draft' => ['', 'Nháp'],
                                                'active' => ['tag-green', 'Hiệu lực'],
                                                'expiring' => ['tag-amber', 'Sắp hết hạn'],
                                                'expired' => ['tag-red', 'Hết hạn'],
                                                'terminated' => ['', 'Chấm dứt'],
                                            ];
                                            [$ccls, $clabel] = $map[$contract->status] ?? ['', $contract->status];
                                        @endphp
                                        <span class="tag {{ $ccls }}">{{ $clabel }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-brand-muted">Chưa có hợp đồng nào.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            @else
                <div class="overflow-x-auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th class="w-40">Thời điểm</th>
                                <th class="w-36">IP</th>
                                <th>Thiết bị</th>
                                <th class="w-28">Kết quả</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentLogins as $login)
                                <tr wire:key="lg-{{ $login->id }}">
                                    <td class="text-xs">{{ $login->logged_in_at?->format('d/m/Y H:i:s') }}</td>
                                    <td class="font-mono text-xs text-brand-muted">{{ $login->ip_address ?? '—' }}</td>
                                    <td class="text-xs text-brand-muted">{{ $login->device_label ?? '—' }}</td>
                                    <td>
                                        @php
                                            $map = [
                                                'success' => ['tag-green', 'Thành công'],
                                                'failed' => ['tag-amber', 'Thất bại'],
                                                'blocked' => ['tag-red', 'Bị chặn'],
                                            ];
                                            [$lcls, $llabel] = $map[$login->result] ?? ['', $login->result];
                                        @endphp
                                        <span class="tag {{ $lcls }}">{{ $llabel }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-brand-muted">Chưa có lượt đăng nhập nào.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Dòng thời gian hoạt động — đặc trưng hồ sơ CRM --}}
        <div class="admin-panel">
            <div class="admin-panel-head">
                <h3>Dòng thời gian</h3>
            </div>

            <div class="max-h-[600px] overflow-y-auto p-4">
                @forelse ($timeline as $event)
                    @php
                        $toneClasses = match ($event['tone']) {
                            'success' => 'bg-state-success-tint text-state-success',
                            'danger' => 'bg-brand-red-tint text-brand-red',
                            'warning' => 'bg-state-warning-tint text-state-warning',
                            'muted' => 'bg-brand-soft text-brand-muted',
                            default => 'bg-brand-soft text-brand-ink',
                        };
                    @endphp

                    {{-- Đường kẻ dọc nối các mốc, trừ mốc cuối --}}
                    <div class="relative flex gap-3 pb-4 {{ $loop->last ? '' : 'before:absolute before:left-[15px] before:top-8 before:h-full before:w-px before:bg-brand-line' }}">
                        <span class="relative z-10 grid h-8 w-8 shrink-0 place-items-center rounded-full {{ $toneClasses }}">
                            @svg($event['icon'], 'h-4 w-4')
                        </span>

                        <div class="min-w-0 flex-1 pt-1">
                            <p class="text-[13px] font-semibold leading-snug">{{ $event['title'] }}</p>
                            @if ($event['detail'])
                                <p class="mt-0.5 text-xs leading-snug text-brand-muted">{{ $event['detail'] }}</p>
                            @endif
                            <p class="mt-1 text-[11px] text-brand-muted">
                                {{ $event['at']->format('d/m/Y H:i') }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-brand-muted">Chưa có hoạt động nào được ghi nhận.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Modal giao khóa học --}}
    @if ($showAssignModal)
        <x-admin.modal wireModel="showAssignModal" title="Giao khóa học cho nhân viên">
            <form wire:submit="assignCourse" id="assign-form" class="grid gap-4">
                <div>
                    <label for="ac_course" class="admin-label">Khóa học</label>
                    <select id="ac_course" wire:model="assignCourseId" class="admin-input">
                        <option value="">— Chọn khóa học —</option>
                        @foreach ($assignableCourses as $course)
                            <option value="{{ $course->id }}">{{ $course->title }}</option>
                        @endforeach
                    </select>
                    @error('assignCourseId') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror

                    @if ($assignableCourses->isEmpty())
                        <p class="mt-1 text-xs text-brand-muted">
                            Nhân viên đã được giao tất cả khóa học đang xuất bản.
                        </p>
                    @endif
                </div>

                <div>
                    <label for="ac_due" class="admin-label">Hạn hoàn thành (ngày)</label>
                    <input id="ac_due" type="number" wire:model="assignDueDays" class="admin-input"
                           placeholder="Để trống = không đặt hạn">
                    @error('assignDueDays') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium">
                    <input type="checkbox" wire:model="assignMandatory"
                           class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                    Khóa học bắt buộc
                </label>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showAssignModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="assign-form" class="admin-btn" @disabled($assignableCourses->isEmpty())>
                    Giao khóa học
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
