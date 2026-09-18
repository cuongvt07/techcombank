<div>
    <x-admin.page-header title="Cấu hình chung"
                         subtitle="Danh mục dùng chung và tham số vận hành của hệ thống.">
        <x-slot:actions>
            <button type="button" wire:click="create" class="admin-btn">
                @svg('heroicon-o-plus', 'h-4 w-4')
                Thêm mục
            </button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px] xl:items-start">
        <div class="admin-panel min-w-0">
            {{-- Tab danh mục: các bảng tra cứu ngắn, admin thường sửa theo cụm --}}
            <div class="flex flex-wrap gap-1 border-b border-brand-line p-2">
                @foreach ($catalogs as $key => $label)
                    <button type="button"
                            wire:click="setTab('{{ $key }}')"
                            @class([
                                'rounded-admin px-3 py-2 text-[13px] font-bold transition-colors',
                                'bg-brand-red text-white' => $tab === $key,
                                'text-brand-ink hover:bg-brand-soft' => $tab !== $key,
                            ])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="overflow-x-auto">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="w-32">Mã</th>
                            <th>Tên</th>
                            @if ($config['has_parent'] ?? false)
                                <th>Thuộc</th>
                            @endif
                            @if ($config['has_department'] ?? false)
                                <th>Phòng ban</th>
                            @endif
                            @if ($config['has_level'] ?? false)
                                <th class="w-24">Cấp độ</th>
                            @endif
                            @if ($config['has_duration'] ?? false)
                                <th class="w-32">Thời hạn</th>
                            @endif
                            <th class="w-28">Trạng thái</th>
                            <th class="w-24 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr wire:key="{{ $tab }}-{{ $item->id }}">
                                <td class="font-mono text-xs font-bold">{{ $item->code }}</td>
                                <td class="font-bold">{{ $item->name }}</td>

                                @if ($config['has_parent'] ?? false)
                                    <td class="text-brand-muted">{{ $item->parent?->name ?? '—' }}</td>
                                @endif
                                @if ($config['has_department'] ?? false)
                                    <td class="text-brand-muted">{{ $item->department?->name ?? '—' }}</td>
                                @endif
                                @if ($config['has_level'] ?? false)
                                    <td><span class="tag">{{ $item->level }}</span></td>
                                @endif
                                @if ($config['has_duration'] ?? false)
                                    <td class="text-brand-muted">
                                        {{ $item->default_duration_months ? $item->default_duration_months . ' tháng' : 'Không xác định' }}
                                    </td>
                                @endif

                                <td>
                                    <span class="tag {{ $item->is_active ? 'tag-green' : '' }}">
                                        {{ $item->is_active ? 'Đang dùng' : 'Ngưng dùng' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa danh mục"
                                                             wire:click="edit({{ $item->id }})" />
                                        <x-admin.icon-button
                                            :icon="$item->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle'"
                                            :label="$item->is_active ? 'Ngưng sử dụng' : 'Bật lại'"
                                            wire:click="toggleActive({{ $item->id }})" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-brand-muted">Chưa có mục nào trong danh mục này.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Tham số hệ thống: ít trường, để cạnh bảng thay vì tab riêng --}}
        <div class="admin-panel">
            <div class="admin-panel-head">
                <h3>Tham số hệ thống</h3>
            </div>

            <form wire:submit="saveSettings" class="grid gap-4 p-4">
                <div>
                    <label for="s_name" class="admin-label">Tên hiển thị hệ thống</label>
                    <input id="s_name" type="text" wire:model="settings.display_name" class="admin-input">
                    @error('settings.display_name') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="s_alert" class="admin-label">Cảnh báo hợp đồng trước (ngày)</label>
                    <input id="s_alert" type="number" wire:model="settings.contract_alert_days" class="admin-input">
                    <p class="mt-1 text-xs text-brand-muted">Áp dụng cho hợp đồng không đặt ngưỡng riêng.</p>
                    @error('settings.contract_alert_days') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="s_dev" class="admin-label">Số thiết bị đăng nhập đồng thời</label>
                    <input id="s_dev" type="number" wire:model="settings.max_devices" class="admin-input">
                    @error('settings.max_devices') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <label class="flex cursor-pointer items-start gap-2.5 text-sm font-medium">
                    <input type="checkbox" wire:model="settings.watermark"
                           class="mt-0.5 h-4 w-4 shrink-0 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                    <span>
                        Bật watermark động khi xem tài liệu
                        <span class="mt-0.5 block text-xs font-normal text-brand-muted">
                            Hiển thị tên, email và thời gian truy cập đè lên tài liệu để truy vết rò rỉ.
                        </span>
                    </span>
                </label>

                {{-- Nội dung màn chào mừng nhân viên mới (spec 4.4) --}}
                <div class="border-t border-brand-line pt-5">
                    <p class="mb-1 text-sm font-bold">Màn chào mừng nhân viên mới</p>
                    <p class="mb-4 text-xs text-brand-muted">
                        Nội dung hiển thị cho nhân viên đang ở trạng thái "nhân viên mới".
                    </p>

                    <div class="grid gap-4">
                        <div>
                            <label for="s_company" class="admin-label">Tên công ty</label>
                            <input id="s_company" type="text" wire:model="settings.company_name" class="admin-input">
                            @error('settings.company_name') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="s_intro" class="admin-label">Giới thiệu công ty</label>
                            <textarea id="s_intro" wire:model="settings.company_intro" rows="3" class="admin-input"
                                      placeholder="Một đoạn ngắn giới thiệu về công ty..."></textarea>
                            @error('settings.company_intro') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="s_values" class="admin-label">Giá trị cốt lõi</label>
                            <textarea id="s_values" wire:model="settings.company_values" rows="4" class="admin-input"
                                      placeholder="Mỗi giá trị một dòng. Để trống thì mục này không hiện."></textarea>
                            @error('settings.company_values') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="s_process" class="admin-label">Quy trình cần biết</label>
                            <textarea id="s_process" wire:model="settings.onboarding_process" rows="5" class="admin-input"
                                      placeholder="Các bước, thủ tục nhân viên mới cần nắm. Để trống thì mục này không hiện."></textarea>
                            @error('settings.onboarding_process') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                {{-- Tài liệu hướng dẫn sử dụng --}}
                <div class="border-t border-brand-line pt-5">
                    <p class="mb-1 text-sm font-bold">Tài liệu hướng dẫn sử dụng</p>
                    <p class="mb-4 text-xs text-brand-muted">
                        Hệ thống tự sinh tài liệu PDF theo cấu hình hiện tại. Nhân viên tải được ở mục Hỗ trợ,
                        quản trị viên tải bản dành cho quản trị.
                    </p>

                    <div class="grid gap-4">
                        <label class="flex cursor-pointer items-start gap-2.5">
                            <input type="checkbox" wire:model="settings.guide_enabled"
                                   class="mt-0.5 h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                            <span class="text-sm font-medium">
                                Cho phép tải tài liệu hướng dẫn
                                <span class="mt-0.5 block text-xs font-normal text-brand-muted">
                                    Tắt thì nút tải ẩn khỏi mục Hỗ trợ.
                                </span>
                            </span>
                        </label>

                        <label class="flex cursor-pointer items-start gap-2.5">
                            <input type="checkbox" wire:model="settings.guide_watermark"
                                   class="mt-0.5 h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                            <span class="text-sm font-medium">
                                Đóng watermark vào tài liệu tải về
                                <span class="mt-0.5 block text-xs font-normal text-brand-muted">
                                    Ghi tên, email và thời gian tải lên từng trang PDF để truy vết bản rò rỉ.
                                </span>
                            </span>
                        </label>

                        <div>
                            <label for="s_gnote" class="admin-label">Ghi chú thêm (tùy chọn)</label>
                            <textarea id="s_gnote" wire:model="settings.guide_note" rows="2" class="admin-input"
                                      placeholder="Nội dung bổ sung hiện ở cuối tài liệu hướng dẫn..."></textarea>
                            @error('settings.guide_note') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('admin.guide', 'admin') }}" class="admin-btn-secondary !min-h-[34px] !text-xs">
                                @svg('heroicon-o-arrow-down-tray', 'h-4 w-4')
                                Tải bản Quản trị
                            </a>
                            <a href="{{ route('admin.guide', 'employee') }}" class="admin-btn-secondary !min-h-[34px] !text-xs">
                                @svg('heroicon-o-arrow-down-tray', 'h-4 w-4')
                                Tải bản Nhân viên
                            </a>
                        </div>
                    </div>
                </div>

                <button type="submit" class="admin-btn">Lưu cấu hình</button>
            </form>
        </div>
    </div>

    @if ($showModal)
        <x-admin.modal :title="($editingId ? 'Sửa ' : 'Thêm ') . mb_strtolower($catalogs[$tab])">
            <form wire:submit="save" id="catalog-form" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="cat_code" class="admin-label">Mã</label>
                    <input id="cat_code" type="text" wire:model="code" class="admin-input">
                    @error('code') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="cat_name" class="admin-label">Tên</label>
                    <input id="cat_name" type="text" wire:model="name" class="admin-input">
                    @error('name') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                @if ($config['has_parent'] ?? false)
                    <div class="sm:col-span-2">
                        <label for="cat_parent" class="admin-label">Trực thuộc</label>
                        <select id="cat_parent" wire:model="parent_id" class="admin-input">
                            <option value="">— Không (cấp cao nhất) —</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                            @endforeach
                        </select>
                        @error('parent_id') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if ($config['has_department'] ?? false)
                    <div class="sm:col-span-2">
                        <label for="cat_dept" class="admin-label">Phòng ban</label>
                        <select id="cat_dept" wire:model="department_id" class="admin-input">
                            <option value="">— Không gắn phòng ban —</option>
                            @foreach ($departments as $dept)
                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($config['has_level'] ?? false)
                    <div class="sm:col-span-2">
                        <label for="cat_level" class="admin-label">Cấp độ</label>
                        <input id="cat_level" type="number" wire:model="level" class="admin-input">
                        <p class="mt-1 text-xs text-brand-muted">
                            Số càng lớn cấp càng cao. Dùng cho điều kiện phân quyền "từ cấp N trở lên".
                        </p>
                        @error('level') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if ($config['has_duration'] ?? false)
                    <div class="sm:col-span-2">
                        <label for="cat_dur" class="admin-label">Thời hạn mặc định (tháng)</label>
                        <input id="cat_dur" type="number" wire:model="default_duration_months" class="admin-input"
                               placeholder="Để trống nếu không xác định thời hạn">
                        @error('default_duration_months') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>
                @endif

                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium sm:col-span-2">
                    <input type="checkbox" wire:model="is_active"
                           class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                    Đang sử dụng
                </label>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="catalog-form" class="admin-btn">
                    {{ $editingId ? 'Lưu thay đổi' : 'Thêm mới' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
