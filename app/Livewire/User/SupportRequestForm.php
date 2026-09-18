<?php

namespace App\Livewire\User;

use App\Models\Employee;
use App\Models\SupportContact;
use App\Models\SupportRequest;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Liên hệ tư vấn / hỗ trợ (spec 4.5): danh sách đầu mối theo phòng ban
 * và form gửi yêu cầu có theo dõi trạng thái xử lý.
 */
class SupportRequestForm extends Component
{
    public string $topic = '';
    public string $subject = '';
    public string $content = '';
    public string $priority = 'normal';

    public function render(): View
    {
        $employee = $this->employee();

        return view('livewire.user.support-request-form', [
            'contacts' => $this->contacts($employee),
            'requests' => $this->myRequests($employee),
        ])->layout('layouts.user', ['title' => 'Hỗ trợ']);
    }

    public function submit(): void
    {
        $employee = $this->employee();

        if (! $employee) {
            session()->flash('error', 'Tài khoản chưa gắn hồ sơ nhân sự.');

            return;
        }

        $data = $this->validate([
            'topic' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:5000'],
            'priority' => ['required', 'in:low,normal,high'],
        ], [
            'topic.required' => 'Hãy chọn chủ đề cần hỗ trợ.',
            'subject.required' => 'Hãy nhập tiêu đề yêu cầu.',
            'content.required' => 'Hãy mô tả vấn đề bạn gặp phải.',
        ]);

        SupportRequest::create([
            'ticket_no' => $this->generateTicketNo(),
            'employee_id' => $employee->id,
            'topic' => $data['topic'],
            'subject' => $data['subject'],
            'content' => $data['content'],
            'priority' => $data['priority'],
            'status' => SupportRequest::STATUS_NEW,
        ]);

        session()->flash('status', 'Đã gửi yêu cầu hỗ trợ. Bộ phận phụ trách sẽ liên hệ với bạn.');

        $this->reset(['topic', 'subject', 'content']);
        $this->priority = 'normal';
    }

    private function employee(): ?Employee
    {
        return auth()->user()?->employee;
    }

    /** Đầu mối của phòng ban nhân viên + đầu mối chung toàn công ty (spec 4.5). */
    private function contacts(?Employee $employee)
    {
        if (! $employee) {
            return collect();
        }

        return SupportContact::active()
            ->forEmployee($employee)
            ->orderBy('sort_order')
            ->get();
    }

    private function myRequests(?Employee $employee)
    {
        if (! $employee) {
            return collect();
        }

        return SupportRequest::where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * Mã phiếu theo ngày + số thứ tự trong ngày, dễ đọc khi trao đổi qua điện thoại.
     * Dùng count trong ngày thay vì id toàn cục để mã không lộ tổng số phiếu hệ thống.
     */
    private function generateTicketNo(): string
    {
        $prefix = 'HT-' . now()->format('ymd');
        $todayCount = SupportRequest::whereDate('created_at', now()->toDateString())->count();

        return sprintf('%s-%03d', $prefix, $todayCount + 1);
    }
}
