<div>
    @php
        $departmentOptions = $departments->map(fn ($item) => ['value' => $item->id, 'label' => $item->full_path])->all();
        $titleOptions = $jobTitles->map(fn ($item) => ['value' => $item->id, 'label' => $item->name])->all();
        $managerOptions = $managers->reject(fn ($item) => $item->id === $editingId)
            ->map(fn ($item) => ['value' => $item->id, 'label' => $item->full_name . ' · ' . $item->employee_code])->all();
    @endphp

    <x-admin.page-header title="Hồ sơ nhân sự" :subtitle="$summary">
        <x-slot:actions>
            @can('employees.create')
            <button type="button" wire:click="create" class="admin-btn">+ Thêm nhân sự</button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Thanh thao tác hàng loạt, chỉ hiện khi đã chọn dòng --}}
    @if (count($selected) > 0)
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-admin border border-brand-red bg-brand-red-tint px-4 py-3">
            <p class="text-sm font-semibold text-brand-red">
                Đã chọn {{ count($selected) }} nhân viên
            </p>

            <div class="flex flex-wrap items-center gap-2">
                @can('employees.update')
                <button type="button" wire:click="openBulkAssign" class="admin-btn">
                    @svg('heroicon-o-academic-cap', 'h-4 w-4')
                    Giao khóa học
                </button>
                @endcan
                @can('employees.update')
                <button type="button" wire:click="bulkMakeOfficial"
                        data-confirm="Chuyển các nhân viên thử việc đang chọn sang chính thức?"
                        class="admin-btn-secondary">
                    Chuyển chính thức
                </button>
                @endcan
                <button type="button" wire:click="clearSelection" class="admin-btn-secondary">
                    Bỏ chọn
                </button>
            </div>
        </div>
    @endif

    <x-admin.list-toolbar placeholder="Tìm theo tên hoặc mã nhân viên...">
        <x-admin.list-filter label="Phòng ban" model="departmentFilter">
            <option value="">Tất cả</option>
            @foreach ($departments as $dept)
                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
            @endforeach
        </x-admin.list-filter>

        <x-admin.list-filter label="Trạng thái" model="statusFilter">
            <option value="">Tất cả</option>
            <option value="probation">Thử việc</option>
            <option value="official">Chính thức</option>
            <option value="suspended">Tạm ngưng</option>
            <option value="resigned">Đã nghỉ việc</option>
        </x-admin.list-filter>

        <x-admin.list-filter label="Chức danh" model="jobTitleFilter">
            <option value="">Tất cả</option>
            @foreach ($jobTitles as $title)
                <option value="{{ $title->id }}">{{ $title->name }}</option>
            @endforeach
        </x-admin.list-filter>

        <x-admin.list-filter label="Cấp bậc" model="jobGradeFilter">
            <option value="">Tất cả</option>
            @foreach ($jobGrades as $grade)
                <option value="{{ $grade->id }}">{{ $grade->name }}</option>
            @endforeach
        </x-admin.list-filter>

        <x-admin.list-filter label="Năm vào làm" model="joinedYearFilter">
            <option value="">Tất cả</option>
            @foreach ($joinedYears as $year)
                <option value="{{ $year }}">{{ $year }}</option>
            @endforeach
        </x-admin.list-filter>
    </x-admin.list-toolbar>

    <div class="admin-panel">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="w-10">
                            <input type="checkbox" wire:model.live="selectPage"
                                   class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red"
                                   aria-label="Chọn tất cả dòng trên trang">
                        </th>
                        <th>Nhân viên</th>
                        <th class="w-28">Mã NV</th>
                        <th>Phòng ban</th>
                        <th>Chức danh</th>
                        <th class="w-28">Cấp bậc</th>
                        <th class="w-28">Trạng thái</th>
                        <th class="w-32 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($employees as $employee)
                        <tr wire:key="emp-{{ $employee->id }}"
                            class="{{ in_array((string) $employee->id, $selected, true) ? '!bg-brand-red-tint' : '' }}">
                            <td>
                                <input type="checkbox" wire:model.live="selected" value="{{ $employee->id }}"
                                       class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red"
                                       aria-label="Chọn {{ $employee->full_name }}">
                            </td>
                            <td>
                                <div class="flex items-center gap-2.5">
                                    <x-user-avatar :employee="$employee" size="h-8 w-8" text="text-[11px]" />
                                    <div class="min-w-0">
                                        <a href="{{ route('admin.employees.show', $employee) }}"
                                           class="block truncate font-bold hover:text-brand-red">{{ $employee->full_name }}</a>
                                    </div>
                                </div>
                            </td>
                            <td class="font-mono text-xs">{{ $employee->employee_code }}</td>
                            <td class="text-brand-muted">{{ $employee->department?->name ?? '—' }}</td>
                            <td class="text-brand-muted">{{ $employee->jobTitle?->name ?? '—' }}</td>
                            <td>
                                @if ($employee->jobGrades->isNotEmpty())
                                    @foreach ($employee->jobGrades as $grade)
                                        <span class="tag">{{ $grade->name }}</span>
                                    @endforeach
                                @elseif ($employee->jobGrade)
                                    <span class="tag">{{ $employee->jobGrade->name }}</span>
                                @else
                                    <span class="text-brand-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $map = [
                                        'probation' => ['tag-amber', 'Thử việc'],
                                        'official' => ['tag-green', 'Chính thức'],
                                        'suspended' => ['', 'Tạm ngưng'],
                                        'resigned' => ['tag-red', 'Đã nghỉ'],
                                    ];
                                    [$cls, $label] = $map[$employee->employment_status] ?? ['', $employee->employment_status];
                                @endphp
                                <span class="tag {{ $cls }}">{{ $label }}</span>
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-admin.icon-button icon="heroicon-o-clock" label="Lịch sử điều chuyển"
                                                         wire:click="viewHistory({{ $employee->id }})" />
                                    @can('employees.update')
                                    <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa hồ sơ"
                                                         wire:click="edit({{ $employee->id }})" />
                                    @endcan
                                    @if ($employee->isActive())
                                        @can('employees.update')
                                        <x-admin.icon-button icon="heroicon-o-user-minus" label="Ghi nhận nghỉ việc"
                                                             variant="danger"
                                                             wire:click="resign({{ $employee->id }})"
                                                             data-confirm="Ghi nhận nghỉ việc và khóa tài khoản của {{ $employee->full_name }}?" />
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-brand-muted">Không tìm thấy nhân sự nào.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-admin.list-pagination :paginator="$employees" />
    </div>

    @if ($showModal)
        <x-admin.modal :title="$editingId ? 'Sửa hồ sơ nhân sự' : 'Thêm hồ sơ nhân sự'" maxWidth="max-w-3xl">
            <form wire:submit="save" id="employee-form" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="e_full_name" class="admin-label">Họ và tên <span class="text-brand-red" aria-hidden="true">*</span></label>
                    <input id="e_full_name" type="text" wire:model="full_name" class="admin-input">
                    @error('full_name') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <x-admin.auto-code id="e_code" label="Mã nhân viên" :value="$employee_code" />

                <div>
                    <label for="e_phone" class="admin-label">Điện thoại</label>
                    <input id="e_phone" type="text" wire:model="phone" class="admin-input">
                    @error('phone') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="e_dob" class="admin-label">Ngày sinh</label>
                    <input id="e_dob" type="date" wire:model="date_of_birth" class="admin-input">
                    @error('date_of_birth') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="e_gender" class="admin-label">Giới tính</label>
                    <select id="e_gender" wire:model="gender" class="admin-input">
                        <option value="">— Chọn —</option>
                        <option value="male">Nam</option>
                        <option value="female">Nữ</option>
                        <option value="other">Khác</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <div class="border-t border-brand-line pt-4">
                        <p class="admin-label !mb-3">Vị trí trong tổ chức</p>
                        <p class="-mt-2 mb-3 text-xs text-brand-muted">
                            Thay đổi các trường dưới đây sẽ ghi vào lịch sử điều chuyển và cập nhật lại
                            quyền truy cập tài liệu, khóa học được giao.
                        </p>
                    </div>
                </div>

                <div>
                    <x-admin.searchable-select id="e_dept" label="Phòng ban" model="department_id"
                                                :options="$departmentOptions" :selected="$department_id"
                                                emptyLabel="— Chọn —" />
                    @error('department_id') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-admin.searchable-select id="e_title" label="Chức danh" model="job_title_id"
                                                :options="$titleOptions" :selected="$job_title_id"
                                                emptyLabel="— Chọn —" />
                </div>

                <div>
                    {{-- Hồ sơ nhân sự chỉ một cấp bậc; chọn nhiều chỉ có ở phân quyền tài liệu --}}
                    <x-admin.searchable-select id="e_grade" label="Cấp bậc" model="job_grade_id"
                                                :options="$jobGrades->map(fn ($g) => ['value' => $g->id, 'label' => $g->name])->all()"
                                                :selected="$job_grade_id" emptyLabel="— Chọn —" />
                    @error('job_grade_id') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-admin.searchable-select id="e_manager" label="Quản lý trực tiếp" model="manager_id"
                                                :options="$managerOptions" :selected="$manager_id"
                                                emptyLabel="— Không có —" />
                    @error('manager_id') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="e_status" class="admin-label">Trạng thái làm việc <span class="text-brand-red" aria-hidden="true">*</span></label>
                    <select id="e_status" wire:model="employment_status" class="admin-input">
                        <option value="probation">Thử việc</option>
                        <option value="official">Chính thức</option>
                        <option value="suspended">Tạm ngưng</option>
                        <option value="resigned">Đã nghỉ việc</option>
                    </select>
                </div>

                <div>
                    <label for="e_joined" class="admin-label">Ngày vào làm</label>
                    <input id="e_joined" type="date" wire:model="joined_at" class="admin-input">
                </div>

                @if ($editingId)
                    <div class="sm:col-span-2">
                        <label for="e_reason" class="admin-label">Lý do thay đổi (ghi vào lịch sử)</label>
                        <input id="e_reason" type="text" wire:model="change_reason" class="admin-input"
                               placeholder="VD: Điều chuyển nội bộ, thăng chức...">
                    </div>
                @endif
                <p class="text-xs text-brand-muted sm:col-span-2"><span class="text-brand-red">*</span> Trường bắt buộc</p>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="employee-form" class="admin-btn" wire:loading.attr="disabled">
                    {{ $editingId ? 'Lưu thay đổi' : 'Tạo hồ sơ' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Modal giao khóa học hàng loạt --}}
    @if ($showBulkAssign)
        <x-admin.modal wireModel="showBulkAssign" title="Giao khóa học cho nhiều nhân viên">
            <form wire:submit="bulkAssignCourse" id="bulk-assign-form" class="grid gap-4">
                <p class="rounded-admin border border-brand-line bg-brand-soft p-3 text-xs text-brand-muted">
                    Khóa học sẽ được giao cho <strong class="text-brand-ink">{{ count($selected) }} nhân viên</strong> đang chọn.
                    Nhân viên đã nghỉ việc sẽ bị bỏ qua.
                </p>

                <div>
                    <x-admin.searchable-select id="bulk_course" label="Khóa học" model="bulkCourseId"
                        :options="$publishedCourses->map(fn ($item) => ['value' => $item->id, 'label' => $item->title])->all()"
                        :selected="$bulkCourseId" emptyLabel="— Chọn khóa học —" />
                    @error('bulkCourseId') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="bulk_due" class="admin-label">Hạn hoàn thành (ngày)</label>
                    <input id="bulk_due" type="number" wire:model="bulkDueDays" class="admin-input"
                           placeholder="Để trống = không đặt hạn">
                    @error('bulkDueDays') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showBulkAssign', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="bulk-assign-form" class="admin-btn">Giao khóa học</button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Lịch sử điều chuyển: audit trail theo spec 3.1.2 --}}
    @if ($showHistory)
        <x-admin.modal wireModel="showHistory" title="Lịch sử thay đổi vị trí" maxWidth="max-w-3xl">
            @if ($histories->isEmpty())
                <p class="text-sm text-brand-muted">Chưa có bản ghi lịch sử nào.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Hiệu lực từ</th>
                                <th>Đến</th>
                                <th>Phòng ban</th>
                                <th>Chức danh</th>
                                <th>Trạng thái</th>
                                <th>Lý do</th>
                                <th>Người thực hiện</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($histories as $history)
                                <tr wire:key="hist-{{ $history->id }}">
                                    <td>{{ $history->effective_from?->format('d/m/Y') }}</td>
                                    <td>
                                        @if ($history->effective_to)
                                            {{ $history->effective_to->format('d/m/Y') }}
                                        @else
                                            <span class="tag tag-green">Đang hiệu lực</span>
                                        @endif
                                    </td>
                                    <td class="text-brand-muted">{{ $history->department?->name ?? '—' }}</td>
                                    <td class="text-brand-muted">{{ $history->jobTitle?->name ?? '—' }}</td>
                                    <td class="text-brand-muted">{{ \App\Models\Employee::statusLabel($history->employment_status) }}</td>
                                    <td class="text-brand-muted">{{ $history->change_reason ?? '—' }}</td>
                                    <td class="text-brand-muted">{{ $history->changedBy?->name ?? 'Hệ thống' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <x-slot:footer>
                <button type="button" wire:click="$set('showHistory', false)" class="admin-btn-secondary">Đóng</button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
