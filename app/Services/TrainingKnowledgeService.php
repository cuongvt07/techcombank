<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Gom ngữ cảnh cho trợ lý AI (spec 4.3).
 *
 * Nguyên tắc bắt buộc: chỉ lấy nội dung mà chính nhân viên đó được phép xem.
 * Nhân viên không được gán khóa học nào thì AI không thấy bài giảng của khóa đó;
 * tài liệu bị rule deny cũng không lọt vào ngữ cảnh. Nhờ vậy AI không thể trả
 * lời vượt quá phạm vi quyền truy cập của người hỏi.
 */
class TrainingKnowledgeService
{
    /**
     * Độ dài tối đa mỗi đoạn ngữ cảnh, tính theo ký tự.
     *
     * Model đang dùng có cửa sổ 131k token nên 2500 ký tự mỗi đoạn vẫn thừa chỗ.
     * Mức 1200 cũ cắt mất phần sau của bài giảng dài, AI trả lời thiếu.
     */
    private const CHUNK_CHARS = 2500;

    /**
     * Bài giảng dài hơn ngưỡng này được chia thành nhiều đoạn thay vì cắt bỏ.
     *
     * Cắt cụt nghĩa là nửa sau của bài không bao giờ vào được ngữ cảnh — hỏi về
     * phần cuối bài thì AI nói không tìm thấy dù nội dung có thật.
     */
    private const SPLIT_THRESHOLD = 3000;

    public function __construct(
        private readonly DocumentAccessService $documentAccess,
    ) {
    }

    /**
     * Tìm các đoạn nội dung liên quan tới câu hỏi.
     *
     * @return Collection<int, array{source: string, title: string, body: string, url: ?string}>
     */
    public function search(Employee $employee, string $question, int $limit = 8): Collection
    {
        $keywords = $this->keywords($question);

        if ($keywords === []) {
            return collect();
        }

        return $this->lessonChunks($employee, $keywords)
            ->concat($this->quizChunks($employee, $keywords))
            ->concat($this->courseOutlineChunks($employee, $keywords))
            ->concat($this->documentChunks($employee, $keywords))
            ->concat($this->courseChunks($employee, $keywords))
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * Tách từ khóa từ câu hỏi.
     *
     * Bỏ các từ nối tiếng Việt vì chúng xuất hiện ở mọi tài liệu, giữ lại sẽ
     * khiến truy vấn khớp tất cả và mất hết tác dụng lọc.
     */
    private function keywords(string $question): array
    {
        $stopWords = [
            'là', 'gì', 'của', 'cho', 'và', 'các', 'những', 'một', 'có', 'không',
            'thì', 'mà', 'với', 'được', 'này', 'đó', 'khi', 'nào', 'ở', 'tôi',
            'bạn', 'ai', 'sao', 'thế', 'như', 'về', 'trong', 'ra', 'vào', 'bao',
            'nhiêu', 'phải', 'làm', 'nói', 'biết', 'muốn', 'cần', 'hỏi',
        ];

        $words = preg_split('/[\s\p{P}]+/u', mb_strtolower(trim($question)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($words)
            ->reject(fn (string $w) => mb_strlen($w) < 2 || in_array($w, $stopWords, true))
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Bài giảng trong các khóa nhân viên được gán.
     *
     * Đây là nguồn chính: content_html đã là văn bản nên đọc thẳng được, không
     * cần trích xuất như file PDF.
     */
    private function lessonChunks(Employee $employee, array $keywords): Collection
    {
        $lessons = Lesson::query()
            ->with('course:id,title,slug')
            ->whereHas('course.enrollments', fn ($q) => $q->where('employee_id', $employee->id))
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $word) {
                    $q->orWhere('title', 'like', "%{$word}%")
                        ->orWhere('summary', 'like', "%{$word}%")
                        ->orWhere('content_html', 'like', "%{$word}%");
                }
            })
            ->limit(20)
            ->get();

        return $lessons->flatMap(function (Lesson $lesson) use ($keywords) {
            $text = trim(strip_tags((string) $lesson->content_html));
            $body = $text !== '' ? $text : (string) $lesson->summary;

            if ($body === '') {
                return [];
            }

            $title = $lesson->course?->title
                ? "Bài giảng: {$lesson->title} (khóa {$lesson->course->title})"
                : "Bài giảng: {$lesson->title}";

            $url = $lesson->course?->slug
                ? route('learn.course', $lesson->course->slug)
                : null;

            // Bài dài tách thành nhiều đoạn, mỗi đoạn chấm điểm riêng để đoạn
            // nào sát câu hỏi nhất được chọn — thay vì cắt cụt mất nửa sau
            $parts = $this->splitBody($body);
            $multi = count($parts) > 1;

            return collect($parts)
                ->map(fn (string $part, int $i) => [
                    'source' => 'lesson',
                    'title' => $multi ? $title . ' — phần ' . ($i + 1) : $title,
                    'body' => $part,
                    'url' => $url,
                    'score' => $this->score($lesson->title . ' ' . $part, $keywords),
                ])
                ->all();
        });
    }

    /**
     * Câu hỏi trắc nghiệm và phần giải thích đáp án.
     *
     * Đây là nguồn giá trị nhất cho việc ôn tập: phần explanation nói rõ VÌ SAO
     * một đáp án đúng, chứ không chỉ nêu đáp án.
     *
     * QUAN TRỌNG — không đưa nội dung các lựa chọn và cờ is_correct vào ngữ cảnh.
     * Nếu đưa vào, nhân viên chỉ cần hỏi trợ lý là có ngay đáp án, bài kiểm tra
     * mất hết ý nghĩa đánh giá. AI giảng lại kiến thức để người học tự làm được,
     * không làm hộ.
     *
     * Chỉ lấy câu hỏi của khóa nhân viên đã được gán.
     */
    private function quizChunks(Employee $employee, array $keywords): Collection
    {
        $questions = Question::query()
            ->with('quiz:id,title,course_id')
            ->where('is_active', true)
            ->whereHas('quiz.course.enrollments', fn ($q) => $q->where('employee_id', $employee->id))
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $word) {
                    $q->orWhere('content', 'like', "%{$word}%")
                        ->orWhere('explanation', 'like', "%{$word}%");
                }
            })
            ->limit(20)
            ->get();

        return $questions->map(function (Question $question) use ($keywords) {
            $explanation = trim((string) $question->explanation);

            // Không có giải thích thì câu hỏi trần không giúp gì cho việc ôn tập
            if ($explanation === '') {
                return null;
            }

            $body = "Câu hỏi ôn tập: {$question->content}

Giải thích: {$explanation}";

            return [
                'source' => 'quiz',
                'title' => 'Ôn tập: ' . Str::limit($question->quiz?->title ?? 'Bài kiểm tra', 60),
                'body' => Str::limit($body, self::CHUNK_CHARS),
                'url' => null,
                // Nhân đôi điểm: nội dung ôn tập sát câu hỏi thường đúng thứ
                // người học đang cần khi chuẩn bị kiểm tra
                'score' => $this->score($question->content . ' ' . $explanation, $keywords) * 2,
            ];
        })->filter()->values();
    }

    /**
     * Tài liệu nhân viên được phép xem.
     *
     * Chỉ đưa tiêu đề + mô tả vào ngữ cảnh, không đọc ruột file PDF/Word. AI sẽ
     * chỉ đường tới tài liệu chứ không trích dẫn nội dung bên trong.
     */
    private function documentChunks(Employee $employee, array $keywords): Collection
    {
        $documents = $this->documentAccess->visibleDocumentsQuery($employee)
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $word) {
                    $q->orWhere('title', 'like', "%{$word}%")
                        ->orWhere('description', 'like', "%{$word}%");
                }
            })
            ->limit(15)
            ->get(['id', 'title', 'description']);

        return $documents->map(function (Document $document) use ($keywords) {
            $body = trim((string) $document->description);

            return [
                'source' => 'document',
                'title' => "Tài liệu: {$document->title}",
                'body' => $body !== ''
                    ? Str::limit($body, self::CHUNK_CHARS)
                    : 'Tài liệu này có trong thư viện nội bộ, chưa có mô tả chi tiết.',
                'url' => route('learn.documents'),
                'score' => $this->score($document->title . ' ' . $body, $keywords),
            ];
        });
    }

    /** Mô tả khóa học — giúp AI trả lời câu hỏi kiểu "khóa nào dạy về X". */
    private function courseChunks(Employee $employee, array $keywords): Collection
    {
        $courses = Course::query()
            ->whereHas('enrollments', fn ($q) => $q->where('employee_id', $employee->id))
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $word) {
                    $q->orWhere('title', 'like', "%{$word}%")
                        ->orWhere('description', 'like', "%{$word}%");
                }
            })
            ->limit(10)
            ->get(['id', 'title', 'slug', 'description']);

        return $courses->map(fn (Course $course) => [
            'source' => 'course',
            'title' => "Khóa học: {$course->title}",
            'body' => Str::limit(trim((string) $course->description), self::CHUNK_CHARS),
            'url' => route('learn.course', $course->slug),
            'score' => $this->score($course->title . ' ' . $course->description, $keywords),
        ])->reject(fn (array $c) => $c['body'] === '');
    }

    /**
     * Chia nội dung dài thành các đoạn theo ranh giới câu.
     *
     * Cắt giữa câu làm mất nghĩa, nên gom theo dấu chấm cho tới khi đủ dài.
     *
     * @return array<int, string>
     */
    private function splitBody(string $body): array
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

        if (mb_strlen($body) <= self::SPLIT_THRESHOLD) {
            return [Str::limit($body, self::CHUNK_CHARS, '')];
        }

        $sentences = preg_split('/(?<=[.!?:])\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [$body];

        $parts = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if ($current !== '' && mb_strlen($current . ' ' . $sentence) > self::CHUNK_CHARS) {
                $parts[] = $current;
                $current = $sentence;

                continue;
            }

            $current = $current === '' ? $sentence : $current . ' ' . $sentence;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        // Giới hạn số đoạn mỗi bài để một bài dài không chiếm hết ngữ cảnh
        return array_slice($parts, 0, 4);
    }

    /**
     * Tổng hợp cấu trúc khóa học: danh sách bài, dạng bài, tiến độ của nhân viên.
     *
     * Trả lời được nhóm câu hỏi mà nội dung từng bài riêng lẻ không đáp ứng:
     * "khóa này có mấy bài", "tôi còn bài nào chưa học", "khóa nào có kiểm tra".
     */
    private function courseOutlineChunks(Employee $employee, array $keywords): Collection
    {
        $enrollments = Enrollment::query()
            ->with(['course.lessonsInOrder'])
            ->where('employee_id', $employee->id)
            ->where('status', '!=', Enrollment::STATUS_CANCELLED)
            ->get();

        if ($enrollments->isEmpty()) {
            return collect();
        }

        // Nạp một lần cho mọi khóa, tránh truy vấn lặp trong vòng lặp
        $completed = LessonProgress::query()
            ->where('employee_id', $employee->id)
            ->where('status', LessonProgress::STATUS_COMPLETED)
            ->pluck('lesson_id')
            ->flip();

        return $enrollments->map(function (Enrollment $enrollment) use ($keywords, $completed) {
            $course = $enrollment->course;

            if (! $course) {
                return null;
            }

            $lines = [];

            foreach ($course->lessonsInOrder as $index => $lesson) {
                $kind = match ($lesson->content_type) {
                    Lesson::TYPE_VIDEO => 'video',
                    Lesson::TYPE_QUIZ => 'bài kiểm tra',
                    Lesson::TYPE_DOCUMENT => 'tài liệu',
                    default => 'bài đọc',
                };

                $state = $completed->has($lesson->id) ? 'đã hoàn thành' : 'chưa hoàn thành';
                $lines[] = sprintf('%d. %s (%s) - %s', $index + 1, $lesson->title, $kind, $state);
            }

            $due = $enrollment->due_date
                ? ' Hạn hoàn thành: ' . $enrollment->due_date->format('d/m/Y') . '.'
                : '';

            $body = sprintf(
                "Khóa học: %s. Trạng thái của bạn: %s, tiến độ %d%%, %d/%d bài đã xong.%s\n\nDanh sách bài trong khóa:\n%s",
                $course->title,
                $this->enrollmentStateLabel($enrollment->status),
                (int) $enrollment->progress_percent,
                (int) $enrollment->completed_lessons,
                (int) $enrollment->total_lessons,
                $due,
                implode("\n", $lines) ?: '(khóa chưa có bài nào)',
            );

            return [
                'source' => 'outline',
                'title' => 'Cấu trúc khóa học: ' . $course->title,
                'body' => Str::limit($body, self::CHUNK_CHARS),
                'url' => $course->slug ? route('learn.course', $course->slug) : null,
                'score' => $this->score($course->title . ' ' . $body, $keywords),
            ];
        })->filter()->values();
    }

    private function enrollmentStateLabel(?string $status): string
    {
        return match ($status) {
            Enrollment::STATUS_COMPLETED => 'đã hoàn thành',
            Enrollment::STATUS_IN_PROGRESS => 'đang học',
            Enrollment::STATUS_OVERDUE => 'quá hạn',
            default => 'chưa bắt đầu',
        };
    }

    /**
     * Điểm liên quan: đếm số lần từ khóa xuất hiện.
     *
     * Cách chấm điểm đơn giản, đủ dùng với kho tài liệu cỡ vài trăm bài. Nếu
     * sau này kho lớn lên thì thay bằng full-text index hoặc vector search —
     * chỉ cần đổi hàm này, phần còn lại giữ nguyên.
     */
    private function score(string $haystack, array $keywords): int
    {
        $haystack = mb_strtolower($haystack);
        $score = 0;

        foreach ($keywords as $word) {
            $score += mb_substr_count($haystack, $word);
        }

        return $score;
    }
}
