<div>
    <x-admin.page-header title="Trung tâm bảo mật"
                         subtitle="Cảnh báo bất thường, nhật ký truy cập tài liệu, lịch sử đăng nhập và phiên thiết bị.">
        <x-slot:actions>
            <button type="button" wire:click="runScan" class="admin-btn-secondary">
                @svg('heroicon-o-magnifying-glass', 'h-4 w-4')
                Quét ngay
            </button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="admin-panel p-3.5">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Cảnh báo chưa xử lý</span>
            <strong class="mt-1 block text-2xl leading-none {{ $counters['open_alerts'] > 0 ? 'text-brand-red' : '' }}">
                {{ $counters['open_alerts'] }}
            </strong>
        </div>
        <div class="admin-panel p-3.5">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Mức nghiêm trọng</span>
            <strong class="mt-1 block text-2xl leading-none {{ $counters['critical'] > 0 ? 'text-brand-red' : '' }}">
                {{ $counters['critical'] }}
            </strong>
        </div>
        <div class="admin-panel p-3.5">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Phiên đang hoạt động</span>
            <strong class="mt-1 block text-2xl leading-none">{{ $counters['active_devices'] }}</strong>
        </div>
        <div class="admin-panel p-3.5">
            <span class="text-[11px] font-semibold uppercase text-brand-muted">Lượt bị từ chối</span>
            <strong class="mt-1 block text-2xl leading-none">{{ $counters['denied_access'] }}</strong>
        </div>
    </div>

    <div class="admin-panel">
        {{-- Tab: bốn nguồn dữ liệu cần đối chiếu chéo khi điều tra sự cố --}}
        <div class="flex flex-wrap gap-1 border-b border-brand-line p-2">
            @foreach ([
                'alerts' => 'Cảnh báo',
                'document-logs' => 'Nhật ký tài liệu',
                'logins' => 'Lịch sử đăng nhập',
                'devices' => 'Phiên thiết bị',
            ] as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')"
                        @class([
                            'rounded-admin px-3 py-2 text-[13px] font-bold transition-colors',
                            'bg-brand-red text-white' => $tab === $key,
                            'text-brand-ink hover:bg-brand-soft' => $tab !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
            <div class="min-w-[220px] flex-1">
                <input type="search" wire:model.live.debounce.400ms="search" class="admin-input"
                       placeholder="Tìm theo tên nhân viên, tài liệu hoặc IP...">
            </div>

            @if ($tab === 'alerts')
                <select wire:model.live="statusFilter" class="admin-input w-auto min-w-[180px]">
                    <option value="">Mọi trạng thái</option>
                    <option value="open">Chưa xử lý</option>
                    <option value="acknowledged">Đã ghi nhận</option>
                    <option value="resolved">Đã đóng</option>
                    <option value="false_positive">Cảnh báo nhầm</option>
                </select>
            @elseif ($tab === 'document-logs')
                <select wire:model.live="actionFilter" class="admin-input w-auto min-w-[170px]">
                    <option value="">Mọi hành động</option>
                    <option value="view">Xem</option>
                    <option value="download">Tải xuống</option>
                    <option value="print">In</option>
                    <option value="stream">Xem video</option>
                    <option value="denied">Bị từ chối</option>
                </select>
            @elseif ($tab === 'logins')
                <select wire:model.live="statusFilter" class="admin-input w-auto min-w-[170px]">
                    <option value="">Mọi kết quả</option>
                    <option value="success">Thành công</option>
                    <option value="failed">Thất bại</option>
                    <option value="blocked">Bị chặn</option>
                </select>
            @else
                <select wire:model.live="statusFilter" class="admin-input w-auto min-w-[170px]">
                    <option value="">Mọi phiên</option>
                    <option value="active">Đang hoạt động</option>
                    <option value="revoked">Đã thu hồi</option>
                </select>
            @endif

            @if (in_array($tab, ['document-logs', 'logins'], true))
                <select wire:model.live="days" class="admin-input w-auto min-w-[150px]">
                    <option value="1">24 giờ qua</option>
                    <option value="7">7 ngày qua</option>
                    <option value="30">30 ngày qua</option>
                    <option value="90">90 ngày qua</option>
                </select>
            @endif
        </div>

        <div class="overflow-x-auto">
            @if ($tab === 'alerts')
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="w-28">Mức độ</th>
                            <th>Cảnh báo</th>
                            <th>Tài khoản</th>
                            <th class="w-40">Thời điểm</th>
                            <th class="w-32">Trạng thái</th>
                            <th class="w-32 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $alert)
                            <tr wire:key="alert-{{ $alert->id }}">
                                <td>
                                    @php
                                        $sevMap = [
                                            'critical' => ['tag-red', 'Nghiêm trọng'],
                                            'high' => ['tag-red', 'Cao'],
                                            'medium' => ['tag-amber', 'Trung bình'],
                                            'low' => ['', 'Thấp'],
                                        ];
                                        [$cls, $label] = $sevMap[$alert->severity] ?? ['', $alert->severity];
                                    @endphp
                                    <span class="tag {{ $cls }}">{{ $label }}</span>
                                </td>
                                <td>
                                    <button type="button" wire:click="viewAlert({{ $alert->id }})"
                                            class="text-left font-bold hover:text-brand-red">
                                        {{ $alert->title }}
                                    </button>
                                    @if ($alert->detail)
                                        <p class="mt-0.5 max-w-md truncate text-xs text-brand-muted">{{ $alert->detail }}</p>
                                    @endif
                                </td>
                                <td>
                                    <div class="min-w-0">
                                        <div class="truncate font-bold">
                                            {{ $alert->user?->employee?->full_name ?? $alert->user?->name ?? '—' }}
                                        </div>
                                        <div class="truncate text-xs text-brand-muted">{{ $alert->user?->email }}</div>
                                    </div>
                                </td>
                                <td class="text-xs text-brand-muted">{{ $alert->created_at->format('d/m/Y H:i') }}</td>
                                <td>
                                    @php
                                        $stMap = [
                                            'open' => ['tag-red', 'Chưa xử lý'],
                                            'acknowledged' => ['tag-amber', 'Đã ghi nhận'],
                                            'resolved' => ['tag-green', 'Đã đóng'],
                                            'false_positive' => ['', 'Nhầm'],
                                        ];
                                        [$scls, $slabel] = $stMap[$alert->status] ?? ['', $alert->status];
                                    @endphp
                                    <span class="tag {{ $scls }}">{{ $slabel }}</span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-admin.icon-button icon="heroicon-o-eye" label="Xem chi tiết"
                                                             wire:click="viewAlert({{ $alert->id }})" />
                                        @if ($alert->status === 'open')
                                            <x-admin.icon-button icon="heroicon-o-hand-raised" label="Ghi nhận cảnh báo"
                                                                 wire:click="acknowledge({{ $alert->id }})" />
                                        @endif
                                        @if (in_array($alert->status, ['open', 'acknowledged'], true))
                                            <x-admin.icon-button icon="heroicon-o-check-circle" label="Đóng cảnh báo"
                                                                 variant="primary"
                                                                 wire:click="resolve({{ $alert->id }})" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-brand-muted">Không có cảnh báo nào.</td></tr>
                        @endforelse
                    </tbody>
                </table>

            @elseif ($tab === 'document-logs')
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="w-40">Thời điểm</th>
                            <th>Nhân viên</th>
                            <th>Tài liệu</th>
                            <th class="w-28">Hành động</th>
                            <th class="w-36">IP</th>
                            <th class="w-36">Mã watermark</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $log)
                            <tr wire:key="log-{{ $log->id }}">
                                <td class="text-xs">{{ $log->accessed_at?->format('d/m/Y H:i:s') }}</td>
                                <td>
                                    <div class="min-w-0">
                                        <div class="truncate font-bold">{{ $log->employee?->full_name ?? '—' }}</div>
                                        <div class="truncate text-xs text-brand-muted">
                                            {{ $log->employee?->department?->name }}
                                        </div>
                                    </div>
                                </td>
                                <td class="max-w-xs truncate">{{ $log->document?->title ?? '—' }}</td>
                                <td>
                                    @php
                                        $acMap = [
                                            'view' => ['', 'Xem'],
                                            'download' => ['tag-amber', 'Tải'],
                                            'print' => ['tag-amber', 'In'],
                                            'stream' => ['', 'Video'],
                                            'denied' => ['tag-red', 'Từ chối'],
                                        ];
                                        [$acls, $alabel] = $acMap[$log->action] ?? ['', $log->action];
                                    @endphp
                                    <span class="tag {{ $acls }}">{{ $alabel }}</span>
                                </td>
                                <td class="font-mono text-xs text-brand-muted">{{ $log->ip_address ?? '—' }}</td>
                                <td class="font-mono text-[11px] text-brand-muted">
                                    {{ $log->watermark_token ? Str::limit($log->watermark_token, 12) : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-brand-muted">Không có lượt truy cập nào trong khoảng thời gian này.</td></tr>
                        @endforelse
                    </tbody>
                </table>

            @elseif ($tab === 'logins')
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="w-40">Thời điểm</th>
                            <th>Tài khoản</th>
                            <th class="w-36">IP</th>
                            <th>Thiết bị</th>
                            <th class="w-28">Kết quả</th>
                            <th>Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $login)
                            <tr wire:key="login-{{ $login->id }}">
                                <td class="text-xs">{{ $login->logged_in_at?->format('d/m/Y H:i:s') }}</td>
                                <td>
                                    <div class="min-w-0">
                                        <div class="truncate font-bold">
                                            {{ $login->user?->employee?->full_name ?? $login->user?->name ?? '—' }}
                                        </div>
                                        <div class="truncate text-xs text-brand-muted">{{ $login->user?->email }}</div>
                                    </div>
                                </td>
                                <td class="font-mono text-xs text-brand-muted">{{ $login->ip_address ?? '—' }}</td>
                                <td class="text-xs text-brand-muted">{{ $login->device_label ?? '—' }}</td>
                                <td>
                                    @php
                                        $rMap = [
                                            'success' => ['tag-green', 'Thành công'],
                                            'failed' => ['tag-amber', 'Thất bại'],
                                            'blocked' => ['tag-red', 'Bị chặn'],
                                        ];
                                        [$rcls, $rlabel] = $rMap[$login->result] ?? ['', $login->result];
                                    @endphp
                                    <span class="tag {{ $rcls }}">{{ $rlabel }}</span>
                                </td>
                                <td class="text-xs text-brand-muted">{{ $login->failure_reason ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-brand-muted">Không có lượt đăng nhập nào.</td></tr>
                        @endforelse
                    </tbody>
                </table>

            @else
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Tài khoản</th>
                            <th>Thiết bị</th>
                            <th class="w-36">IP</th>
                            <th class="w-40">Hoạt động gần nhất</th>
                            <th class="w-28">Trạng thái</th>
                            <th class="w-28 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $session)
                            <tr wire:key="dev-{{ $session->id }}">
                                <td>
                                    <div class="min-w-0">
                                        <div class="truncate font-bold">
                                            {{ $session->user?->employee?->full_name ?? $session->user?->name ?? '—' }}
                                        </div>
                                        <div class="truncate text-xs text-brand-muted">{{ $session->user?->email }}</div>
                                    </div>
                                </td>
                                <td class="text-brand-muted">{{ $session->device_label ?? '—' }}</td>
                                <td class="font-mono text-xs text-brand-muted">{{ $session->ip_address ?? '—' }}</td>
                                <td class="text-xs text-brand-muted">
                                    {{ $session->last_activity_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                                <td>
                                    <span class="tag {{ $session->is_active ? 'tag-green' : '' }}">
                                        {{ $session->is_active ? 'Hoạt động' : 'Đã thu hồi' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1.5">
                                        @if ($session->is_active)
                                            <x-admin.icon-button icon="heroicon-o-arrow-right-on-rectangle" label="Thu hồi phiên này"
                                                                 wire:click="revokeSession({{ $session->id }})"
                                                                 wire:confirm="Thu hồi phiên này? Người dùng sẽ phải đăng nhập lại." />
                                            @if ($session->user_id)
                                                <x-admin.icon-button icon="heroicon-o-shield-exclamation" label="Thu hồi tất cả phiên của tài khoản"
                                                                     variant="danger"
                                                                     wire:click="revokeAllForUser({{ $session->user_id }})"
                                                                     wire:confirm="Thu hồi TẤT CẢ phiên của tài khoản này?" />
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-brand-muted">Không có phiên nào.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-brand-line p-4">{{ $rows->links() }}</div>
        @endif
    </div>

    {{-- Chi tiết cảnh báo --}}
    @if ($showDetail && $detailAlert)
        <x-admin.modal wireModel="showDetail" title="Chi tiết cảnh báo">
            <div class="grid gap-4">
                <div>
                    <h4 class="text-lg font-bold">{{ $detailAlert->title }}</h4>
                    @if ($detailAlert->detail)
                        <p class="mt-1 text-sm text-brand-muted">{{ $detailAlert->detail }}</p>
                    @endif
                </div>

                <dl class="grid gap-2 rounded-admin border border-brand-line bg-brand-soft p-3.5 text-sm">
                    <div class="flex gap-2">
                        <dt class="w-32 shrink-0 font-bold">Loại:</dt>
                        <dd class="font-mono text-xs">{{ $detailAlert->type }}</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="w-32 shrink-0 font-bold">Tài khoản:</dt>
                        <dd>{{ $detailAlert->user?->employee?->full_name ?? $detailAlert->user?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="w-32 shrink-0 font-bold">Thời điểm:</dt>
                        <dd>{{ $detailAlert->created_at->format('d/m/Y H:i:s') }}</dd>
                    </div>
                    @if ($detailAlert->handledBy)
                        <div class="flex gap-2">
                            <dt class="w-32 shrink-0 font-bold">Người xử lý:</dt>
                            <dd>{{ $detailAlert->handledBy->name }} — {{ $detailAlert->handled_at?->format('d/m/Y H:i') }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($detailAlert->context)
                    <div>
                        <p class="admin-label">Dữ liệu kèm theo</p>
                        <pre class="overflow-x-auto rounded-admin border border-brand-line bg-white p-3 font-mono text-xs">{{ json_encode($detailAlert->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                @endif
            </div>

            <x-slot:footer>
                @if (in_array($detailAlert->status, ['open', 'acknowledged'], true))
                    <button type="button" wire:click="markFalsePositive({{ $detailAlert->id }})"
                            class="admin-btn-secondary">Cảnh báo nhầm</button>
                    <button type="button" wire:click="resolve({{ $detailAlert->id }})"
                            class="admin-btn">Đóng cảnh báo</button>
                @else
                    <button type="button" wire:click="$set('showDetail', false)" class="admin-btn-secondary">Đóng</button>
                @endif
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
