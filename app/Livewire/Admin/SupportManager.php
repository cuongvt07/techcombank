<?php

namespace App\Livewire\Admin;

use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Xử lý phiếu hỗ trợ nhân viên gửi lên (spec 4.5).
 *
 * Luồng trạng thái: new -> in_progress -> resolved -> closed.
 * Phiếu đã giải quyết vẫn mở lại được nếu nhân viên phản hồi chưa ổn.
 */
class SupportManager extends Component
{
    use WithPagination;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    /** Mặc định chỉ hiện phiếu còn mở: việc cần xử lý nằm trên đầu. */
    #[Url]
    public string $statusFilter = 'open';

    #[Url]
    public string $priorityFilter = '';

    public bool $showModal = false;
    public ?int $viewingId = null;

    public string $resolution = '';
    public ?int $assigned_to = null;

    public function render(): View
    {
        return view('livewire.admin.support-manager', [
            'requests' => $this->requests(),
            'stats' => $this->stats(),
            'viewing' => $this->viewingId ? SupportRequest::with(['employee.department', 'assignedTo'])->find($this->viewingId) : null,
            'admins' => User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.admin', ['title' => 'Phiếu hỗ trợ']);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'priorityFilter'], true)) {
            $this->resetPage();
        }
    }

    private function requests()
    {
        return SupportRequest::query()
            ->with(['employee:id,full_name,employee_code,department_id,avatar_file_id', 'employee.department:id,name', 'assignedTo:id,name'])
            ->when($this->statusFilter === 'open', fn ($q) => $q->open())
            ->when(! in_array($this->statusFilter, ['', 'open'], true), fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->priorityFilter !== '', fn ($q) => $q->where('priority', $this->priorityFilter))
            ->when($this->search !== '', function ($q) {
                $term = '%' . $this->search . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('ticket_no', 'like', $term)
                        ->orWhere('subject', 'like', $term)
                        ->orWhere('topic', 'like', $term)
                        ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', $term)
                            ->orWhere('employee_code', 'like', $term));
                });
            })
            // Khẩn lên trước, rồi phiếu cũ nhất: phiếu chờ lâu nhất không bị bỏ quên
            ->orderByRaw("FIELD(priority, 'high', 'normal', 'low')")
            ->orderBy('created_at')
            ->paginate(15);
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        return [
            'new' => SupportRequest::where('status', SupportRequest::STATUS_NEW)->count(),
            'in_progress' => SupportRequest::where('status', SupportRequest::STATUS_IN_PROGRESS)->count(),
            'resolved' => SupportRequest::where('status', SupportRequest::STATUS_RESOLVED)->count(),
            'high' => SupportRequest::open()->where('priority', 'high')->count(),
        ];
    }

    public function view(int $id): void
    {
        $request = SupportRequest::findOrFail($id);

        $this->viewingId = $request->id;
        $this->resolution = (string) $request->resolution;
        $this->assigned_to = $request->assigned_to;
        $this->resetValidation();
        $this->showModal = true;
    }

    /** Nhận xử lý: gán cho chính mình và chuyển sang đang xử lý. */
    public function take(int $id): void
    {
        $request = SupportRequest::findOrFail($id);

        if ($request->status === SupportRequest::STATUS_NEW) {
            $request->update([
                'assigned_to' => auth()->id(),
                'status' => SupportRequest::STATUS_IN_PROGRESS,
            ]);

            session()->flash('status', "Đã nhận xử lý phiếu {$request->ticket_no}.");
        }
    }

    public function assign(): void
    {
        $request = SupportRequest::findOrFail($this->viewingId);

        $request->update([
            'assigned_to' => $this->assigned_to ?: null,
            // Gán người xử lý cho phiếu mới thì phiếu coi như đã được tiếp nhận
            'status' => $request->status === SupportRequest::STATUS_NEW && $this->assigned_to
                ? SupportRequest::STATUS_IN_PROGRESS
                : $request->status,
        ]);

        session()->flash('status', 'Đã cập nhật người xử lý.');
    }

    /** Giải quyết phiếu: bắt buộc ghi câu trả lời để nhân viên đọc được. */
    public function resolve(): void
    {
        $this->validate([
            'resolution' => ['required', 'string', 'min:5', 'max:3000'],
        ], [
            'resolution.required' => 'Hãy nhập nội dung phản hồi cho nhân viên.',
            'resolution.min' => 'Nội dung phản hồi quá ngắn.',
        ]);

        SupportRequest::findOrFail($this->viewingId)->update([
            'status' => SupportRequest::STATUS_RESOLVED,
            'resolution' => $this->resolution,
            'resolved_at' => now(),
            'assigned_to' => $this->assigned_to ?: auth()->id(),
        ]);

        session()->flash('status', 'Đã giải quyết phiếu và gửi phản hồi.');
        $this->showModal = false;
    }

    public function close(int $id): void
    {
        SupportRequest::findOrFail($id)->update(['status' => SupportRequest::STATUS_CLOSED]);

        session()->flash('status', 'Đã đóng phiếu.');
    }

    /** Mở lại phiếu đã giải quyết/đóng khi vấn đề chưa xong. */
    public function reopen(int $id): void
    {
        SupportRequest::findOrFail($id)->update([
            'status' => SupportRequest::STATUS_IN_PROGRESS,
            'resolved_at' => null,
        ]);

        session()->flash('status', 'Đã mở lại phiếu.');
    }
}
