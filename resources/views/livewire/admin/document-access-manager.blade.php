<div>
    <x-admin.page-header title="Phân quyền tài liệu"
                         subtitle="Quyền truy cập tính động theo phòng ban, chức danh, cấp bậc — tự cập nhật khi nhân sự thay đổi.">
        <x-slot:actions>
            <button type="button" wire:click="openTester" class="admin-btn-secondary">
                @svg('heroicon-o-beaker', 'h-4 w-4')
                Thử quyền
            </button>
            <button type="button" wire:click="create" class="admin-btn">
                @svg('heroicon-o-plus', 'h-4 w-4')
                Thêm quy tắc
            </button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 rounded-admin border border-brand-line bg-white px-4 py-3 text-sm text-brand-muted">
        <strong class="text-brand-ink">Thứ tự xét:</strong>
        quy tắc gắn trực tiếp tài liệu đè quy tắc của danh mục · độ ưu tiên cao xét trước ·
        cùng mức thì <strong class="text-brand-ink">chặn thắng cho phép</strong>.
        Không quy tắc nào khớp thì mặc định <strong class="text-brand-ink">không có quyền</strong>.
    </div>

    <div class="admin-panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
            <div class="min-w-[240px] flex-1">
                <input type="search" wire:model.live.debounce.400ms="search" class="admin-input"
                       placeholder="Tìm theo tên tài liệu hoặc danh mục...">
            </div>
            <select wire:model.live="scopeFilter" class="admin-input w-auto min-w-[190px]">
                <option value="">Mọi quy tắc</option>
                <option value="document">Áp cho tài liệu</option>
                <option value="category">Áp cho danh mục</option>
                <option value="deny">Chỉ quy tắc chặn</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Áp dụng cho</th>
                        <th>Điều kiện</th>
                        <th class="w-40">Quyền</th>
                        <th class="w-24">Ưu tiên</th>
                        <th class="w-28">Hiệu lực</th>
                        <th class="w-32 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rules as $rule)
                        <tr wire:key="rule-{{ $rule->id }}">
                            <td>
                                <div class="min-w-0">
                                    <span class="tag {{ $rule->document_id ? 'tag-red' : '' }}">
                                        {{ $rule->document_id ? 'Tài liệu' : 'Danh mục' }}
                                    </span>
                                    <div class="mt-1 truncate font-bold">
                                        {{ $rule->document?->title ?? $rule->category?->name ?? '—' }}
                                    </div>
                                </div>
                            </td>

                            <td>
                                @php
                                    $conds = [];
                                    if ($rule->department) {
                                        $conds[] = 'Phòng: ' . $rule->department->name
                                            . ($rule->include_sub_departments ? ' (+phòng con)' : '');
                                    }
                                    if ($rule->jobTitle) $conds[] = 'Chức danh: ' . $rule->jobTitle->name;
                                    if ($rule->jobGrade) $conds[] = 'Cấp bậc: ' . $rule->jobGrade->name;
                                    if ($rule->min_grade_level !== null) $conds[] = 'Từ cấp ' . $rule->min_grade_level . ' trở lên';
                                    if ($rule->employment_status) $conds[] = 'Trạng thái: ' . $rule->employment_status;
                                @endphp

                                @if ($conds)
                                    <ul class="grid gap-0.5 text-xs text-brand-muted">
                                        @foreach ($conds as $cond)
                                            <li>{{ $cond }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <span class="tag tag-amber">Mọi nhân viên</span>
                                @endif
                            </td>

                            <td>
                                @if ($rule->isDeny())
                                    <span class="tag tag-red">Chặn truy cập</span>
                                @else
                                    <div class="flex flex-wrap gap-1">
                                        @if ($rule->can_view) <span class="tag tag-green">Xem</span> @endif
                                        @if ($rule->can_download) <span class="tag tag-green">Tải</span> @endif
                                        @if ($rule->can_print) <span class="tag tag-green">In</span> @endif
                                    </div>
                                @endif
                            </td>

                            <td class="font-bold">{{ $rule->priority }}</td>

                            <td>
                                <span class="tag {{ $rule->is_active ? 'tag-green' : '' }}">
                                    {{ $rule->is_active ? 'Đang bật' : 'Đã tắt' }}
                                </span>
                            </td>

                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa quy tắc"
                                                         wire:click="edit({{ $rule->id }})" />
                                    <x-admin.icon-button
                                        :icon="$rule->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle'"
                                        :label="$rule->is_active ? 'Tạm tắt quy tắc' : 'Bật lại quy tắc'"
                                        wire:click="toggleActive({{ $rule->id }})" />
                                    <x-admin.icon-button icon="heroicon-o-trash" label="Xóa quy tắc"
                                                         variant="danger"
                                                         wire:click="delete({{ $rule->id }})"
                                                         wire:confirm="Xóa quy tắc này?" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-brand-muted">
                                Chưa có quy tắc nào — hiện không nhân viên nào xem được tài liệu.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rules->hasPages())
            <div class="border-t border-brand-line p-4">{{ $rules->links() }}</div>
        @endif
    </div>

    {{-- Modal quy tắc --}}
    @if ($showModal)
        <x-admin.modal :title="$editingId ? 'Sửa quy tắc phân quyền' : 'Thêm quy tắc phân quyền'" maxWidth="max-w-3xl">
            <form wire:submit="save" id="access-form" class="grid gap-4">
                <div>
                    <label for="a_scope" class="admin-label">Phạm vi áp dụng</label>
                    <select id="a_scope" wire:model.live="scope" class="admin-input">
                        <option value="document">Một tài liệu cụ thể</option>
                        <option value="category">Cả một danh mục tài liệu</option>
                    </select>
                </div>

                @if ($scope === 'document')
                    <div>
                        <label for="a_doc" class="admin-label">Tài liệu</label>
                        <select id="a_doc" wire:model="documentId" class="admin-input">
                            <option value="">— Chọn —</option>
                            @foreach ($documents as $doc)
                                <option value="{{ $doc->id }}">{{ $doc->title }}</option>
                            @endforeach
                        </select>
                        @error('documentId') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="a_cat" class="admin-label">Danh mục</label>
                        <select id="a_cat" wire:model="categoryId" class="admin-input">
                            <option value="">— Chọn —</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                        @error('categoryId') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-brand-muted">
                            Áp cho mọi tài liệu trong danh mục này và các danh mục con.
                        </p>
                    </div>
                @endif

                <div class="border-t border-brand-line pt-4">
                    <p class="admin-label">Điều kiện áp dụng</p>
                    <p class="-mt-1 mb-3 text-xs text-brand-muted">
                        Để trống nghĩa là không ràng buộc chiều đó. Nhân viên phải khớp
                        <strong class="text-brand-ink">tất cả</strong> điều kiện đã chọn.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="a_dept" class="admin-label">Phòng ban</label>
                            <select id="a_dept" wire:model="departmentId" class="admin-input">
                                <option value="">— Không ràng buộc —</option>
                                @foreach ($departments as $dept)
                                    <option value="{{ $dept->id }}">{{ $dept->full_path }}</option>
                                @endforeach
                            </select>
                        </div>

                        <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium sm:col-span-2">
                            <input type="checkbox" wire:model="includeSubDepartments"
                                   class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                            Áp dụng cho cả phòng ban con
                        </label>

                        <div>
                            <label for="a_title" class="admin-label">Chức danh</label>
                            <select id="a_title" wire:model="jobTitleId" class="admin-input">
                                <option value="">— Không ràng buộc —</option>
                                @foreach ($jobTitles as $jt)
                                    <option value="{{ $jt->id }}">{{ $jt->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="a_grade" class="admin-label">Cấp bậc cụ thể</label>
                            <select id="a_grade" wire:model="jobGradeId" class="admin-input">
                                <option value="">— Không ràng buộc —</option>
                                @foreach ($jobGrades as $jg)
                                    <option value="{{ $jg->id }}">{{ $jg->name }} (cấp {{ $jg->level }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="a_minlevel" class="admin-label">Hoặc: từ cấp bậc N trở lên</label>
                            <input id="a_minlevel" type="number" wire:model="minGradeLevel" class="admin-input"
                                   placeholder="VD: 5">
                            @error('minGradeLevel') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="a_status" class="admin-label">Trạng thái làm việc</label>
                            <select id="a_status" wire:model="employmentStatus" class="admin-input">
                                <option value="">— Không ràng buộc —</option>
                                <option value="probation">Thử việc</option>
                                <option value="official">Chính thức</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="border-t border-brand-line pt-4">
                    <p class="admin-label">Hiệu lực quy tắc</p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="a_effect" class="admin-label">Loại quy tắc</label>
                            <select id="a_effect" wire:model.live="effect" class="admin-input">
                                <option value="allow">Cho phép</option>
                                <option value="deny">Chặn truy cập</option>
                            </select>
                        </div>

                        <div>
                            <label for="a_prio" class="admin-label">Độ ưu tiên</label>
                            <input id="a_prio" type="number" wire:model="priority" class="admin-input">
                            <p class="mt-1 text-xs text-brand-muted">Số lớn hơn được xét trước.</p>
                            @error('priority') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($effect === 'allow')
                        <div class="mt-4 grid gap-2.5">
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium">
                                <input type="checkbox" wire:model="canView"
                                       class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                                Được xem trực tuyến
                            </label>

                            <label class="flex cursor-pointer items-start gap-2.5 text-sm font-medium">
                                <input type="checkbox" wire:model="canDownload"
                                       class="mt-0.5 h-4 w-4 shrink-0 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                                <span>
                                    Được tải bản gốc
                                    <span class="mt-0.5 block text-xs font-normal text-brand-muted">
                                        Vẫn bị chặn nếu tài liệu tắt cờ "cho phép tải" — cờ của tài liệu là trần cứng.
                                    </span>
                                </span>
                            </label>

                            <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium">
                                <input type="checkbox" wire:model="canPrint"
                                       class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                                Được in
                            </label>
                        </div>
                    @else
                        <p class="mt-3 rounded-admin border border-brand-red bg-brand-red-tint p-3 text-xs font-semibold text-brand-red">
                            Quy tắc chặn sẽ thắng quy tắc cho phép ở cùng mức ưu tiên. Dùng cho ngoại lệ hẹp.
                        </p>
                    @endif
                </div>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="access-form" class="admin-btn">
                    {{ $editingId ? 'Lưu quy tắc' : 'Tạo quy tắc' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Công cụ thử quyền: rule chồng nhau khó suy luận bằng mắt --}}
    @if ($showTester)
        <x-admin.modal wireModel="showTester" title="Thử quyền truy cập">
            <div class="grid gap-4">
                <p class="rounded-admin border border-brand-line bg-brand-soft p-3 text-xs text-brand-muted">
                    Chọn một nhân viên và một tài liệu để xem hệ thống quyết định thế nào.
                    Kết quả chạy đúng bộ quy tắc mà site người dùng đang dùng.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="t_emp" class="admin-label">Nhân viên</label>
                        <select id="t_emp" wire:model="testEmployeeId" class="admin-input">
                            <option value="">— Chọn —</option>
                            @foreach ($employees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->full_name }} ({{ $emp->employee_code }})</option>
                            @endforeach
                        </select>
                        @error('testEmployeeId') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="t_doc" class="admin-label">Tài liệu</label>
                        <select id="t_doc" wire:model="testDocumentId" class="admin-input">
                            <option value="">— Chọn —</option>
                            @foreach ($documents as $doc)
                                <option value="{{ $doc->id }}">{{ $doc->title }}</option>
                            @endforeach
                        </select>
                        @error('testDocumentId') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>
                </div>

                <button type="button" wire:click="runTest" class="admin-btn justify-self-start">
                    Kiểm tra
                </button>

                @if ($testResult)
                    <div @class([
                        'rounded-admin border p-4',
                        'border-state-success bg-state-success-tint' => $testResult['can_view'],
                        'border-brand-red bg-brand-red-tint' => ! $testResult['can_view'],
                    ])>
                        <p @class([
                            'text-sm font-bold',
                            'text-state-success' => $testResult['can_view'],
                            'text-brand-red' => ! $testResult['can_view'],
                        ])>
                            {{ $testResult['can_view'] ? 'Được phép xem tài liệu này' : 'Không được xem tài liệu này' }}
                        </p>

                        @if (! $testResult['can_view'] && $testResult['reason'])
                            <p class="mt-1 text-xs font-semibold text-brand-red">Lý do: {{ $testResult['reason'] }}</p>
                        @endif

                        <dl class="mt-3 grid gap-1.5 border-t border-white/40 pt-3 text-xs">
                            <div class="flex gap-2">
                                <dt class="w-28 shrink-0 font-bold">Nhân viên:</dt>
                                <dd>{{ $testResult['employee'] }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-28 shrink-0 font-bold">Phòng ban:</dt>
                                <dd>{{ $testResult['department'] }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-28 shrink-0 font-bold">Cấp bậc:</dt>
                                <dd>{{ $testResult['grade'] }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-28 shrink-0 font-bold">Tài liệu:</dt>
                                <dd>{{ $testResult['document'] }}</dd>
                            </div>
                        </dl>

                        @if ($testResult['can_view'])
                            <div class="mt-3 flex flex-wrap gap-1.5 border-t border-white/40 pt-3">
                                <span class="tag tag-green">Xem</span>
                                @if ($testResult['can_download'])
                                    <span class="tag tag-green">Tải xuống</span>
                                @else
                                    <span class="tag">Không tải được</span>
                                @endif
                                @if ($testResult['can_print'])
                                    <span class="tag tag-green">In</span>
                                @endif
                            </div>

                            @if ($testResult['download_capped'])
                                <p class="mt-2 text-xs font-semibold text-state-warning">
                                    Quy tắc cho phép tải nhưng tài liệu đang tắt cờ "cho phép tải" nên vẫn bị chặn.
                                </p>
                            @endif
                        @endif
                    </div>
                @endif
            </div>

            <x-slot:footer>
                <button type="button" wire:click="$set('showTester', false)" class="admin-btn-secondary">Đóng</button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
