<?php

namespace App\Services;

use App\Models\DeviceSession;
use App\Models\DocumentAccessLog;
use App\Models\LoginHistory;
use App\Models\SecurityAlert;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Giám sát truy cập bất thường và giới hạn thiết bị (spec 3.3.2).
 *
 * Đây là biện pháp NGĂN CHẶN và TRUY VẾT, không phải chặn tuyệt đối:
 * không thể ngăn người dùng chụp màn hình hay quay lại nội dung từ phía client.
 * Giá trị nằm ở chỗ mọi lượt xem đều để lại dấu vết truy ngược được.
 */
class SecurityMonitorService
{
    /** Ngưỡng mặc định, ghi đè được trong Cấu hình chung (spec 3.1.5). */
    private const DEFAULT_MAX_DEVICES = 2;
    private const DEFAULT_MASS_DOWNLOAD_THRESHOLD = 20;
    private const DEFAULT_FAILED_LOGIN_THRESHOLD = 5;

    /**
     * Ghi nhận một phiên đăng nhập trên thiết bị và thu hồi phiên cũ nếu vượt giới hạn.
     *
     * @return DeviceSession Phiên vừa tạo/cập nhật
     */
    public function registerDevice(User $user, string $sessionId, ?string $fingerprint = null): DeviceSession
    {
        $session = DeviceSession::updateOrCreate(
            ['user_id' => $user->id, 'session_id' => $sessionId],
            [
                'device_fingerprint' => $fingerprint,
                'device_label' => $this->deviceLabel(request()->userAgent()),
                'ip_address' => request()->ip(),
                'last_activity_at' => now(),
                'is_active' => true,
                'revoked_at' => null,
            ]
        );

        $this->enforceDeviceLimit($user, $session);

        return $session;
    }

    /**
     * Giữ số thiết bị hoạt động trong giới hạn: thu hồi phiên cũ nhất khi vượt.
     * Thu hồi phiên cũ thay vì chặn phiên mới — người dùng đổi máy vẫn vào được,
     * còn phiên bỏ quên trên máy khác thì bị đẩy ra.
     *
     * @return int Số phiên bị thu hồi
     */
    public function enforceDeviceLimit(User $user, ?DeviceSession $keep = null): int
    {
        $limit = (int) Setting::get('security.max_concurrent_devices', self::DEFAULT_MAX_DEVICES);

        $active = DeviceSession::where('user_id', $user->id)
            ->where('is_active', true)
            ->when($keep, fn ($q) => $q->where('id', '!=', $keep->id))
            ->orderByDesc('last_activity_at')
            ->get();

        // Phiên đang giữ lại chiếm một suất trong giới hạn
        $allowed = max(0, $limit - ($keep ? 1 : 0));
        $excess = $active->slice($allowed);

        if ($excess->isEmpty()) {
            return 0;
        }

        DeviceSession::whereIn('id', $excess->pluck('id'))->update([
            'is_active' => false,
            'revoked_at' => now(),
        ]);

        $this->raise(
            $user,
            SecurityAlert::TYPE_MULTI_DEVICE,
            'medium',
            'Vượt giới hạn thiết bị đăng nhập',
            sprintf('Tài khoản đăng nhập trên nhiều hơn %d thiết bị. Đã thu hồi %d phiên cũ.', $limit, $excess->count()),
            ['revoked_sessions' => $excess->count(), 'limit' => $limit],
        );

        return $excess->count();
    }

    /**
     * Quét log truy cập tìm dấu hiệu tải hàng loạt (spec 3.3.2).
     * Chạy định kỳ qua scheduler.
     *
     * @return int Số cảnh báo mới tạo
     */
    public function detectMassDownload(int $withinMinutes = 60): int
    {
        $threshold = (int) Setting::get('security.mass_download_threshold', self::DEFAULT_MASS_DOWNLOAD_THRESHOLD);

        $suspects = DocumentAccessLog::query()
            ->whereIn('action', [DocumentAccessLog::ACTION_DOWNLOAD, DocumentAccessLog::ACTION_VIEW])
            ->where('accessed_at', '>=', now()->subMinutes($withinMinutes))
            ->whereNotNull('user_id')
            ->select('user_id')
            ->selectRaw('COUNT(DISTINCT document_id) as doc_count')
            ->groupBy('user_id')
            ->havingRaw('COUNT(DISTINCT document_id) >= ?', [$threshold])
            ->get();

        $created = 0;

        foreach ($suspects as $suspect) {
            $user = User::find($suspect->user_id);

            if (! $user) {
                continue;
            }

            // Không tạo cảnh báo trùng khi job chạy lại trong cùng cửa sổ thời gian
            $existing = SecurityAlert::where('user_id', $user->id)
                ->where('type', SecurityAlert::TYPE_MASS_DOWNLOAD)
                ->where('status', SecurityAlert::STATUS_OPEN)
                ->where('created_at', '>=', now()->subMinutes($withinMinutes))
                ->exists();

            if ($existing) {
                continue;
            }

            $this->raise(
                $user,
                SecurityAlert::TYPE_MASS_DOWNLOAD,
                'high',
                'Truy cập nhiều tài liệu bất thường',
                sprintf('Truy cập %d tài liệu khác nhau trong %d phút.', $suspect->doc_count, $withinMinutes),
                ['document_count' => $suspect->doc_count, 'window_minutes' => $withinMinutes],
            );

            $created++;
        }

        return $created;
    }

    /** Quét đăng nhập thất bại liên tiếp — dấu hiệu dò mật khẩu. */
    public function detectBruteForce(int $withinMinutes = 30): int
    {
        $threshold = (int) Setting::get('security.failed_login_threshold', self::DEFAULT_FAILED_LOGIN_THRESHOLD);

        $suspects = LoginHistory::query()
            ->where('result', 'failed')
            ->where('logged_in_at', '>=', now()->subMinutes($withinMinutes))
            ->select('user_id')
            ->selectRaw('COUNT(*) as fail_count')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->get();

        $created = 0;

        foreach ($suspects as $suspect) {
            $user = User::find($suspect->user_id);

            if (! $user) {
                continue;
            }

            $existing = SecurityAlert::where('user_id', $user->id)
                ->where('type', SecurityAlert::TYPE_BRUTE_FORCE)
                ->where('status', SecurityAlert::STATUS_OPEN)
                ->where('created_at', '>=', now()->subMinutes($withinMinutes))
                ->exists();

            if ($existing) {
                continue;
            }

            $this->raise(
                $user,
                SecurityAlert::TYPE_BRUTE_FORCE,
                'critical',
                'Đăng nhập thất bại nhiều lần',
                sprintf('%d lần đăng nhập thất bại trong %d phút.', $suspect->fail_count, $withinMinutes),
                ['fail_count' => $suspect->fail_count],
            );

            $created++;
        }

        return $created;
    }

    /** Phát hiện đăng nhập từ IP chưa từng dùng trước đó. */
    public function detectUnusualIp(User $user, string $ip): ?SecurityAlert
    {
        $seenBefore = LoginHistory::where('user_id', $user->id)
            ->where('result', 'success')
            ->where('ip_address', $ip)
            ->where('logged_in_at', '<', now()->subMinute())
            ->exists();

        // Lần đăng nhập đầu tiên của tài khoản không phải bất thường
        $hasHistory = LoginHistory::where('user_id', $user->id)
            ->where('result', 'success')
            ->where('logged_in_at', '<', now()->subMinute())
            ->exists();

        if ($seenBefore || ! $hasHistory) {
            return null;
        }

        return $this->raise(
            $user,
            SecurityAlert::TYPE_UNUSUAL_IP,
            'medium',
            'Đăng nhập từ địa chỉ IP lạ',
            sprintf('Tài khoản đăng nhập từ IP %s chưa từng xuất hiện trước đây.', $ip),
            ['ip' => $ip],
        );
    }

    /** Thu hồi toàn bộ phiên của một tài khoản — dùng khi xử lý sự cố. */
    public function revokeAllSessions(User $user): int
    {
        return DeviceSession::where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'revoked_at' => now()]);
    }

    public function acknowledge(SecurityAlert $alert, ?int $userId = null): void
    {
        $alert->update([
            'status' => SecurityAlert::STATUS_ACKNOWLEDGED,
            'handled_by' => $userId,
            'handled_at' => now(),
        ]);
    }

    public function resolve(SecurityAlert $alert, ?int $userId = null, bool $falsePositive = false): void
    {
        $alert->update([
            'status' => $falsePositive ? SecurityAlert::STATUS_FALSE_POSITIVE : SecurityAlert::STATUS_RESOLVED,
            'handled_by' => $userId,
            'handled_at' => now(),
        ]);
    }

    private function raise(
        User $user,
        string $type,
        string $severity,
        string $title,
        string $detail,
        array $context = [],
    ): SecurityAlert {
        return SecurityAlert::create([
            'user_id' => $user->id,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'context' => $context,
            'status' => SecurityAlert::STATUS_OPEN,
        ]);
    }

    /** Nhãn thiết bị suy ra từ user agent, đủ để người dùng nhận ra máy của mình. */
    private function deviceLabel(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Không xác định',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            str_contains($ua, 'Firefox') => 'Firefox',
            default => 'Trình duyệt khác',
        };

        return $os . ' · ' . $browser;
    }
}
