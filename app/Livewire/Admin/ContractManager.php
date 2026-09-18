<?php

namespace App\Livewire\Admin;

use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Services\FileStorageService;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Quản lý hợp đồng lao động (spec 3.1.3): loại, thời hạn, cảnh báo sắp hết hạn
 * và lưu trữ file scan.
 */
class ContractManager extends Component
{
    use WithPagination, WithFileUploads;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    /** Lọc nhanh nhóm hợp đồng cần xử lý gấp. */
    #[Url]
    public bool $onlyExpiring = false;

    public bool $showModal = false;
    public ?int $editingId = null;

    public ?int $employee_id = null;
    public ?int $contract_type_id = null;
    public string $contract_no = '';
    public ?string $signed_at = null;
    public ?string $effective_from = null;
    public ?string $effective_to = null;
    public string $status = Contract::STATUS_DRAFT;
    public ?int $alert_before_days = null;
    public string $note = '';
    public $file = null;

    public function render(): View
    {
        return view('livewire.admin.contract-manager', [
            'contracts' => $this->contracts(),
            'contractTypes' => ContractType::where('is_active', true)->orderBy('name')->get(),
            'employees' => Employee::active()->orderBy('full_name')->limit(300)->get(),
            'expiringCount' => Contract::needsExpiryAlert()->count(),
        ])->layout('layouts.admin', ['title' => 'Quản lý hợp đồng']);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'onlyExpiring'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->effective_from = now()->toDateString();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $contract = Contract::findOrFail($id);

        $this->editingId = $contract->id;
        $this->employee_id = $contract->employee_id;
        $this->contract_type_id = $contract->contract_type_id;
        $this->contract_no = $contract->contract_no;
        $this->signed_at = $contract->signed_at?->toDateString();
        $this->effective_from = $contract->effective_from?->toDateString();
        $this->effective_to = $contract->effective_to?->toDateString();
        $this->status = $contract->status;
        $this->alert_before_days = $contract->alert_before_days;
        $this->note = (string) $contract->note;
        $this->file = null;

        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate($this->rules());

        $payload = [
            'employee_id' => $data['employee_id'],
            'contract_type_id' => $data['contract_type_id'],
            'contract_no' => $data['contract_no'],
            'signed_at' => $data['signed_at'] ?: null,
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?: null,
            'status' => $data['status'],
            'alert_before_days' => $data['alert_before_days'] ?: null,
            'note' => $data['note'] ?: null,
        ];

        if ($this->editingId) {
            $contract = Contract::findOrFail($this->editingId);
            $contract->update($payload);
            session()->flash('status', 'Đã cập nhật hợp đồng.');
        } else {
            $payload['created_by'] = auth()->id();
            $contract = Contract::create($payload);
            session()->flash('status', 'Đã tạo hợp đồng.');
        }

        // File scan vào kho tài nguyên chung, hợp đồng chỉ giữ tham chiếu.
        // Quyền xem vẫn kiểm soát ở tầng Policy (HR + quản lý trực tiếp).
        if ($this->file) {
            $storedFile = app(FileStorageService::class)->storeForPurpose(
                $this->file,
                FileStorageService::PURPOSE_CONTRACT,
                auth()->id(),
            );

            $contract->update(['stored_file_id' => $storedFile->id]);
        }

        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Đồng bộ trạng thái theo ngày hết hạn.
     * Thao tác thủ công ở đây phục vụ lúc chưa bật scheduler (spec 3.1.3).
     */
    public function refreshStatuses(): void
    {
        $expired = Contract::expired()->update(['status' => Contract::STATUS_EXPIRED]);

        $expiring = 0;
        Contract::needsExpiryAlert()
            ->where('status', Contract::STATUS_ACTIVE)
            ->each(function (Contract $contract) use (&$expiring) {
                $contract->update(['status' => Contract::STATUS_EXPIRING]);
                $expiring++;
            });

        session()->flash('status', "Đã cập nhật: {$expired} hợp đồng hết hạn, {$expiring} hợp đồng sắp hết hạn.");
    }

    private function contracts()
    {
        return Contract::query()
            ->with(['employee.department', 'contractType'])
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';

                $query->where(fn ($q) => $q
                    ->where('contract_no', 'like', $term)
                    ->orWhereHas('employee', fn ($e) => $e
                        ->where('full_name', 'like', $term)
                        ->orWhere('employee_code', 'like', $term)));
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->onlyExpiring, fn ($q) => $q->needsExpiryAlert())
            ->orderByRaw('effective_to IS NULL, effective_to')
            ->paginate(15);
    }

    private function rules(): array
    {
        return [
            'employee_id' => ['required', Rule::exists('employees', 'id')],
            'contract_type_id' => ['required', Rule::exists('contract_types', 'id')],
            'contract_no' => [
                'required', 'string', 'max:100',
                Rule::unique('contracts', 'contract_no')->ignore($this->editingId)->whereNull('deleted_at'),
            ],
            'signed_at' => ['nullable', 'date'],
            'effective_from' => ['required', 'date'],
            // Ngày hết hạn phải sau ngày hiệu lực, tránh hợp đồng âm thời hạn
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'status' => ['required', Rule::in([
                Contract::STATUS_DRAFT, Contract::STATUS_ACTIVE, Contract::STATUS_EXPIRING,
                Contract::STATUS_EXPIRED, Contract::STATUS_TERMINATED,
            ])],
            'alert_before_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'note' => ['nullable', 'string', 'max:1000'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:' . FileStorageService::MAX_FILE_KB],
        ];
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'employee_id', 'contract_type_id', 'contract_no',
            'signed_at', 'effective_from', 'effective_to', 'alert_before_days', 'note', 'file',
        ]);
        $this->status = Contract::STATUS_DRAFT;
        $this->resetValidation();
    }
}
