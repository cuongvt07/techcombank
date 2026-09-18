<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;

/**
 * Dựng nội dung khoá học (spec 3.2.4 + 3.2.5): sắp xếp bài học, xuất bản,
 * và giữ tiến độ của người đang học nhất quán khi cấu trúc khoá thay đổi.
 */
class CourseBuilderService
{
    public function __construct(private readonly ProgressService $progress)
    {
    }

    /**
     * Sắp xếp lại thứ tự bài học theo danh sách id truyền vào.
     * Thứ tự quyết định điều kiện mở khoá bài tiếp theo khi học tuần tự.
     *
     * @param  array<int, int>  $orderedLessonIds
     */
    public function reorderLessons(Course $course, array $orderedLessonIds): void
    {
        DB::transaction(function () use ($course, $orderedLessonIds) {
            foreach (array_values($orderedLessonIds) as $index => $lessonId) {
                // Ràng buộc course_id để một id lạ không kéo bài của khoá khác vào đây
                Lesson::where('id', $lessonId)
                    ->where('course_id', $course->id)
                    ->update(['sort_order' => $index]);
            }
        });
    }

    /** Đổi vị trí một bài lên/xuống một bậc. */
    public function moveLesson(Lesson $lesson, int $direction): void
    {
        $neighbour = Lesson::where('course_id', $lesson->course_id)
            ->when(
                $direction < 0,
                fn ($q) => $q->where('sort_order', '<', $lesson->sort_order)->orderByDesc('sort_order'),
                fn ($q) => $q->where('sort_order', '>', $lesson->sort_order)->orderBy('sort_order'),
            )
            ->first();

        if (! $neighbour) {
            return;
        }

        DB::transaction(function () use ($lesson, $neighbour) {
            $lessonOrder = $lesson->sort_order;
            $lesson->update(['sort_order' => $neighbour->sort_order]);
            $neighbour->update(['sort_order' => $lessonOrder]);
        });
    }

    /**
     * Xuất bản khoá học.
     *
     * @return array{ok: bool, message: string}
     */
    public function publish(Course $course): array
    {
        $lessons = $course->lessons()->get();

        if ($lessons->isEmpty()) {
            return ['ok' => false, 'message' => 'Khóa học chưa có bài học nào.'];
        }

        if ($lessons->where('is_required', true)->isEmpty()) {
            return ['ok' => false, 'message' => 'Khóa học cần ít nhất một bài học bắt buộc để tính tiến độ.'];
        }

        // Bài học trỏ tới nội dung rỗng sẽ khiến người học mở ra thấy trang trắng
        $incomplete = $lessons->filter(fn (Lesson $lesson) => ! $this->hasContent($lesson));

        if ($incomplete->isNotEmpty()) {
            return [
                'ok' => false,
                'message' => 'Chưa gắn nội dung cho bài: ' . $incomplete->pluck('title')->take(3)->join(', ')
                    . ($incomplete->count() > 3 ? '…' : ''),
            ];
        }

        $course->update([
            'status' => Course::STATUS_PUBLISHED,
            'published_at' => now(),
            'published_by' => auth()->id(),
        ]);

        return ['ok' => true, 'message' => 'Đã xuất bản khóa học.'];
    }

    /**
     * Tính lại tiến độ của mọi người đang học khoá này.
     *
     * Bắt buộc gọi sau khi thêm/xoá/đổi cờ bắt buộc của bài học: mẫu số đổi thì
     * % tiến độ đã lưu trở thành sai, và người học có thể bị kẹt ở 90% vĩnh viễn
     * hoặc đột nhiên "hoàn thành" khoá mà chưa học bài mới.
     */
    public function refreshEnrollmentProgress(Course $course): int
    {
        $count = 0;

        Enrollment::where('course_id', $course->id)
            ->whereNot('status', Enrollment::STATUS_CANCELLED)
            ->chunkById(100, function ($enrollments) use (&$count) {
                foreach ($enrollments as $enrollment) {
                    $this->progress->recalculate($enrollment);
                    $count++;
                }
            });

        return $count;
    }

    /** Bài học đã có nội dung để hiển thị chưa. */
    private function hasContent(Lesson $lesson): bool
    {
        return match ($lesson->content_type) {
            Lesson::TYPE_TEXT => filled($lesson->content_html),
            Lesson::TYPE_DOCUMENT, Lesson::TYPE_VIDEO => $lesson->document_id !== null,
            Lesson::TYPE_QUIZ => $lesson->quiz_id !== null,
            default => false,
        };
    }
}
