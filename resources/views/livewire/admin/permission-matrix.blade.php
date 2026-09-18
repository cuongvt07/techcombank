<div>
    <x-admin.page-header title="Phân quyền hệ thống"
                         subtitle="Ma trận vai trò × quyền cho từng loại tài nguyên.">
        <x-slot:actions>
            @if ($dirty)
                <button type="button" wire:click="resetChanges" class="admin-btn-secondary">Hoàn tác</button>
            @endif
            <button type="button" wire:click="save" class="admin-btn" @disabled(! $dirty)>
                Lưu thay đổi
            </button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($dirty)
        <div class="mb-4 rounded-admin border border-state-warning bg-state-warning-tint px-4 py-3 text-sm font-semibold text-state-warning">
            Có thay đổi chưa được lưu.
        </div>
    @endif

    <div class="mb-4 rounded-admin border border-brand-line bg-white px-4 py-3 text-sm text-brand-muted">
        Vai trò <strong class="text-brand-ink">{{ \App\Enums\RoleName::ADMIN->label() }}</strong>
        luôn có toàn quyền và không xuất hiện trong bảng này.
    </div>

    <div class="admin-panel overflow-x-auto">
        <table class="admin-table">
            <thead>
                <tr>
                    <th class="sticky left-0 z-10 bg-[#fafbfc] min-w-[260px]">Quyền</th>
                    @foreach ($roles as $role)
                        <th class="text-center min-w-[130px]">
                            {{ \App\Enums\RoleName::tryFrom($role->name)?->label() ?? $role->name }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($groupedPermissions as $group => $permissions)
                    {{-- Dòng nhóm: cho phép bật/tắt cả cụm quyền của một tài nguyên --}}
                    <tr class="bg-brand-soft">
                        <td class="sticky left-0 z-10 bg-brand-soft font-bold uppercase text-xs tracking-wide">
                            {{ $this->groupLabel($group) }}
                        </td>
                        @foreach ($roles as $role)
                            <td class="text-center">
                                <button type="button"
                                        wire:click="toggleGroup('{{ $role->name }}', '{{ $group }}')"
                                        class="whitespace-nowrap text-[11px] font-semibold text-brand-muted hover:text-brand-red">
                                    Tất cả
                                </button>
                            </td>
                        @endforeach
                    </tr>

                    @foreach ($permissions as $permission)
                        <tr wire:key="perm-{{ $permission->id }}">
                            <td class="sticky left-0 z-10 bg-white">
                                <div class="font-bold">{{ $this->actionLabel($permission->name) }}</div>
                                <div class="font-mono text-[11px] text-brand-muted">{{ $permission->name }}</div>
                            </td>

                            @foreach ($roles as $role)
                                @php $checked = $matrix[$role->name][$permission->name] ?? false; @endphp
                                <td class="text-center">
                                    <button type="button"
                                            wire:click="toggle('{{ $role->name }}', '{{ $permission->name }}')"
                                            @class([
                                                'grid h-7 w-7 mx-auto place-items-center rounded-admin border transition-colors',
                                                'border-brand-red bg-brand-red text-white' => $checked,
                                                'border-brand-line bg-white text-transparent hover:border-brand-red' => ! $checked,
                                            ])
                                            aria-pressed="{{ $checked ? 'true' : 'false' }}"
                                            aria-label="{{ $this->actionLabel($permission->name) }} — {{ $role->name }}">
                                        @svg('heroicon-o-check', 'h-3.5 w-3.5 stroke-[3]')
                                    </button>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</div>
