<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Enrollment;
use App\Models\Quiz;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Gửi nhắc học / nhắc làm lại bài kiểm tra.
 *
 * Hệ thống chưa có kênh email/push, nên lời nhắc là một thông báo riêng cho
 * nhân viên đó — hiện ngay đầu trang Sự kiện của họ (sự kiện riêng luôn xếp trên).
 * Thông báo mang course_id để chuông thông báo ở site nhân viên dẫn thẳng tới khóa.
 */
class LearningReminderService
{
    /**
     * Thời gian chờ CỐ ĐỊNH giữa hai lần nhắc cùng một việc (cùng lượt ghi danh,
     * hoặc cùng bài kiểm tra với cùng nhân viên): 2 giờ — đủ chặn bấm lặp/spam
     * mà admin vẫn nhắc lại được trong ngày.
     */
    public const COOLDOWN_HOURS = 2;

    /**
     * Nhắc các lượt ghi danh chưa hoàn thành.
     *
     * @param  iterable<Enrollment>  $enrollments
     * @return array{sent: int, skipped: int}
     */
    public function remindEnrollments(iterable $enrollments, ?int $byUserId = null): array
    {
        $sent = 0;
        $skipped = 0;

        foreach ($enrollments as $enrollment) {
            $enrollment->loadMissing('course');

            if (! $this->canRemind($enrollment)) {
                $skipped++;

                continue;
            }

            $due = $enrollment->due_date;
            $dueText = match (true) {
                $due === null => 'Khóa học chưa có hạn hoàn thành.',
                $due->isPast() => 'Khóa học đã quá hạn từ ngày ' . $due->format('d/m/Y') . '.',
                default => 'Hạn hoàn thành: ' . $due->format('d/m/Y') . '.',
            };

            Announcement::create([
                'title' => 'Nhắc hoàn thành khóa học: ' . $enrollment->course->title,
                'content' => "Bạn đang hoàn thành {$this->percent($enrollment)}% khóa học \"{$enrollment->course->title}\". {$dueText} "
                    . 'Vui lòng vào mục Khóa học của tôi để tiếp tục.',
                'type' => Announcement::TYPE_COURSE,
                'employee_id' => $enrollment->employee_id,
                'course_id' => $enrollment->course_id,
                'published_at' => now(),
                'expires_at' => now()->addDays(14),
                'created_by' => $byUserId,
            ]);

            $enrollment->forceFill(['last_reminded_at' => now()])->save();
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * Nhắc nhân viên làm lại bài kiểm tra chưa đạt.
     *
     * Người đã được nhắc làm lại CÙNG bài trong thời gian chờ bị bỏ qua (dựa trên
     * thông báo nhắc trước đó — announcements.quiz_id).
     *
     * @param  Collection<int, int>|array<int, int>  $employeeIds
     * @return array{sent: int, skipped: int}
     */
    public function remindRetake(Quiz $quiz, iterable $employeeIds, ?int $byUserId = null): array
    {
        $employeeIds = collect($employeeIds)->map(fn ($id) => (int) $id)->unique()->values();
        $recent = $this->recentRetakeReminders($employeeIds->map(fn ($id) => [$id, $quiz->id]));
        $sent = 0;

        foreach ($employeeIds as $employeeId) {
            if (isset($recent["{$employeeId}:{$quiz->id}"])) {
                continue;
            }

            Announcement::create([
                'title' => 'Nhắc làm lại bài kiểm tra: ' . $quiz->title,
                'content' => "Bạn chưa đạt bài kiểm tra \"{$quiz->title}\" (điểm đạt {$quiz->pass_score}%). Vui lòng làm lại trong khóa học tương ứng.",
                'type' => Announcement::TYPE_COURSE,
                'employee_id' => $employeeId,
                'course_id' => $quiz->course_id,
                'quiz_id' => $quiz->id,
                'published_at' => now(),
                'expires_at' => now()->addDays(14),
                'created_by' => $byUserId,
            ]);
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $employeeIds->count() - $sent];
    }

    /**
     * Lần nhắc làm lại gần nhất còn trong thời gian chờ, theo cặp nhân viên–bài.
     *
     * @param  iterable<array{0: int, 1: int}>  $pairs  [employee_id, quiz_id]
     * @return array<string, Carbon>  "employeeId:quizId" => lúc nhắc
     */
    public function recentRetakeReminders(iterable $pairs): array
    {
        $pairs = collect($pairs);

        if ($pairs->isEmpty()) {
            return [];
        }

        return Announcement::query()
            ->whereIn('employee_id', $pairs->pluck(0)->unique())
            ->whereIn('quiz_id', $pairs->pluck(1)->unique())
            ->where('created_at', '>', now()->subHours(self::COOLDOWN_HOURS))
            ->groupBy('employee_id', 'quiz_id')
            ->selectRaw('employee_id, quiz_id, MAX(created_at) AS reminded_at')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->employee_id}:{$r->quiz_id}" => Carbon::parse($r->reminded_at)])
            ->all();
    }

    /** Mốc được nhắc lại sau lần nhắc $remindedAt. */
    public static function nextRemindAt(\DateTimeInterface $remindedAt): Carbon
    {
        return Carbon::instance($remindedAt)->addHours(self::COOLDOWN_HOURS);
    }

    public function canRemind(Enrollment $enrollment): bool
    {
        if (in_array($enrollment->status, [Enrollment::STATUS_COMPLETED, Enrollment::STATUS_CANCELLED], true)) {
            return false;
        }

        return ! $enrollment->last_reminded_at
            || $enrollment->last_reminded_at->lt(now()->subHours(self::COOLDOWN_HOURS));
    }

    private function percent(Enrollment $enrollment): int
    {
        return (int) round((float) $enrollment->progress_percent);
    }
}
