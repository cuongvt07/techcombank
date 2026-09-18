<?php

namespace App\Livewire\User;

use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Services\DocumentAccessService;
use App\Services\ProgressService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Học một khoá (spec 4.2): mục lục bài học bên trái, nội dung bài đang học ở giữa.
 *
 * Mọi truy cập đều đi qua enrollment của chính người đang đăng nhập — không có
 * đường nào xem được khoá chưa được giao, kể cả khi đoán đúng id trên URL.
 */
class CoursePlayer extends Component
{
    public Course $course;

    #[Url]
    public ?int $lesson = null;

    /** Vị trí xem video gần nhất, đồng bộ từ trình phát về server. */
    public int $videoPosition = 0;

    /*
     * Nhớ kết quả trong vòng đời một request: enrollment và danh sách bài học
     * được hỏi tới nhiều lần khi render (mục lục, nội dung, điều kiện mở khoá).
     * Không nhớ lại thì mỗi lần gọi là một truy vấn CSDL trùng lặp.
     */
    private ?Enrollment $cachedEnrollment = null;
    private ?\Illuminate\Support\Collection $cachedLessons = null;

    public function mount(Course $course): void
    {
        $this->course = $course;

        // Không có enrollment thì coi như khoá không tồn tại với người này,
        // trả 404 thay vì 403 để không lộ sự tồn tại của khoá học nội bộ.
        if (! $this->enrollment()) {
            throw new NotFoundHttpException();
        }

        $this->lesson ??= $this->firstUnfinishedLessonId();
    }

    public function render(): View
    {
        $enrollment = $this->enrollment();
        $current = $this->currentLesson();

        return view('livewire.user.course-player', [
            'enrollment' => $enrollment,
            'lessons' => $this->lessons(),
            'currentLesson' => $current,
            'progressMap' => $this->progressMap($enrollment),
            'unlockedMap' => $this->unlockedMap($enrollment),
            'currentProgress' => $current
                ? LessonProgress::where('enrollment_id', $enrollment->id)
                    ->where('lesson_id', $current->id)
                    ->first()
                : null,
            'watermarkText' => $current?->document && $current->document->enable_watermark
                ? app(DocumentAccessService::class)->watermarkText($this->employee())
                : null,

            // Xong hết bài thì nút "Bài sau" đổi thành "Thoát": không còn bài
            // nào để đi tiếp, để nguyên nút mờ là ngõ cụt
            'allLessonsCompleted' => $this->allLessonsCompleted($enrollment),
        ])->layout('layouts.user', [
            'title' => $this->course->title,
        ]);
    }

    /** Mở một bài học, chặn nếu bài chưa được mở khoá theo trình tự. */
    public function openLesson(int $lessonId, ProgressService $progress): void
    {
        $enrollment = $this->enrollment();
        $lesson = $this->lessons()->firstWhere('id', $lessonId);

        if (! $lesson) {
            return;
        }

        if (! $lesson->isUnlockedFor($enrollment)) {
            session()->flash('error', 'Hãy hoàn thành bài học trước đó để mở bài này.');

            return;
        }

        $this->lesson = $lessonId;
        $this->videoPosition = 0;

        $progress->markLessonStarted($enrollment, $lesson);
        $this->logDocumentAccess($lesson);
    }

    /** Đánh dấu hoàn thành bài văn bản/tài liệu — người học tự xác nhận đã đọc xong. */
    public function completeLesson(ProgressService $progress): void
    {
        $lesson = $this->currentLesson();

        if (! $lesson) {
            return;
        }

        // Bài kiểm tra chỉ hoàn thành khi đạt điểm, không cho bấm tay
        if ($lesson->isQuiz()) {
            return;
        }

        /*
         * Video nhúng (YouTube/Vimeo) không báo tiến độ về trang cha được, nên
         * người học tự xác nhận. Video phát trực tiếp thì đo được nên vẫn để
         * hệ thống tự đánh dấu theo ngưỡng % xem — bấm tay sẽ bỏ qua ngưỡng đó.
         */
        if ($lesson->isVideo() && ! $this->isEmbeddedVideo($lesson)) {
            return;
        }

        $progress->completeLesson($this->enrollment(), $lesson);

        session()->flash('status', 'Đã hoàn thành bài học.');

        $this->goToNextLesson();
    }

    /**
     * Nhận tiến độ xem video từ trình phát.
     * Bài tự hoàn thành khi đạt ngưỡng % xem tối thiểu (spec 3.2.2).
     */
    public function updateVideoProgress(int $position, float $percent, ProgressService $progress): void
    {
        $lesson = $this->currentLesson();

        if (! $lesson || ! $lesson->isVideo()) {
            return;
        }

        $this->videoPosition = $position;

        $progress->updateVideoProgress(
            $this->enrollment(),
            $lesson,
            $position,
            min(100, max(0, $percent)),
        );
    }

    /**
     * Client báo về số giây người học ở lại bài.
     * Gọi định kỳ và khi rời trang, để biết ai thực sự học và ai chỉ bấm qua.
     */
    public function recordTimeSpent(int $seconds, ProgressService $progress): void
    {
        $lesson = $this->currentLesson();

        if ($lesson) {
            $progress->addTimeSpent($this->enrollment(), $lesson, $seconds);
        }
    }

    /**
     * Đã hoàn thành toàn bộ bài trong khoá chưa.
     *
     * Đếm trên tập bài hiện có thay vì tin vào completed_lessons của enrollment:
     * quản trị viên có thể thêm bài mới sau khi người học đã xong, lúc đó con số
     * đã lưu không còn đúng.
     */
    private function allLessonsCompleted(?Enrollment $enrollment): bool
    {
        if (! $enrollment) {
            return false;
        }

        $lessonIds = $this->lessons()->pluck('id');

        if ($lessonIds->isEmpty()) {
            return false;
        }

        $completed = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('status', LessonProgress::STATUS_COMPLETED)
            ->whereIn('lesson_id', $lessonIds)
            ->count();

        return $completed >= $lessonIds->count();
    }

    /** Rời khoá học, quay về danh sách khoá của mình. */
    public function exitCourse()
    {
        return $this->redirectRoute('learn.courses', navigate: true);
    }

    public function goToNextLesson(): void
    {
        $lessons = $this->lessons();
        $currentIndex = $lessons->search(fn (Lesson $l) => $l->id === $this->lesson);

        if ($currentIndex === false) {
            return;
        }

        $next = $lessons->get($currentIndex + 1);

        if ($next && $next->isUnlockedFor($this->enrollment())) {
            $this->lesson = $next->id;
            $this->videoPosition = 0;
            app(ProgressService::class)->markLessonStarted($this->enrollment(), $next);
        }
    }

    public function goToPreviousLesson(): void
    {
        $lessons = $this->lessons();
        $currentIndex = $lessons->search(fn (Lesson $l) => $l->id === $this->lesson);

        if ($currentIndex === false || $currentIndex === 0) {
            return;
        }

        $this->lesson = $lessons->get($currentIndex - 1)->id;
        $this->videoPosition = 0;
    }

    // ---- Trợ giúp -----------------------------------------------------------

    /**
     * Video nhúng từ nguồn ngoài — trình phát của họ không cho trang cha
     * đọc tiến độ, nên loại này người học tự xác nhận đã xem xong.
     */
    public function isEmbeddedVideo(Lesson $lesson): bool
    {
        return $lesson->isVideo() && $lesson->document?->embedUrl() !== null;
    }

    private function employee(): ?Employee
    {
        return auth()->user()?->employee;
    }

    private function enrollment(): ?Enrollment
    {
        if ($this->cachedEnrollment !== null) {
            return $this->cachedEnrollment;
        }

        $employee = $this->employee();

        if (! $employee) {
            return null;
        }

        return $this->cachedEnrollment = Enrollment::where('course_id', $this->course->id)
            ->where('employee_id', $employee->id)
            ->whereNot('status', Enrollment::STATUS_CANCELLED)
            ->first();
    }

    private function lessons()
    {
        return $this->cachedLessons ??= $this->course
            ->lessonsInOrder()
            ->with(['document', 'quiz'])
            ->get();
    }

    private function currentLesson(): ?Lesson
    {
        return $this->lesson
            ? $this->lessons()->firstWhere('id', $this->lesson)
            : $this->lessons()->first();
    }

    /** Bài chưa hoàn thành đầu tiên — điểm "học tiếp" khi quay lại khoá. */
    private function firstUnfinishedLessonId(): ?int
    {
        $enrollment = $this->enrollment();
        $completed = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('status', LessonProgress::STATUS_COMPLETED)
            ->pluck('lesson_id')
            ->flip();

        $next = $this->lessons()->first(fn (Lesson $l) => ! $completed->has($l->id));

        return ($next ?? $this->lessons()->first())?->id;
    }

    /** @return \Illuminate\Support\Collection<int, LessonProgress> keyed by lesson_id */
    private function progressMap(Enrollment $enrollment)
    {
        return LessonProgress::where('enrollment_id', $enrollment->id)
            ->get()
            ->keyBy('lesson_id');
    }

    /**
     * Bài nào đang mở khoá.
     * Tính sẵn thành map để view không gọi truy vấn trong vòng lặp.
     *
     * @return array<int, bool>
     */
    private function unlockedMap(Enrollment $enrollment): array
    {
        // Khoá học tự do: mọi bài đều mở, không cần hỏi CSDL
        if (! $this->course->sequential) {
            return $this->lessons()->mapWithKeys(fn (Lesson $l) => [$l->id => true])->all();
        }

        // Nạp một lần danh sách bài đã hoàn thành thay vì hỏi từng bài trong vòng lặp
        $completedIds = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('status', LessonProgress::STATUS_COMPLETED)
            ->pluck('lesson_id')
            ->flip();

        $map = [];
        $previousCompleted = true;

        foreach ($this->lessons() as $lesson) {
            $map[$lesson->id] = $previousCompleted;
            $previousCompleted = $completedIds->has($lesson->id);
        }

        return $map;
    }

    /** Ghi log xem tài liệu để truy vết (spec 3.3.2). */
    private function logDocumentAccess(Lesson $lesson): void
    {
        if (! $lesson->document || ! $this->employee()) {
            return;
        }

        app(DocumentAccessService::class)->logAccess(
            $this->employee(),
            $lesson->document,
            $lesson->isVideo()
                ? \App\Models\DocumentAccessLog::ACTION_STREAM
                : \App\Models\DocumentAccessLog::ACTION_VIEW,
        );
    }
}
