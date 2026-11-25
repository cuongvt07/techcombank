<div>
    <x-admin.page-header title="Trung tâm bảo mật" />

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

    {{-- Tab nguồn dữ liệu là một card riêng; bộ lọc và bảng tách thành hai card bên dưới (fb7 mục 4) --}}
    <div class="admin-panel mb-4">
        {{-- Tab: bốn nguồn dữ liệu cần đối chiếu chéo khi điều tra sự cố --}}
        <div class="flex flex-wrap gap-1 p-2">
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

        {{-- Tab con theo bước xử lý: ghi nhận / đóng là cảnh báo tự chuyển tab --}}
        @if ($tab === 'alerts')
            <div class="flex flex-wrap gap-6 border-t border-brand-line px-4" role="tablist" aria-label="Trạng thái cảnh báo">
                @foreach (\App\Livewire\Admin\SecurityCenter::ALERT_TABS as $key => [$label])
                    @php $activeSub = $alertTab === $key; @endphp
                    <button type="button" role="tab" aria-selected="{{ $activeSub ? 'true' : 'false' }}"
                            wire:click="setAlertTab('{{ $key }}')"
                            @class([
                                '-mb-px inline-flex items-center gap-2 border-b-2 py-3 text-[13px] font-bold transition-colors',
                                'border-brand-red text-brand-red' => $activeSub,
                                'border-transparent text-brand-muted hover:text-brand-ink' => ! $activeSub,
                            ])>
                        {{ $label }}
                        <span @class([
                            'rounded-full px-1.5 py-px text-[11px] font-bold leading-4',
                            'bg-brand-red text-white' => $activeSub || ($key === 'open' && $alertTabCounts[$key] > 0),
                            'bg-brand-soft text-brand-muted' => ! ($activeSub || ($key === 'open' && $alertTabCounts[$key] > 0)),
                        ])>{{ $alertTabCounts[$key] }}</span>
                    </button>
                @endforeach
            </div>
        @endif

    </div>

    <x-admin.list-toolbar placeholder="Tìm theo tên nhân viên, tài liệu hoặc IP...">
        @if ($tab === 'document-logs')
            <x-admin.list-filter label="Hành động" model="actionFilter">
                <option value="">Tất cả</option>
                <option value="view">Xem</option>
                <option value="download">Tải xuống</option>
                <option value="print">In</option>
                <option value="stream">Xem video</option>
                <option value="denied">Bị từ chối</option>
            </x-admin.list-filter>
        @elseif ($tab === 'logins')
            <x-admin.list-filter label="Kết quả" model="statusFilter">
                <option value="">Tất cả</option>
                <option value="success">Thành công</option>
                <option value="failed">Thất bại</option>
                <option value="blocked">Bị chặn</option>
            </x-admin.list-filter>
        @elseif ($tab === 'devices')
            <x-admin.list-filter label="Trạng thái phiên" model="statusFilter">
                <option value="">Tất cả</option>
                <option value="active">Đang hoạt động</option>
                <option value="revoked">Đã thu hồi</option>
            </x-admin.list-filter>
        @endif

        @if (in_array($tab, ['document-logs', 'logins'], true))
            <x-admin.list-filter label="Thời gian" model="days">
                <option value="1">24 giờ qua</option>
                <option value="7">7 ngày qua</option>
                <option value="30">30 ngày qua</option>
                <option value="90">90 ngày qua</option>
            </x-admin.list-filter>
        @endif
    </x-admin.list-toolbar>

    <div class="admin-panel">
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
                                    {{-- Chi tiết cảnh báo: bỏ dòng chữ nhỏ dưới tiêu đề, xem qua tooltip hoặc bấm mở --}}
                                    <button type="button" wire:click="viewAlert({{ $alert->id }})"
                                            @if ($alert->detail) title="{{ $alert->detail }}" @endif
                                            class="text-left font-bold hover:text-brand-red">
                                        {{ $alert->title }}
                                    </button>
                                </td>
                                <td>
                                    <div class="min-w-0">
                                        <div class="truncate font-bold">
                                            {{ $alert->user?->employee?->full_name ?? $alert->user?->name ?? '—' }}
                                        </div>
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
                                            @can('security.update')
                                            <x-admin.icon-button icon="heroicon-o-hand-raised" label="Ghi nhận cảnh báo"
                                                                 wire:click="acknowledge({{ $alert->id }})" />
                                            @endcan
                                        @endif
                                        @if (in_array($alert->status, ['open', 'acknowledged'], true))
                                            @can('security.update')
                                            <x-admin.icon-button icon="heroicon-o-check-circle" label="Đóng cảnh báo"
                                                                 variant="primary"
                                                                 wire:click="resolve({{ $alert->id }})" />
                                            @endcan
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-brand-muted">
                                {{ $alertTab === 'open' ? 'Không còn cảnh báo nào cần xử lý.' : 'Không có cảnh báo nào.' }}
                            </td></tr>
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
                                            @can('security.update')
                                            <x-admin.icon-button icon="heroicon-o-arrow-right-on-rectangle" label="Thu hồi phiên này"
                                                                 wire:click="revokeSession({{ $session->id }})"
                                                                 data-confirm="Thu hồi phiên này? Người dùng sẽ phải đăng nhập lại." data-confirm-danger />
                                            @endcan
                                            @if ($session->user_id)
                                                @can('security.update')
                                                <x-admin.icon-button icon="heroicon-o-shield-exclamation" label="Thu hồi tất cả phiên của tài khoản"
                                                                     variant="danger"
                                                                     wire:click="revokeAllForUser({{ $session->user_id }})"
                                                                     data-confirm="Thu hồi TẤT CẢ phiên của tài khoản này?" data-confirm-danger />
                                                @endcan
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

        <x-admin.list-pagination :paginator="$rows" />
    </div>

    {{-- Chi tiết cảnh báo --}}
    @if ($showDetail && $detailAlert)
        <x-admin.modal wireModel="showDetail" title="Chi tiết cảnh báo">
            <div class="grid gap-4">
                <x-admin.detail-field label="Cảnh báo">
                    <span class="text-base font-bold">{{ $detailAlert->title }}</span>
                </x-admin.detail-field>

                @if ($detailAlert->detail)
                    <x-admin.detail-field label="Mô tả">
                        <span class="text-brand-muted">{{ $detailAlert->detail }}</span>
                    </x-admin.detail-field>
                @endif

                <dl class="grid gap-2 rounded-admin border border-brand-line bg-brand-soft p-3.5 text-sm">
                    <div class="flex gap-2">
                        <dt class="w-32 shrink-0 font-bold">Loại:</dt>
                        <dd>{{ $detailAlert->typeLabel() }}</dd>
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
                    @can('security.update')
                    <button type="button" wire:click="markFalsePositive({{ $detailAlert->id }})"
                            class="admin-btn-secondary">Cảnh báo nhầm</button>
                    @endcan
                    @can('security.update')
                    <button type="button" wire:click="resolve({{ $detailAlert->id }})"
                            class="admin-btn">Đóng cảnh báo</button>
                    @endcan
                @else
                    <button type="button" wire:click="$set('showDetail', false)" class="admin-btn-secondary">Đóng</button>
                @endif
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
