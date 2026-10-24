<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class Profile extends Component
{
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $employee = $user?->employee;

        $this->name = $employee?->full_name ?? (string) $user?->name;
        $this->email = (string) $user?->email;
        $this->phone = (string) $employee?->phone;
    }

    public function render(): View
    {
        return view('livewire.admin.profile', [
            'user' => auth()->user(),
            'employee' => auth()->user()?->employee,
        ])->layout('layouts.admin', ['title' => 'Thông tin cá nhân']);
    }

    public function saveProfile(): void
    {
        $user = auth()->user();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
        ], [
            'name.required' => 'Hãy nhập họ tên.',
            'email.required' => 'Hãy nhập email.',
            'email.email' => 'Email chưa đúng định dạng.',
            'email.unique' => 'Email này đã được tài khoản khác sử dụng.',
        ]);

        DB::transaction(function () use ($user, $data): void {
            $user->update(['name' => $data['name'], 'email' => $data['email']]);

            $user->employee?->update([
                'full_name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?: null,
            ]);
        });

        session()->flash('status', 'Đã cập nhật thông tin cá nhân.');
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
            'must_change_password' => false,
        ])->save();

        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        session()->flash('status', 'Đã đổi mật khẩu.');
    }
}
