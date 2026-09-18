<?php

namespace App\Livewire\Auth;

use App\Enums\RoleName;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\SecurityMonitorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Đăng nhập bằng auth nội bộ Laravel (spec 7.5).
 *
 * Lớp này cố tình mỏng và tách khỏi nghiệp vụ: khi Techcombank cấp thông tin IdP,
 * chỉ cần thêm driver SSO ghi vào users.sso_provider/sso_identifier mà không phải
 * sửa gì ở tầng phân quyền hay tiến độ học tập.
 */
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function render(): View
    {
        return view('livewire.auth.login')->layout('layouts.guest');
    }

    public function login(): void
    {
        $this->validate();

        // Chặn dò mật khẩu: 5 lần thất bại cho mỗi cặp email+IP (spec 3.3.2)
        $throttleKey = Str::transliterate(Str::lower($this->email) . '|' . request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Bạn đã thử quá nhiều lần. Vui lòng đợi '
                    . RateLimiter::availableIn($throttleKey) . ' giây.',
            ]);
        }

        $user = User::where('email', $this->email)->first();

        // Tài khoản bị khoá/vô hiệu không được vào, kể cả khi mật khẩu đúng
        if ($user && ! $user->canLogin()) {
            $this->recordAttempt($user, 'blocked', 'Tài khoản đã bị khóa');

            throw ValidationException::withMessages([
                'email' => 'Tài khoản đã bị khóa. Vui lòng liên hệ quản trị viên.',
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($throttleKey);
            $this->recordAttempt($user, 'failed', 'Sai thông tin đăng nhập');

            throw ValidationException::withMessages([
                'email' => 'Email hoặc mật khẩu không đúng.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        $authenticated = Auth::user();
        $authenticated->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->save();

        $this->recordAttempt($authenticated, 'success');

        // Ghi phiên thiết bị và áp giới hạn số máy đăng nhập đồng thời (spec 3.3.2).
        // Gọi sau recordAttempt để detectUnusualIp có lịch sử đăng nhập mà so sánh.
        $monitor = app(SecurityMonitorService::class);
        $monitor->registerDevice($authenticated, session()->getId());
        $monitor->detectUnusualIp($authenticated, request()->ip());

        $this->redirectIntended($this->homeFor($authenticated), navigate: true);
    }

    /** Admin và nhân viên vào hai site khác nhau (spec mục 1). */
    private function homeFor(User $user): string
    {
        return $user->hasAnyRole(RoleName::adminRoles())
            ? route('admin.dashboard')
            : route('learn.events');
    }

    private function recordAttempt(?User $user, string $result, ?string $reason = null): void
    {
        if (! $user) {
            return;
        }

        LoginHistory::create([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 500, ''),
            'result' => $result,
            'failure_reason' => $reason,
            'logged_in_at' => now(),
        ]);
    }
}
