<?php

namespace App\Livewire\Admin;

use App\Models\DeviceSession;
use App\Models\DocumentAccessLog;
use App\Models\LoginHistory;
use App\Models\SecurityAlert;
use App\Models\User;
use App\Services\SecurityMonitorService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Trung tâm bảo mật (spec 3.3.2): nhật ký truy cập tài liệu, lịch sử đăng nhập,
 * phiên thiết bị đang hoạt động và cảnh báo bất thường.
 *
 * Gộp bốn nguồn vào một màn hình có tab vì khi điều tra một sự cố, người xử lý
 * cần đối chiếu chéo cả bốn — tách màn hình sẽ bắt họ nhảy qua lại và mất mạch.
 */
class SecurityCenter extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'alerts';

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $actionFilter = '';

    /** Số ngày dữ liệu hiển thị, giới hạn để bảng log không phình vô hạn. */
    #[Url]
    public int $days = 7;

    public bool $showDetail = false;
    public ?int $detailAlertId = null;

    public function render(): View
    {
        return view('livewire.admin.security-center', [
            'rows' => $this->rows(),
            'counters' => $this->counters(),
            'detailAlert' => $this->detailAlert(),
        ])->layout('layouts.admin', ['title' => 'Trung tâm bảo mật']);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'actionFilter', 'days', 'tab'], true)) {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['alerts', 'document-logs', 'logins', 'devices'], true)) {
            $this->tab = $tab;
            $this->reset(['search', 'statusFilter', 'actionFilter']);
            $this->resetPage();
        }
    }

    // ---- Xử lý cảnh báo -----------------------------------------------------

    public function viewAlert(int $id): void
    {
        $this->detailAlertId = $id;
        $this->showDetail = true;
    }

    public function acknowledge(int $id, SecurityMonitorService $service): void
    {
        $service->acknowledge(SecurityAlert::findOrFail($id), auth()->id());
        session()->flash('status', 'Đã ghi nhận cảnh báo.');
    }

    public function resolve(int $id, SecurityMonitorService $service): void
    {
        $service->resolve(SecurityAlert::findOrFail($id), auth()->id());
        session()->flash('status', 'Đã đóng cảnh báo.');
        $this->showDetail = false;
    }

    public function markFalsePositive(int $id, SecurityMonitorService $service): void
    {
        $service->resolve(SecurityAlert::findOrFail($id), auth()->id(), falsePositive: true);
        session()->flash('status', 'Đã đánh dấu là cảnh báo nhầm.');
        $this->showDetail = false;
    }

    /** Chạy quét thủ công, phục vụ lúc chưa bật scheduler. */
    public function runScan(SecurityMonitorService $service): void
    {
        $mass = $service->detectMassDownload();
        $brute = $service->detectBruteForce();

        session()->flash('status', "Quét xong: {$mass} cảnh báo truy cập hàng loạt, {$brute} cảnh báo dò mật khẩu.");
    }

    // ---- Thu hồi phiên ------------------------------------------------------

    public function revokeSession(int $id): void
    {
        DeviceSession::findOrFail($id)->revoke();
        session()->flash('status', 'Đã thu hồi phiên đăng nhập.');
    }

    public function revokeAllForUser(int $userId, SecurityMonitorService $service): void
    {
        $count = $service->revokeAllSessions(User::findOrFail($userId));
        session()->flash('status', "Đã thu hồi {$count} phiên của tài khoản này.");
    }

    // ---- Truy vấn theo tab --------------------------------------------------

    private function rows()
    {
        return match ($this->tab) {
            'document-logs' => $this->documentLogs(),
            'logins' => $this->loginHistories(),
            'devices' => $this->deviceSessions(),
            default => $this->alerts(),
        };
    }

    private function alerts()
    {
        return SecurityAlert::query()
            ->with(['user.employee', 'handledBy'])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';
                $query->where('title', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            // Cảnh báo chưa xử lý và mức độ nặng lên đầu
            ->orderByRaw("FIELD(status, 'open', 'acknowledged', 'resolved', 'false_positive')")
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    private function documentLogs()
    {
        return DocumentAccessLog::query()
            ->with(['document', 'employee.department'])
            ->where('accessed_at', '>=', now()->subDays($this->days))
            ->when($this->actionFilter, fn ($q) => $q->where('action', $this->actionFilter))
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';
                $query->whereHas('document', fn ($d) => $d->where('title', 'like', $term))
                    ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', $term)
                        ->orWhere('employee_code', 'like', $term));
            })
            ->orderByDesc('accessed_at')
            ->paginate(20);
    }

    private function loginHistories()
    {
        return LoginHistory::query()
            ->with('user.employee')
            ->where('logged_in_at', '>=', now()->subDays($this->days))
            ->when($this->statusFilter, fn ($q) => $q->where('result', $this->statusFilter))
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';
                $query->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term))
                    ->orWhere('ip_address', 'like', $term);
            })
            ->orderByDesc('logged_in_at')
            ->paginate(20);
    }

    private function deviceSessions()
    {
        return DeviceSession::query()
            ->with('user.employee')
            ->when($this->statusFilter === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->statusFilter === 'revoked', fn ($q) => $q->where('is_active', false))
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';
                $query->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term))
                    ->orWhere('ip_address', 'like', $term);
            })
            ->orderByDesc('is_active')
            ->orderByDesc('last_activity_at')
            ->paginate(20);
    }

    private function detailAlert(): ?SecurityAlert
    {
        return $this->detailAlertId
            ? SecurityAlert::with(['user.employee', 'handledBy'])->find($this->detailAlertId)
            : null;
    }

    /** @return array<string, int> */
    private function counters(): array
    {
        return [
            'open_alerts' => SecurityAlert::open()->count(),
            'critical' => SecurityAlert::open()->where('severity', 'critical')->count(),
            'active_devices' => DeviceSession::where('is_active', true)->count(),
            'denied_access' => DocumentAccessLog::where('action', DocumentAccessLog::ACTION_DENIED)
                ->where('accessed_at', '>=', now()->subDays($this->days))
                ->count(),
        ];
    }
}
