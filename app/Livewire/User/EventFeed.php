<?php

namespace App\Livewire\User;

use App\Models\Announcement;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Trang đầu tiên của nhân viên: danh sách sự kiện.
 *
 * Sự kiện gán riêng cho chính mình luôn nằm trên đầu, rồi tới sự kiện toàn
 * công ty — thứ cần hành động không bị trôi xuống dưới.
 */
class EventFeed extends Component
{
    /** '' = tất cả, 'personal' = riêng tôi, 'upcoming' = sắp diễn ra. */
    public string $filter = '';

    public function render(): View
    {
        $events = $this->events();

        return view('livewire.user.event-feed', [
            'employee' => $this->employee(),
            'events' => $events,
            'personalCount' => $this->personalCount(),
        ])->layout('layouts.user', ['title' => 'Sự kiện']);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    private function employee(): ?Employee
    {
        return auth()->user()?->employee;
    }

    private function events(): Collection
    {
        $employee = $this->employee();

        return Announcement::query()
            ->with('employee:id,full_name')
            ->visible()
            ->forEmployee($employee)
            ->when($this->filter === 'personal', fn ($q) => $q->whereNotNull('employee_id'))
            ->when($this->filter === 'upcoming', fn ($q) => $q->where('starts_at', '>', now()))
            ->orderedForEmployee()
            ->limit(50)
            ->get();
    }

    /** Số sự kiện dành riêng cho tôi — dùng cho nhãn đếm ở bộ lọc. */
    private function personalCount(): int
    {
        $employee = $this->employee();

        if (! $employee) {
            return 0;
        }

        return Announcement::visible()
            ->where('employee_id', $employee->id)
            ->count();
    }
}
