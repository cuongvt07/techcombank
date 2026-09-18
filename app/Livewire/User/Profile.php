<?php

namespace App\Livewire\User;

use App\Models\DeviceSession;
use App\Models\Employee;
use App\Models\LoginHistory;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use App\Services\FileStorageService;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Trang cá nhân (spec 4.2): thông tin cơ bản, đổi mật khẩu,
 * và danh sách thiết bị đang đăng nhập để tự thu hồi khi cần (spec 3.3.2).
 */
class Profile extends Component
{
    use WithFileUploads;

    /** Ảnh đại diện vừa chọn, chưa lưu. */
    public $avatar = null;

    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';

    public function render(): View
    {
        $employee = $this->employee();

        return view('livewire.user.profile', [
            'employee' => $employee,
            'user' => auth()->user(),
            'sessions' => $this->sessions(),
            'recentLogins' => $this->recentLogins(),
        ])->layout('layouts.user', ['title' => 'Thông tin cá nhân']);
    }

    /**
     * Lưu ảnh đại diện.
     *
     * Ảnh đi vào kho tài nguyên chung như mọi file khác, nhân viên chỉ giữ tham
     * chiếu. Ảnh cũ không xóa để còn khôi phục được từ thùng rác.
     */
    public function saveAvatar(FileStorageService $storage): void
    {
        $this->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . FileStorageService::MAX_FILE_KB],
        ], [
            'avatar.image' => 'Tệp tải lên phải là ảnh.',
            'avatar.mimes' => 'Chỉ nhận ảnh JPG, PNG hoặc WEBP.',
            'avatar.max' => 'Ảnh không được vượt quá 2MB.',
        ]);

        $employee = auth()->user()?->employee;

        if (! $employee) {
            $this->addError('avatar', 'Tài khoản của bạn chưa gắn với hồ sơ nhân viên.');

            return;
        }

        $file = $storage->storeForPurpose($this->avatar, FileStorageService::PURPOSE_AVATAR, auth()->id());
        $employee->update(['avatar_file_id' => $file->id]);

        $this->avatar = null;

        session()->flash('status', 'Đã cập nhật ảnh đại diện.');
    }

    /** Gỡ ảnh, quay về avatar chữ cái. */
    public function removeAvatar(): void
    {
        auth()->user()?->employee?->update(['avatar_file_id' => null]);

        session()->flash('status', 'Đã gỡ ảnh đại diện.');
    }

    public function changePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required'],
            'newPassword' => ['required', 'string', 'min:8', 'different:currentPassword'],
            'newPasswordConfirmation' => ['required', 'same:newPassword'],
        ], [
            'currentPassword.required' => 'Hãy nhập mật khẩu hiện tại.',
            'newPassword.min' => 'Mật khẩu mới cần ít nhất 8 ký tự.',
            'newPassword.different' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
            'newPasswordConfirmation.same' => 'Xác nhận mật khẩu không khớp.',
        ]);

        $user = auth()->user();

        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', 'Mật khẩu hiện tại không đúng.');

            return;
        }

        $user->forceFill([
            'password' => $this->newPassword,
            // Đổi mật khẩu xong thì gỡ cờ bắt buộc đổi ở lần đăng nhập đầu
            'must_change_password' => false,
        ])->save();

        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);

        session()->flash('status', 'Đã đổi mật khẩu.');
    }

    /** Người dùng tự thu hồi phiên trên máy lạ mà không cần gọi IT. */
    public function revokeSession(int $id): void
    {
        $session = DeviceSession::where('user_id', auth()->id())->find($id);

        if (! $session) {
            return;
        }

        $session->revoke();
        session()->flash('status', 'Đã thu hồi phiên đăng nhập.');
    }

    private function employee(): ?Employee
    {
        return auth()->user()?->employee;
    }

    private function sessions()
    {
        return DeviceSession::where('user_id', auth()->id())
            ->where('is_active', true)
            ->orderByDesc('last_activity_at')
            ->get();
    }

    private function recentLogins()
    {
        return LoginHistory::where('user_id', auth()->id())
            ->orderByDesc('logged_in_at')
            ->limit(10)
            ->get();
    }
}
