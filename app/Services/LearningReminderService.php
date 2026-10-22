<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Enrollment;
use App\Models\Quiz;
use Illuminate\Support\Collection;

/**
 * Gửi nhắc học / nhắc làm lại bài kiểm tra.
 *
 * Hệ thống chưa có kênh email/push, nên lời nhắc là một thông báo riêng cho
 * nhân viên đó — hiện ngay đầu trang Sự kiện của họ (sự kiện riêng luôn xếp trên).
 */
class LearningReminderService
{
    /** Không nhắc lại cùng một lượt ghi danh trong khoảng này, tránh spam. */
    public const COOLDOWN_HOURS = 12;

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
     * @param  Collection<int, int>|array<int, int>  $employeeIds
     */
    public function remindRetake(Quiz $quiz, iterable $employeeIds, ?int $byUserId = null): int
    {
        $count = 0;

        foreach ($employeeIds as $employeeId) {
            Announcement::create([
                'title' => 'Nhắc làm lại bài kiểm tra: ' . $quiz->title,
                'content' => "Bạn chưa đạt bài kiểm tra \"{$quiz->title}\" (điểm đạt {$quiz->pass_score}%). Vui lòng làm lại trong khóa học tương ứng.",
                'type' => Announcement::TYPE_COURSE,
                'employee_id' => $employeeId,
                'published_at' => now(),
                'expires_at' => now()->addDays(14),
                'created_by' => $byUserId,
            ]);
            $count++;
        }

        return $count;
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
