<div>
    @include('livewire.admin.partials.document-tabs', ['active' => 'permissions', 'documentCount' => $documentCount])

    <x-admin.page-header title="Phân quyền tài liệu"
                         subtitle="Mỗi tài liệu có một bộ phân quyền; trong bộ có thể tạo nhiều quy tắc theo đơn vị, chức danh và cấp bậc.">
    </x-admin.page-header>

    <div class="admin-panel">
        <div class="flex flex-wrap items-end gap-3 border-b border-brand-line p-4">
            <div class="min-w-[240px] flex-1">
                <label for="f-document-access-manager-search-0" class="admin-label">Tìm tài liệu</label>
                <input id="f-document-access-manager-search-0" type="search" wire:model="search"
                       wire:keydown.enter="applySearch" class="admin-input"
                       placeholder="Nhập tên tài liệu...">
            </div>
            <div class="ml-auto flex items-end gap-2">
                <button type="button" wire:click="applySearch" class="admin-btn">
                    @svg('heroicon-o-magnifying-glass', 'h-4 w-4')
                    Tìm kiếm
                </button>
                <button type="button" wire:click="resetFilters" class="admin-btn-secondary">Đặt lại</button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="admin-table min-w-[860px]">
                <thead>
                    <tr>
                        <th>Tên tài liệu</th>
                        <th class="w-40">Phạm vi</th>
                        <th>Quyền hiện tại</th>
                        <th class="w-52 min-w-[205px] text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($documents as $document)
                        @php
                            $accessRules = $document->accessRules;
                            $activeRules = $accessRules->where('is_active', true);
                            $primaryRule = $activeRules->first() ?? $accessRules->first();
                            $unitLabels = $accessRules->map(fn ($rule) => $rule->departmentLabel())->unique()->values();
                            $displayRules = $activeRules->isNotEmpty() ? $activeRules : $accessRules;
                            $canView = $displayRules->contains(fn ($rule) => $rule->can_view && ! $rule->isDeny());
                            $canDownload = $displayRules->contains(fn ($rule) => $rule->can_download && ! $rule->isDeny());
                            $canPrint = $displayRules->contains(fn ($rule) => $rule->can_print && ! $rule->isDeny());
                            $hasDeny = $displayRules->contains(fn ($rule) => $rule->isDeny());
                        @endphp
                        <tr wire:key="access-document-{{ $document->id }}">
                            <td class="min-w-[280px]">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-admin bg-brand-soft text-brand-red">
                                        @svg($document->isVideo() ? 'heroicon-o-play-circle' : 'heroicon-o-document', 'h-4 w-4')
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-bold text-brand-ink">{{ $document->title }}</p>
                                        <p class="mt-0.5 text-[11px] text-brand-muted">
                                            {{ strtoupper($document->currentVersion?->storedFile?->extension ?? ($document->isVideo() ? 'VIDEO' : 'FILE')) }}
                                            @if ($document->currentVersion?->storedFile)
                                                · {{ $document->currentVersion->storedFile->humanSize() }}
                                            @endif
                                            @if ($document->published_at)
                                                · {{ $document->published_at->format('d/m/Y') }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="tag">{{ $document->ownerDepartment?->name ?? 'Toàn trường' }}</span>
                            </td>
                            <td>
                                @if ($primaryRule)
                                    <p class="text-xs font-bold text-brand-red">1 bộ phân quyền · {{ $accessRules->count() }} {{ $accessRules->count() === 1 ? 'quy tắc' : 'quy tắc' }}</p>
                                    <p class="mt-1 max-w-[420px] text-xs text-brand-muted">
                                        @if ($unitLabels->contains('Toàn công ty'))
                                            Toàn công ty
                                        @else
                                            {{ $unitLabels->take(3)->implode(', ') }}
                                            @if ($unitLabels->count() > 3)
                                                · +{{ $unitLabels->count() - 3 }} đơn vị
                                            @endif
                                        @endif
                                        @if ($accessRules->whereNotNull('job_title_id')->isNotEmpty())
                                            · {{ $accessRules->whereNotNull('job_title_id')->pluck('jobTitle.name')->filter()->unique()->take(2)->implode(', ') }}
                                        @endif
                                    </p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @if ($hasDeny)
                                            <span class="tag tag-red">Có quy tắc chặn</span>
                                        @endif
                                        @if ($canView)
                                            <span class="tag tag-green">Xem</span>
                                        @endif
                                        @if ($canDownload)
                                            <span class="tag tag-green">Tải</span>
                                        @endif
                                        @if ($canPrint)
                                            <span class="tag tag-green">In</span>
                                        @endif
                                    </div>
                                @else
                                    <p class="text-xs font-bold text-brand-muted">Chưa có quy tắc</p>
                                @endif
                            </td>
                            <td>
                                <div class="flex flex-nowrap items-center justify-end gap-1.5">
                                    @if ($accessRules->isNotEmpty())
                                        @can('document_access.update')
                                            <button type="button" wire:click="manageDocument({{ $document->id }})"
                                                    class="admin-btn-secondary shrink-0 whitespace-nowrap !min-h-9 !px-3 !text-xs">
                                                @svg('heroicon-o-shield-check', 'h-4 w-4')
                                                Phân quyền
                                            </button>
                                        @endcan
                                    @else
                                        @can('document_access.create')
                                            <button type="button" wire:click="manageDocument({{ $document->id }})"
                                                    class="admin-btn-secondary shrink-0 whitespace-nowrap !min-h-9 !px-3 !text-xs">
                                                @svg('heroicon-o-shield-check', 'h-4 w-4')
                                                Phân quyền
                                            </button>
                                        @endcan
                                    @endif
                                    @can('document_access.delete')
                                        <button type="button" wire:click="deleteDocumentPermission({{ $document->id }})"
                                                @disabled($accessRules->isEmpty())
                                                class="inline-flex min-h-9 min-w-[64px] shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-admin border border-red-200 px-3 text-xs font-semibold text-brand-red transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-35"
                                                aria-label="Xóa phân quyền"
                                                title="Xóa phân quyền"
                                                data-confirm="Xóa toàn bộ quy tắc phân quyền của tài liệu này? Bản thân tài liệu và file sẽ được giữ nguyên."
                                                data-confirm-title="Xóa bộ phân quyền"
                                                data-confirm-ok="Xóa"
                                                data-confirm-danger>
                                            @svg('heroicon-o-trash', 'h-4 w-4')
                                            Xóa
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-10 text-center text-sm text-brand-muted">Không tìm thấy tài liệu.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($documents->hasPages())
            <div class="border-t border-brand-line p-4">{{ $documents->links() }}</div>
        @endif
    </div>

    @if ($showModal && $selectedDocument)
        <x-admin.modal title="Phân quyền tài liệu" maxWidth="max-w-4xl">
            @if ($duplicateError)
                <div role="alert" class="mb-4 rounded-admin border border-brand-red/30 bg-red-50 px-3 py-2 text-sm font-semibold text-brand-red">
                    {{ $duplicateError }}
                </div>
            @endif

            <div class="mb-5 flex items-center gap-3 rounded-admin border border-brand-line bg-brand-soft p-3.5">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-admin bg-white text-brand-red">
                    @svg($selectedDocument->isVideo() ? 'heroicon-o-play-circle' : 'heroicon-o-document', 'h-5 w-5')
                </span>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-brand-muted">Bộ phân quyền · {{ $selectedRules->count() }} quy tắc</p>
                    <p class="truncate text-sm font-bold text-brand-ink">{{ $selectedDocument->title }}</p>
                </div>
            </div>

            <form wire:submit="save" id="access-form" class="grid gap-5">
                <div class="grid gap-4 border-t border-brand-line pt-4 md:grid-cols-2">
                    <fieldset>
                        <legend class="admin-label">Đơn vị / tổ chuyên môn <span class="font-normal text-brand-muted">({{ count($departmentIds) }} đã chọn)</span></legend>
                        <p class="mb-2 text-xs text-brand-muted">Không chọn đơn vị nghĩa là áp dụng toàn công ty.</p>
                        <div class="max-h-40 space-y-1 overflow-y-auto rounded-admin border border-brand-line bg-slate-50 p-2">
                            @foreach ($departments as $department)
                                <label wire:key="access-department-{{ $department->id }}" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-xs hover:bg-white">
                                    <input type="checkbox" wire:model="departmentIds" value="{{ $department->id }}" class="h-4 w-4 rounded border-brand-line text-brand-red focus:ring-brand-red">
                                    <span>{{ $department->full_path }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('departmentIds.*') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs font-medium">
                            <input type="checkbox" wire:model="includeSubDepartments" class="h-4 w-4 rounded border-brand-line text-brand-red focus:ring-brand-red">
                            Áp dụng cho cả đơn vị cấp dưới
                        </label>
                    </fieldset>

                    <fieldset>
                        <legend class="admin-label">Cấp bậc <span class="font-normal text-brand-muted">({{ count($jobGradeIds) }} đã chọn)</span></legend>
                        <p class="mb-2 text-xs text-brand-muted">Không chọn cấp bậc nghĩa là áp dụng mọi cấp.</p>
                        <div class="max-h-40 space-y-1 overflow-y-auto rounded-admin border border-brand-line bg-slate-50 p-2">
                            @foreach ($jobGrades as $grade)
                                <label wire:key="access-grade-{{ $grade->id }}" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-xs hover:bg-white">
                                    <input type="checkbox" wire:model="jobGradeIds" value="{{ $grade->id }}" class="h-4 w-4 rounded border-brand-line text-brand-red focus:ring-brand-red">
                                    <span>{{ $grade->name }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('jobGradeIds.*') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </fieldset>
                </div>

                <div class="max-w-sm">
                    <label for="a_status" class="admin-label">Trạng thái nhân sự</label>
                    <select id="a_status" wire:model="employmentStatus" class="admin-input">
                        <option value="">Mọi trạng thái</option>
                        <option value="probation">Thử việc</option>
                        <option value="official">Chính thức</option>
                    </select>
                </div>

                <div class="border-t border-brand-line pt-4">
                    <p class="admin-label">Quyền của quy tắc này</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ([['canView', 'Xem tài liệu'], ['canDownload', 'Tải xuống'], ['canPrint', 'In']] as [$field, $label])
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-full border border-brand-line px-3 py-2 text-xs font-semibold has-[:checked]:border-brand-red has-[:checked]:bg-brand-red-tint has-[:checked]:text-brand-red">
                                <input type="checkbox" wire:model="{{ $field }}" class="h-3.5 w-3.5 rounded border-brand-line text-brand-red focus:ring-brand-red">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-brand-muted">Quyền tải vẫn chịu giới hạn bởi cài đặt cho phép tải của tài liệu.</p>
                </div>

                <div class="max-w-sm">
                    <label for="a_effect" class="admin-label">Hiệu lực</label>
                    <select id="a_effect" wire:model.live="effect" class="admin-input">
                        <option value="allow">Cho phép</option>
                        <option value="deny">Chặn truy cập</option>
                    </select>
                </div>
            </form>

            <section class="mt-5 border-t border-brand-line pt-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h4 class="text-sm font-bold">Các quy tắc trong bộ phân quyền</h4>
                        <p class="mt-0.5 text-xs text-brand-muted">Mỗi quy tắc có thể áp dụng cho nhóm nhân viên và quyền khác nhau.</p>
                    </div>
                    <span class="tag tag-red">{{ $selectedRules->count() }} {{ $selectedRules->count() === 1 ? 'quy tắc' : 'quy tắc' }}</span>
                </div>

                @if ($selectedRules->isNotEmpty())
                    <div class="grid gap-2">
                        @foreach ($selectedRules as $rule)
                            @php
                                $ruleDepartments = $rule->departmentLabel();
                                $ruleGrades = $rule->jobGrades->isNotEmpty()
                                    ? $rule->jobGrades->pluck('name')->implode(', ')
                                    : ($rule->jobGrade?->name ?? 'Mọi cấp bậc');
                            @endphp
                            <article wire:key="document-access-rule-{{ $rule->id }}" class="flex items-start gap-3 rounded-admin border border-brand-line p-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-admin {{ $rule->isDeny() ? 'bg-red-50 text-brand-red' : 'bg-blue-50 text-blue-700' }} text-[10px] font-bold">
                                    {{ strtoupper(mb_substr($rule->departments->first()?->name ?? 'TC', 0, 2)) }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-bold">{{ $ruleDepartments }}</p>
                                    <p class="mt-0.5 text-xs text-brand-muted">
                                        {{ $rule->jobTitle?->name ?? 'Tất cả chức danh' }}
                                        · {{ $ruleGrades }}
                                        @if ($rule->min_grade_level !== null)
                                            · Từ cấp {{ $rule->min_grade_level }}
                                        @endif
                                        @if ($rule->employment_status)
                                            · {{ $rule->employment_status === 'probation' ? 'Thử việc' : 'Chính thức' }}
                                        @endif
                                        ·
                                        @if ($rule->isDeny())
                                            Chặn truy cập
                                        @else
                                            {{ collect([$rule->can_view ? 'Xem tài liệu' : null, $rule->can_download ? 'Tải xuống' : null, $rule->can_print ? 'In' : null])->filter()->implode(' · ') ?: 'Không cấp quyền' }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    @can('document_access.update')
                                        <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa quy tắc" wire:click="edit({{ $rule->id }})" />
                                    @endcan
                                    @can('document_access.delete')
                                        <x-admin.icon-button icon="heroicon-o-trash" label="Xóa quy tắc" variant="danger" wire:click="delete({{ $rule->id }})" data-confirm="Chỉ xóa quy tắc này khỏi bộ phân quyền?" data-confirm-danger />
                                    @endcan
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <p class="rounded-admin border border-dashed border-brand-line px-3 py-4 text-center text-xs text-brand-muted">Chưa có quy tắc. Tạo quy tắc đầu tiên bằng form ở trên.</p>
                @endif
            </section>

            <x-slot:footer>
                <button type="button" wire:click="$set('showModal', false)" class="admin-btn-secondary">Đóng</button>
                <button type="submit" form="access-form" class="admin-btn" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? 'Lưu quy tắc' : 'Thêm quy tắc' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
