<?php

namespace App\Livewire\User;

use App\Services\NotificationCenter;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Chuông thông báo — dùng chung cho header site nhân viên và thanh trên site quản trị.
 *
 * Lời nhắc học admin gửi từ báo cáo, phiếu hỗ trợ được nhận/giao, lượt làm bài
 * được cấp thêm... đều hiện ở đây kèm số chưa đọc. Tự làm mới định kỳ
 * (wire:poll) — nhẹ: vài COUNT + 10 dòng của tab đang mở.
 */
class NotificationBell extends Component
{
    public const LIST_LIMIT = 10;

    /** user | admin — quyết định nguồn thông báo và bộ tab. Khóa để client không đổi được. */
    #[Locked]
    public string $site = NotificationCenter::SITE_USER;

    public string $tab = 'all';

    public function mount(string $site = NotificationCenter::SITE_USER): void
    {
        $this->site = $site === NotificationCenter::SITE_ADMIN ? $site : NotificationCenter::SITE_USER;
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, $this->center()->tabs($this->site)) ? $tab : 'all';
    }

    /** Bấm một thông báo: đánh dấu đã đọc rồi đi tới trang phù hợp. */
    public function open(string $key): void
    {
        $user = auth()->user();
        $url = $user ? $this->center()->open($user, $this->site, $key) : null;

        if ($url === null) {
            return;
        }

        $this->dispatch('notifications-updated');
        $this->redirect($url, navigate: $this->site === NotificationCenter::SITE_USER);
    }

    /** Đánh dấu đã đọc mọi thông báo của tab đang mở. */
    public function markAllRead(): void
    {
        if ($user = auth()->user()) {
            $this->center()->markAllRead($user, $this->site, $this->tab);
            $this->dispatch('notifications-updated');
        }
    }

    /** Trang Sự kiện đánh dấu đọc → chuông vẽ lại để số giảm ngay. */
    #[On('notifications-updated')]
    public function refreshBell(): void
    {
        // Không cần làm gì: chỉ để Livewire render lại component.
    }

    public function render(): View
    {
        $user = auth()->user();
        $center = $this->center();

        return view('livewire.user.notification-bell', [
            'enabled' => $user !== null,
            'admin' => $this->site === NotificationCenter::SITE_ADMIN,
            'tabs' => $center->tabs($this->site),
            'counts' => $user ? $center->unreadCounts($user, $this->site) : ['all' => 0],
            'items' => $user ? $center->items($user, $this->site, $this->tab, self::LIST_LIMIT) : collect(),
        ]);
    }

    private function center(): NotificationCenter
    {
        return app(NotificationCenter::class);
    }
}
