<div>
    <x-admin.page-header title="Phân quyền hệ thống">
        @if ($canEdit)
            <x-slot:actions>
                <button type="button" wire:click="resetChanges" class="admin-btn-secondary" @disabled(! $dirty)>Hủy</button>
                <button type="button" wire:click="save" class="admin-btn" @disabled(! $dirty)>Lưu</button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="admin-panel">
        {{-- Vai trò đang cấu hình (PermissionMatrix::EDITABLE_ROLES: Nhân sự, Quản lý).
             Chỉ còn một vai trò thì hiện nhãn tĩnh, nhiều vai trò thì thành tab để chọn.
             Vai trò Nhân viên không có ở đây — chỉ giữ quyền học tập cố định. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-line px-4 py-3">
            @if ($roles->count() === 1)
                @php $only = $roles->first(); @endphp
                <div class="flex flex-wrap items-center gap-2">
                    <span class="admin-label !mb-0 mr-1">Vai trò</span>
                    <span class="inline-flex h-9 items-center gap-2 rounded-admin border border-brand-red bg-brand-red-tint px-3.5 text-sm font-semibold text-brand-red">
                        @svg('heroicon-o-user-group', 'h-4 w-4')
                        {{ $this->roleLabel($only->name) }}
                        <span class="rounded-full bg-white px-1.5 py-px text-[11px] font-bold leading-4">
                            {{ $this->grantedCount($only) }}/{{ $totalPermissions }} màn được xem
                        </span>
                    </span>
                </div>
            @else
            <div class="flex flex-wrap items-center gap-2" role="tablist" aria-label="Vai trò">
                <span class="admin-label !mb-0 mr-1">Vai trò</span>
                @foreach ($roles as $r)
                    @php $active = $r->name === $role; @endphp
                    <button type="button" role="tab" aria-selected="{{ $active ? 'true' : 'false' }}"
                            wire:click="$set('role', '{{ $r->name }}')"
                            @if ($dirty && ! $active)
                                data-confirm="Đổi vai trò sẽ bỏ các thay đổi chưa lưu. Tiếp tục?"
                                data-confirm-title="Chưa lưu thay đổi"
                                data-confirm-ok="Bỏ thay đổi"
                            @endif
                            @class([
                                'inline-flex h-9 items-center gap-2 rounded-admin border px-3.5 text-sm font-semibold transition-colors',
                                'border-brand-red bg-brand-red text-white' => $active,
                                'border-brand-line bg-white text-brand-ink hover:border-brand-red hover:text-brand-red' => ! $active,
                            ])>
                        {{ $this->roleLabel($r->name) }}
                        <span @class([
                            'rounded-full px-1.5 py-px text-[11px] font-bold leading-4',
                            'bg-white/20 text-white' => $active,
                            'bg-brand-soft text-brand-muted' => ! $active,
                        ])>{{ $this->grantedCount($r) }}/{{ $totalPermissions }}</span>
                    </button>
                @endforeach
            </div>
            @endif

            {{-- Ghi chú vai trò cố định: chuyển từ dòng chữ nhỏ sang tooltip (fb7 mục 7) --}}
            <span class="inline-flex items-center gap-1 text-xs text-brand-muted"
                  title="{{ \App\Enums\RoleName::ADMIN->label() }} luôn có toàn quyền; {{ \App\Enums\RoleName::EMPLOYEE->label() }} chỉ có quyền học tập — không cần cấu hình.">
                @svg('heroicon-o-information-circle', 'h-4 w-4')
                <span class="sr-only">{{ \App\Enums\RoleName::ADMIN->label() }} luôn có toàn quyền; {{ \App\Enums\RoleName::EMPLOYEE->label() }} chỉ có quyền học tập — không cần cấu hình.</span>
            </span>
        </div>

        @if ($dirty)
            <div class="flex items-center gap-2 border-b border-state-warning bg-state-warning-tint px-4 py-2.5 text-sm font-semibold text-state-warning">
                @svg('heroicon-o-exclamation-circle', 'h-4 w-4 shrink-0')
                Có thay đổi chưa được lưu.
            </div>
        @endif

        <div class="data-grid-scroll">
            <table class="permission-grid w-full min-w-[560px] table-fixed border-collapse text-[13px]">
                <colgroup>
                    <col>
                    @foreach ($columns as $column => $label)
                        <col class="w-[130px]">
                    @endforeach
                </colgroup>

                <thead>
                    <tr class="border-b border-brand-line bg-[#fafbfc] text-[11px] font-semibold uppercase tracking-wide text-brand-muted">
                        <th class="h-12 px-4 text-left align-middle">Màn hình<span class="ml-1 inline-flex align-middle normal-case tracking-normal text-brand-muted" title="&quot;Thao tác&quot; gồm mọi thao tác Thêm / Sửa / Xóa của màn đó. Tick Thao tác sẽ tự tick Xem; bỏ Xem sẽ bỏ luôn Thao tác. Dấu — là màn chỉ để xem.">@svg('heroicon-o-information-circle', 'h-3.5 w-3.5')<span class="sr-only">&quot;Thao tác&quot; gồm mọi thao tác Thêm / Sửa / Xóa của màn đó. Tick Thao tác sẽ tự tick Xem; bỏ Xem sẽ bỏ luôn Thao tác. Dấu — là màn chỉ để xem.</span></span></th>
                        @foreach ($columns as $column => $label)
                            <th class="h-12 px-2 text-center align-middle">
                                <span class="block">{{ $label }}</span>
                                @if ($canEdit)
                                    <button type="button" wire:click="toggleColumn('{{ $column }}')"
                                            class="mt-0.5 text-[11px] font-semibold normal-case tracking-normal text-brand-red hover:underline">
                                        Chọn cả cột
                                    </button>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>

                @foreach ($modules as $group => $groupModules)
                    <tbody>
                        <tr class="border-b border-brand-line bg-brand-soft">
                            <th colspan="{{ count($columns) + 1 }}"
                                class="px-4 py-2 text-left align-middle text-[11px] font-bold uppercase tracking-wide text-brand-ink">
                                {{ $group }}
                            </th>
                        </tr>

                        @foreach ($groupModules as $module => [$moduleLabel, $moduleActions])
                            @php
                                $manageActions = \App\Support\PermissionCatalog::manageActions($module);
                                // Tên các thao tác gói trong cột "Thao tác" của màn này, vd "Thêm, Sửa, Xóa"
                                $manageText = collect($manageActions)->map(fn ($a) => \App\Support\PermissionCatalog::ACTIONS[$a])->implode(', ');
                            @endphp

                            <tr wire:key="perm-{{ $role }}-{{ $module }}"
                                class="border-b border-[#eef0f4] transition-colors last:border-b-0 hover:bg-brand-soft/60">
                                <td class="h-12 px-4 align-middle">
                                    <span class="font-semibold text-brand-ink">{{ $moduleLabel }}</span>
                                    @if ($manageText)
                                        <span class="ml-1.5 text-xs text-brand-muted">· Thao tác: {{ $manageText }}</span>
                                    @endif
                                </td>

                                @foreach ($columns as $column => $columnLabel)
                                    <td class="h-12 px-2 text-center align-middle">
                                        @php
                                            $available = $column === 'view' ? in_array('view', $moduleActions, true) : $manageActions !== [];
                                            $checked = $column === 'view'
                                                ? ($granted[\App\Support\PermissionCatalog::name($module, 'view')] ?? false)
                                                : $this->hasManage($module);
                                            // Dữ liệu cũ có thể chỉ cấp lẻ vài thao tác: hiện "một phần" để người dùng biết
                                            $partial = $column === 'manage' && $this->hasPartialManage($module);
                                        @endphp
                                        @if ($available)
                                            <button type="button"
                                                    wire:click="toggle('{{ $module }}', '{{ $column }}')"
                                                    @disabled(! $canEdit)
                                                    @class([
                                                        'mx-auto grid h-6 w-6 place-items-center rounded-admin-sm border-2 transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                                                        'border-brand-red bg-brand-red text-white' => $checked,
                                                        'border-brand-red bg-brand-red-tint text-brand-red' => $partial,
                                                        'border-[#cfd4dc] bg-white text-transparent hover:border-brand-red' => ! $checked && ! $partial,
                                                    ])
                                                    role="checkbox"
                                                    aria-checked="{{ $checked ? 'true' : ($partial ? 'mixed' : 'false') }}"
                                                    title="{{ $column === 'manage' ? 'Được ' . mb_strtolower($manageText) . ' trên màn này' : 'Được vào xem màn này' }}"
                                                    aria-label="{{ $columnLabel }} — {{ $moduleLabel }}">
                                                @if ($partial)
                                                    @svg('heroicon-o-minus', 'h-3.5 w-3.5 stroke-[3]')
                                                @else
                                                    @svg('heroicon-o-check', 'h-3.5 w-3.5 stroke-[3]')
                                                @endif
                                            </button>
                                        @else
                                            <span class="text-[#c3c8d0]" title="Màn hình này chỉ để xem, không có thao tác">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>

    </div>
</div>
