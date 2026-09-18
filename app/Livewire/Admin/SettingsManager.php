<?php

namespace App\Livewire\Admin;

use App\Models\ContractType;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\JobGrade;
use App\Models\JobTitle;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cấu hình chung (spec 3.1.5): danh mục dùng chung + tham số hệ thống.
 *
 * Gom mọi danh mục vào một màn hình có tab thay vì tách 5 màn hình riêng —
 * đây đều là bảng tra cứu ngắn, admin thường sửa theo cụm khi thiết lập hệ thống.
 */
class SettingsManager extends Component
{
    /** Cấu hình từng loại danh mục: model, nhãn và các cột hiển thị. */
    private const CATALOGS = [
        'departments' => [
            'model' => Department::class,
            'label' => 'Phòng ban',
            'has_parent' => true,
        ],
        'job_titles' => [
            'model' => JobTitle::class,
            'label' => 'Chức danh',
            'has_department' => true,
        ],
        'job_grades' => [
            'model' => JobGrade::class,
            'label' => 'Cấp bậc',
            'has_level' => true,
        ],
        'contract_types' => [
            'model' => ContractType::class,
            'label' => 'Loại hợp đồng',
            'has_duration' => true,
        ],
        'document_categories' => [
            'model' => DocumentCategory::class,
            'label' => 'Danh mục tài liệu',
            'has_parent' => true,
        ],
    ];

    #[Url]
    public string $tab = 'departments';

    public bool $showModal = false;
    public ?int $editingId = null;

    public string $code = '';
    public string $name = '';
    public ?int $parent_id = null;
    public ?int $department_id = null;
    public ?int $level = null;
    public ?int $default_duration_months = null;
    public bool $is_active = true;

    /** Tham số hệ thống dạng key-value. */
    public array $settings = [];

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function render(): View
    {
        return view('livewire.admin.settings-manager', [
            'catalogs' => collect(self::CATALOGS)->map(fn ($c) => $c['label']),
            'config' => self::CATALOGS[$this->tab],
            'items' => $this->items(),
            'departments' => Department::active()->orderBy('name')->get(),
            'parents' => $this->parentOptions(),
        ])->layout('layouts.admin', ['title' => 'Cấu hình chung']);
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::CATALOGS)) {
            $this->tab = $tab;
            $this->resetForm();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $item = $this->modelClass()::findOrFail($id);

        $this->editingId = $item->id;
        $this->code = (string) $item->code;
        $this->name = (string) $item->name;
        $this->parent_id = $item->parent_id ?? null;
        $this->department_id = $item->department_id ?? null;
        $this->level = $item->level ?? null;
        $this->default_duration_months = $item->default_duration_months ?? null;
        $this->is_active = (bool) ($item->is_active ?? true);

        $this->showModal = true;
    }

    public function save(): void
    {
        $config = self::CATALOGS[$this->tab];
        $data = $this->validate($this->rules());

        $payload = [
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $this->is_active,
        ];

        if ($config['has_parent'] ?? false) {
            $payload['parent_id'] = $data['parent_id'];
        }

        if ($config['has_department'] ?? false) {
            $payload['department_id'] = $data['department_id'];
        }

        if ($config['has_level'] ?? false) {
            $payload['level'] = $data['level'] ?? 0;
        }

        if ($config['has_duration'] ?? false) {
            $payload['default_duration_months'] = $data['default_duration_months'];
        }

        if ($this->editingId) {
            $this->modelClass()::findOrFail($this->editingId)->update($payload);
            session()->flash('status', 'Đã cập nhật danh mục.');
        } else {
            $this->modelClass()::create($payload);
            session()->flash('status', 'Đã thêm danh mục.');
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $item = $this->modelClass()::findOrFail($id);
        $item->update(['is_active' => ! $item->is_active]);
    }

    public function saveSettings(): void
    {
        $this->validate([
            'settings.contract_alert_days' => ['required', 'integer', 'min:1', 'max:365'],
            'settings.max_devices' => ['required', 'integer', 'min:1', 'max:10'],
            'settings.display_name' => ['required', 'string', 'max:100'],
            'settings.company_name' => ['required', 'string', 'max:150'],
            'settings.company_intro' => ['nullable', 'string', 'max:2000'],
            'settings.company_values' => ['nullable', 'string', 'max:2000'],
            'settings.onboarding_process' => ['nullable', 'string', 'max:3000'],
            'settings.guide_note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'settings.contract_alert_days' => 'số ngày cảnh báo hợp đồng',
            'settings.max_devices' => 'số thiết bị tối đa',
            'settings.display_name' => 'tên hiển thị',
            'settings.company_name' => 'tên công ty',
            'settings.company_intro' => 'giới thiệu công ty',
            'settings.company_values' => 'giá trị cốt lõi',
            'settings.onboarding_process' => 'quy trình onboarding',
        ]);

        Setting::set('app.display_name', $this->settings['display_name'], 'string', 'general');
        Setting::set('contract.alert_before_days', $this->settings['contract_alert_days'], 'integer', 'contract');
        Setting::set('security.max_concurrent_devices', $this->settings['max_devices'], 'integer', 'security');
        Setting::set('security.watermark_enabled', $this->settings['watermark'] ? '1' : '0', 'boolean', 'security');

        // Nội dung hiển thị ở màn chào mừng nhân viên mới (spec 4.4)
        Setting::set('company.name', $this->settings['company_name'], 'string', 'company');
        Setting::set('company.intro', $this->settings['company_intro'], 'string', 'company');
        Setting::set('company.values', $this->settings['company_values'], 'string', 'company');
        Setting::set('company.onboarding_process', $this->settings['onboarding_process'], 'string', 'company');

        // Tài liệu hướng dẫn sử dụng
        Setting::set('guide.enabled', $this->settings['guide_enabled'] ? '1' : '0', 'boolean', 'guide');
        Setting::set('guide.watermark', $this->settings['guide_watermark'] ? '1' : '0', 'boolean', 'guide');
        Setting::set('guide.note', $this->settings['guide_note'], 'string', 'guide');

        session()->flash('status', 'Đã lưu cấu hình hệ thống.');
    }

    private function loadSettings(): void
    {
        $this->settings = [
            'display_name' => Setting::get('app.display_name', config('app.name')),
            'contract_alert_days' => Setting::get('contract.alert_before_days', 30),
            'max_devices' => Setting::get('security.max_concurrent_devices', 2),
            'watermark' => (bool) Setting::get('security.watermark_enabled', true),

            'company_name' => Setting::get('company.name', 'Techcombank'),
            'company_intro' => Setting::get('company.intro', ''),
            'company_values' => Setting::get('company.values', ''),
            'onboarding_process' => Setting::get('company.onboarding_process', ''),

            'guide_enabled' => (bool) Setting::get('guide.enabled', true),
            'guide_watermark' => (bool) Setting::get('guide.watermark', true),
            'guide_note' => Setting::get('guide.note', ''),
        ];
    }

    /** @return class-string<Model> */
    private function modelClass(): string
    {
        return self::CATALOGS[$this->tab]['model'];
    }

    private function items()
    {
        $query = $this->modelClass()::query();

        if (self::CATALOGS[$this->tab]['has_parent'] ?? false) {
            $query->with('parent');
        }

        if (self::CATALOGS[$this->tab]['has_department'] ?? false) {
            $query->with('department');
        }

        if (self::CATALOGS[$this->tab]['has_level'] ?? false) {
            return $query->orderBy('level')->get();
        }

        return $query->orderBy('name')->get();
    }

    private function parentOptions()
    {
        if (! (self::CATALOGS[$this->tab]['has_parent'] ?? false)) {
            return collect();
        }

        // Loại chính bản ghi đang sửa khỏi danh sách cha, tránh tạo vòng lặp trong cây
        return $this->modelClass()::query()
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->orderBy('name')
            ->get();
    }

    private function rules(): array
    {
        $config = self::CATALOGS[$this->tab];
        $table = (new ($config['model']))->getTable();

        $rules = [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique($table, 'code')->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];

        if ($config['has_parent'] ?? false) {
            $rules['parent_id'] = ['nullable', Rule::exists($table, 'id'), Rule::notIn([$this->editingId])];
        }

        if ($config['has_department'] ?? false) {
            $rules['department_id'] = ['nullable', Rule::exists('departments', 'id')];
        }

        if ($config['has_level'] ?? false) {
            $rules['level'] = ['required', 'integer', 'min:0', 'max:100'];
        }

        if ($config['has_duration'] ?? false) {
            $rules['default_duration_months'] = ['nullable', 'integer', 'min:1', 'max:120'];
        }

        return $rules;
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'parent_id',
            'department_id', 'level', 'default_duration_months',
        ]);
        $this->is_active = true;
        $this->resetValidation();
    }
}
