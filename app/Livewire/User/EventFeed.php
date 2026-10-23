<?php

namespace App\Livewire\User;

use App\Models\Announcement;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Trang đầu tiên của nhân viên: danh sách sự kiện.
 *
 * Sự kiện gán riêng cho chính mình luôn nằm trên đầu, rồi tới sự kiện toàn
 * công ty — thứ cần hành động không bị trôi xuống dưới.
 *
 * Trạng thái đã đọc (chuông thông báo): mở trang KHÔNG đánh dấu tất cả — đây là
 * trang mặc định sau đăng nhập, làm vậy thì chuông luôn về 0 trước khi kịp nhìn.
 * Chỉ đánh dấu khi bấm vào một sự kiện, hoặc khi mở đúng sự kiện từ chuông (?su-kien=ID).
 */
class EventFeed extends Component
{
    /** '' = tất cả, 'personal' = riêng tôi, 'upcoming' = sắp diễn ra. */
    public string $filter = '';

    /** Sự kiện được mở từ chuông thông báo: tô viền + cuộn tới. */
    #[Url(as: 'su-kien', except: null)]
    public ?int $focus = null;

    public function mount(): void
    {
        if ($this->focus) {
            $this->markRead($this->focus);
        }
    }

    public function render(): View
    {
        $events = $this->events();
        $employee = $this->employee();

        // Chấm "Mới" theo đúng quy tắc của chuông (không tính thông báo cũ hơn tài khoản)
        $unreadIds = $employee && $events->isNotEmpty()
            ? Announcement::query()->unreadFor($employee)->whereIn('id', $events->pluck('id'))->pluck('id')->flip()
            : collect();

        return view('livewire.user.event-feed', [
            'employee' => $employee,
            'events' => $events,
            'unreadIds' => $unreadIds,
            'personalCount' => $this->personalCount(),
        ])->layout('layouts.user', ['title' => 'Sự kiện']);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    /** Bấm vào một sự kiện = đã xem. Chỉ nhận sự kiện nhân viên được thấy. */
    public function markRead(int $id): void
    {
        $employee = $this->employee();
        $announcement = $employee
            ? Announcement::query()->notifiableFor($employee)->find($id)
            : null;

        if ($announcement) {
            $announcement->markReadBy($employee);
            $this->dispatch('notifications-updated');
        }
    }

    private function employee(): ?Employee
    {
        return auth()->user()?->employee;
    }

    private function events(): Collection
    {
        $employee = $this->employee();

        return Announcement::query()
            ->with(['employee:id,full_name', 'course:id,slug'])
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
