<div>
    <x-admin.page-header title="Phân quyền hệ thống"
                         subtitle="Tick các thao tác nhân viên được phép làm trên từng màn hình quản trị.">
        @if ($canEdit)
            <x-slot:actions>
                <button type="button" wire:click="resetChanges" class="admin-btn-secondary" @disabled(! $dirty)>Hủy</button>
                <button type="button" wire:click="save" class="admin-btn" @disabled(! $dirty)>Lưu</button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="admin-panel">
        {{-- Vai trò đang cấu hình. Hiện chỉ có Nhân viên nên là nhãn tĩnh;
             mở thêm vai trò (PermissionMatrix::EDITABLE_ROLES) thì tự thành tab để chọn --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-line px-4 py-3">
            @if ($roles->count() === 1)
                @php $only = $roles->first(); @endphp
                <div class="flex flex-wrap items-center gap-2">
                    <span class="admin-label !mb-0 mr-1">Vai trò</span>
                    <span class="inline-flex h-9 items-center gap-2 rounded-admin border border-brand-red bg-brand-red-tint px-3.5 text-sm font-semibold text-brand-red">
                        @svg('heroicon-o-user-group', 'h-4 w-4')
                        {{ $this->roleLabel($only->name) }}
                        <span class="rounded-full bg-white px-1.5 py-px text-[11px] font-bold leading-4">
                            {{ $this->grantedCount($only) }}/{{ $totalPermissions }} quyền
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

            <p class="text-xs text-brand-muted">
                <strong class="text-brand-ink">{{ \App\Enums\RoleName::ADMIN->label() }}</strong> luôn có toàn quyền nên không cần cấu hình.
            </p>
        </div>

        @if ($dirty)
            <div class="flex items-center gap-2 border-b border-state-warning bg-state-warning-tint px-4 py-2.5 text-sm font-semibold text-state-warning">
                @svg('heroicon-o-exclamation-circle', 'h-4 w-4 shrink-0')
                Có thay đổi chưa được lưu.
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] table-fixed border-collapse text-[13px]">
                <colgroup>
                    <col>
                    @foreach ($actions as $action => $label)
                        <col class="w-[104px]">
                    @endforeach
                    @if ($canEdit)
                        <col class="w-[96px]">
                    @endif
                </colgroup>

                <thead>
                    <tr class="border-b border-brand-line bg-[#fafbfc] text-[11px] font-semibold uppercase tracking-wide text-brand-muted">
                        <th class="h-12 px-4 text-left align-middle">Màn hình</th>
                        @foreach ($actions as $action => $label)
                            <th class="h-12 px-2 text-center align-middle">
                                <span class="block">{{ $label }}</span>
                                @if ($canEdit)
                                    <button type="button" wire:click="toggleColumn('{{ $action }}')"
                                            class="mt-0.5 text-[11px] font-semibold normal-case tracking-normal text-brand-red hover:underline">
                                        Chọn cả cột
                                    </button>
                                @endif
                            </th>
                        @endforeach
                        @if ($canEdit)
                            <th class="h-12 px-2 text-center align-middle">Cả dòng</th>
                        @endif
                    </tr>
                </thead>

                @foreach ($modules as $group => $groupModules)
                    <tbody>
                        <tr class="border-b border-brand-line bg-brand-soft">
                            <th colspan="{{ count($actions) + ($canEdit ? 2 : 1) }}"
                                class="px-4 py-2 text-left align-middle text-[11px] font-bold uppercase tracking-wide text-brand-ink">
                                {{ $group }}
                            </th>
                        </tr>

                        @foreach ($groupModules as $module => [$moduleLabel, $moduleActions])
                            @php
                                $rowNames = collect($moduleActions)->map(fn ($a) => \App\Support\PermissionCatalog::name($module, $a));
                                $rowAll = $rowNames->every(fn ($n) => $granted[$n] ?? false);
                            @endphp

                            <tr wire:key="perm-{{ $role }}-{{ $module }}"
                                class="border-b border-[#eef0f4] transition-colors last:border-b-0 hover:bg-brand-soft/60">
                                <td class="h-12 px-4 align-middle font-semibold text-brand-ink">{{ $moduleLabel }}</td>

                                @foreach ($actions as $action => $actionLabel)
                                    <td class="h-12 px-2 text-center align-middle">
                                        @if (in_array($action, $moduleActions, true))
                                            @php $checked = $granted[\App\Support\PermissionCatalog::name($module, $action)] ?? false; @endphp
                                            <button type="button"
                                                    wire:click="toggle('{{ $module }}', '{{ $action }}')"
                                                    @disabled(! $canEdit)
                                                    @class([
                                                        'mx-auto grid h-6 w-6 place-items-center rounded-admin-sm border-2 transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                                                        'border-brand-red bg-brand-red text-white' => $checked,
                                                        'border-[#cfd4dc] bg-white text-transparent hover:border-brand-red' => ! $checked,
                                                    ])
                                                    role="checkbox"
                                                    aria-checked="{{ $checked ? 'true' : 'false' }}"
                                                    aria-label="{{ $actionLabel }} — {{ $moduleLabel }}">
                                                @svg('heroicon-o-check', 'h-3.5 w-3.5 stroke-[3]')
                                            </button>
                                        @else
                                            <span class="text-[#c3c8d0]" title="Màn hình này không có thao tác {{ $actionLabel }}">—</span>
                                        @endif
                                    </td>
                                @endforeach

                                @if ($canEdit)
                                    <td class="h-12 px-2 text-center align-middle">
                                        <button type="button" wire:click="toggleRow('{{ $module }}')"
                                                class="text-xs font-semibold {{ $rowAll ? 'text-brand-muted hover:text-brand-red' : 'text-brand-red hover:underline' }}">
                                            {{ $rowAll ? 'Bỏ hết' : 'Chọn hết' }}
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>

        <p class="border-t border-brand-line px-4 py-3 text-xs text-brand-muted">
            Tick Thêm / Sửa / Xóa sẽ tự tick Xem; bỏ Xem sẽ bỏ hết thao tác của màn đó. Dấu — là màn hình không có thao tác này.
        </p>
    </div>
</div>
