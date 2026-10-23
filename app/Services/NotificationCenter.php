<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Employee;
use App\Models\Quiz;
use App\Models\SupportRequest;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chuông thông báo — một chỗ cho cả gửi lẫn đọc.
 *
 * Hai nguồn, một cách "đã đọc / chưa đọc":
 *  - Announcement (sự kiện công ty + lời nhắc học riêng): đã đọc lưu ở
 *    announcement_reads theo nhân viên — thông báo toàn công ty không thể nhân
 *    bản cho từng người.
 *  - Thông báo tài khoản (bảng notifications, read_at): phân công phiếu hỗ trợ,
 *    phiếu được xử lý, cấp thêm lượt làm bài. Gửi theo user vì người nhận có thể
 *    là quản trị viên không có hồ sơ nhân viên.
 * Chuông chỉ làm việc với "khóa" chung (a:<id> / n:<uuid>) nên hai nguồn được
 * đếm, liệt kê và đánh dấu đã đọc giống hệt nhau.
 *
 * Site nhân viên đọc cả hai nguồn; site quản trị chỉ đọc thông báo tài khoản.
 */
class NotificationCenter
{
    public const SITE_USER = 'user';
    public const SITE_ADMIN = 'admin';

    public const CAT_LEARNING = 'learning';
    public const CAT_SUPPORT = 'support';
    public const CAT_EVENTS = 'events';

    public const CATEGORY_LABELS = [
        self::CAT_LEARNING => 'Học tập',
        self::CAT_SUPPORT => 'Hỗ trợ',
        self::CAT_EVENTS => 'Sự kiện',
    ];

    // ---- Gửi ------------------------------------------------------------------

    public function send(User|int|null $user, string $category, string $title, string $body, string $url, string $icon = 'heroicon-o-bell'): void
    {
        $user = is_int($user) ? User::find($user) : $user;

        $user?->notify(new AppNotification($category, $title, $body, $url, $icon));
    }

    /** Người được giao phụ trách phiếu. Tự nhận phiếu cho mình thì không báo chính mình. */
    public function supportAssigned(SupportRequest $request, ?int $actorId): void
    {
        if (! $request->assigned_to || $request->assigned_to === $actorId) {
            return;
        }

        $this->send(
            $request->assigned_to,
            self::CAT_SUPPORT,
            "Bạn được giao phụ trách phiếu {$request->ticket_no}",
            Str::limit((string) $request->subject, 140),
            route('admin.support', ['q' => $request->ticket_no, 'statusFilter' => ''], false),
            'heroicon-s-lifebuoy',
        );
    }

    /** Nhân viên gửi phiếu: phiếu đã có người nhận xử lý. */
    public function supportAccepted(SupportRequest $request): void
    {
        $this->sendToRequester(
            $request,
            "Phiếu {$request->ticket_no} đã được tiếp nhận xử lý",
            'Yêu cầu "' . Str::limit((string) $request->subject, 90) . '" đang được bộ phận hỗ trợ xử lý.',
        );
    }

    /** Nhân viên gửi phiếu: phiếu đã được giải quyết (kèm trích phản hồi). */
    public function supportResolved(SupportRequest $request): void
    {
        $this->sendToRequester(
            $request,
            "Phiếu {$request->ticket_no} đã được giải quyết",
            Str::limit('Phản hồi: ' . $request->resolution, 160),
        );
    }

    /** Nhân viên được cấp thêm lượt làm bài kiểm tra. */
    public function attemptsGranted(Quiz $quiz, Employee|int $employee, int $extra): void
    {
        $employee = $employee instanceof Employee ? $employee : Employee::find($employee);
        $course = $quiz->course_id ? $quiz->course()->first(['id', 'slug']) : null;

        $this->send(
            $employee?->user_id,
            self::CAT_LEARNING,
            "Bạn được cấp thêm {$extra} lượt làm bài: {$quiz->title}",
            'Bạn có thể làm lại bài kiểm tra trong khóa học tương ứng.',
            $course?->slug ? route('learn.course', $course, false) : route('learn.courses', [], false),
            'heroicon-s-academic-cap',
        );
    }

    private function sendToRequester(SupportRequest $request, string $title, string $body): void
    {
        $userId = Employee::whereKey($request->employee_id)->value('user_id');

        $this->send($userId, self::CAT_SUPPORT, $title, $body, route('learn.support', [], false), 'heroicon-s-lifebuoy');
    }

    // ---- Đọc ------------------------------------------------------------------

    /** Tab hiển thị ở chuông của từng site. */
    public function tabs(string $site): array
    {
        return $site === self::SITE_ADMIN
            ? ['all' => 'Tất cả', self::CAT_SUPPORT => 'Hỗ trợ', self::CAT_LEARNING => 'Học tập']
            : ['all' => 'Tất cả', self::CAT_LEARNING => 'Học tập', self::CAT_SUPPORT => 'Hỗ trợ', self::CAT_EVENTS => 'Sự kiện'];
    }

    /**
     * Số chưa đọc theo tab; 'all' = tổng mọi tab.
     *
     * @return array<string, int>
     */
    public function unreadCounts(User $user, string $site): array
    {
        $counts = array_fill_keys(array_keys(self::CATEGORY_LABELS), 0);

        // Thông báo tài khoản: một truy vấn gom theo loại
        $rows = $user->unreadNotifications()->reorder()->toBase()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.category')) AS cat, COUNT(*) AS c")
            ->groupBy('cat')
            ->pluck('c', 'cat');

        foreach ($rows as $cat => $c) {
            if (isset($counts[$cat])) {
                $counts[$cat] += (int) $c;
            }
        }

        if ($site === self::SITE_USER && ($employee = $this->employee($user))) {
            $row = Announcement::query()->unreadFor($employee)->reorder()->toBase()
                ->selectRaw("COALESCE(SUM(type = 'course' AND employee_id IS NOT NULL), 0) AS learning, COUNT(*) AS total")
                ->first();
            $counts[self::CAT_LEARNING] += (int) $row->learning;
            $counts[self::CAT_EVENTS] += (int) $row->total - (int) $row->learning;
        }

        return ['all' => array_sum($counts)] + $counts;
    }

    /**
     * Thông báo mới nhất của một tab, hai nguồn trộn theo thời gian.
     *
     * @return Collection<int, array{key: string, category: string, title: string, body: string, at: \Carbon\CarbonInterface|null, read: bool, icon: string, accent: bool}>
     */
    public function items(User $user, string $site, string $tab = 'all', int $limit = 10): Collection
    {
        $items = $this->accountQuery($user, $tab)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($n) => [
                'key' => 'n:' . $n->id,
                'category' => $n->data['category'] ?? self::CAT_EVENTS,
                'title' => (string) ($n->data['title'] ?? ''),
                'body' => (string) ($n->data['body'] ?? ''),
                'at' => $n->created_at,
                'read' => $n->read_at !== null,
                'icon' => $n->data['icon'] ?? 'heroicon-o-bell',
                'accent' => ($n->data['category'] ?? null) === self::CAT_LEARNING,
            ]);

        if ($site === self::SITE_USER && ($employee = $this->employee($user)) && $tab !== self::CAT_SUPPORT) {
            $announcements = $this->announcementQuery($employee, $tab)
                ->withReadFlag($employee)
                ->latest('published_at')
                ->latest('id')
                ->limit($limit)
                ->get(['id', 'title', 'content', 'type', 'employee_id', 'published_at'])
                ->map(fn (Announcement $a) => [
                    'key' => 'a:' . $a->id,
                    'category' => $a->isLearningReminder() ? self::CAT_LEARNING : self::CAT_EVENTS,
                    'title' => $a->title,
                    'body' => Str::limit((string) $a->content, 160),
                    'at' => $a->published_at,
                    'read' => (bool) $a->is_read,
                    'icon' => match (true) {
                        $a->isLearningReminder() => 'heroicon-s-academic-cap',
                        $a->isPersonal() => 'heroicon-s-user',
                        default => 'heroicon-o-megaphone',
                    },
                    'accent' => $a->isLearningReminder(),
                ]);

            $items = $items->concat($announcements);
        }

        return $items->sortByDesc(fn ($i) => $i['at']?->getTimestamp() ?? 0)->take($limit)->values();
    }

    /** Đánh dấu một thông báo đã đọc; trả về trang cần mở (null nếu không thuộc người này). */
    public function open(User $user, string $site, string $key): ?string
    {
        [$source, $id] = array_pad(explode(':', $key, 2), 2, '');

        if ($source === 'n') {
            $notification = $user->notifications()->whereKey($id)->first();
            $notification?->markAsRead();

            return $notification?->data['url'] ?? null;
        }

        if ($source === 'a' && $site === self::SITE_USER && ($employee = $this->employee($user))) {
            $announcement = Announcement::query()->notifiableFor($employee)->with('course:id,slug')->find((int) $id);
            $announcement?->markReadBy($employee);

            return $announcement?->targetUrl();
        }

        return null;
    }

    public function markAllRead(User $user, string $site, string $tab = 'all'): void
    {
        $this->accountQuery($user, $tab)->whereNull('read_at')->update(['read_at' => now()]);

        if ($site === self::SITE_USER && ($employee = $this->employee($user)) && $tab !== self::CAT_SUPPORT) {
            $now = now();
            $rows = $this->announcementQuery($employee, $tab)
                ->whereDoesntHave('reads', fn ($q) => $q->where('employee_id', $employee->id))
                ->pluck('id')
                ->map(fn ($id) => ['announcement_id' => $id, 'employee_id' => $employee->id, 'read_at' => $now])
                ->all();

            if ($rows) {
                DB::table('announcement_reads')->insertOrIgnore($rows);
            }
        }
    }

    // ---- Nội bộ ---------------------------------------------------------------

    private function accountQuery(User $user, string $tab)
    {
        return $user->notifications()
            ->when($tab !== 'all', fn ($q) => $q->where('data->category', $tab));
    }

    /** Announcement của nhân viên theo tab: Học tập = lời nhắc riêng loại khóa học, Sự kiện = phần còn lại. */
    private function announcementQuery(Employee $employee, string $tab): Builder
    {
        $learning = fn ($q) => $q->where('type', Announcement::TYPE_COURSE)->whereNotNull('employee_id');

        return Announcement::query()
            ->notifiableFor($employee)
            ->when($tab === self::CAT_LEARNING, $learning)
            ->when($tab === self::CAT_EVENTS, fn ($q) => $q->whereNot($learning));
    }

    private function employee(User $user): ?Employee
    {
        return $user->employee?->setRelation('user', $user);
    }
}
