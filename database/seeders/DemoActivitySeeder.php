<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseAssignmentRule;
use App\Models\Department;
use App\Models\DeviceSession;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LoginHistory;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptAnswer;
use App\Models\SecurityAlert;
use App\Models\SupportRequest;
use App\Services\CourseAssignmentService;
use App\Services\DocumentAccessService;
use App\Services\ProgressService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Hoạt động học tập mẫu để mọi màn hình đều có dữ liệu thật để xem:
 * tiến độ dở dang, bài thi đã làm, log truy cập, cảnh báo, phiếu hỗ trợ.
 *
 * Chạy SAU DemoDataSeeder. Dữ liệu đi qua đúng service nghiệp vụ (ProgressService,
 * QuizService...) thay vì insert thẳng, nên % tiến độ và trạng thái luôn nhất quán
 * với logic thật — nếu insert tay sẽ sinh ra dữ liệu mà hệ thống không bao giờ tạo được.
 */
class DemoActivitySeeder extends Seeder
{
    public function run(): void
    {
        $this->createMoreCourses();
        $this->assignCoursesToEveryone();
        $this->simulateLearning();
        $this->simulateQuizAttempts();
        $this->createAccessLogs();
        $this->createLoginHistory();
        $this->createSecurityAlerts();
        $this->createSupportRequests();
    }

    /** Thêm vài khoá nữa để danh sách không quá thưa. */
    private function createMoreCourses(): void
    {
        $departments = Department::pluck('id', 'code');

        $courses = [
            [
                'code' => 'C-AML',
                'title' => 'Phòng chống rửa tiền (AML)',
                'description' => 'Nhận diện giao dịch đáng ngờ, quy trình báo cáo và trách nhiệm của nhân viên giao dịch.',
                'dept' => 'CRD',
                'days' => 45,
                'cert' => true,
            ],
            [
                'code' => 'C-CS',
                'title' => 'Kỹ năng chăm sóc khách hàng',
                'description' => 'Chuẩn mực giao tiếp, xử lý khiếu nại và giữ chân khách hàng.',
                'dept' => 'OPS',
                'days' => 30,
                'cert' => false,
            ],
            [
                'code' => 'C-DATA',
                'title' => 'Bảo mật dữ liệu khách hàng',
                'description' => 'Nguyên tắc xử lý dữ liệu cá nhân, phân loại thông tin và quy định lưu trữ.',
                'dept' => 'IT',
                'days' => 30,
                'cert' => true,
            ],
        ];

        foreach ($courses as $data) {
            $course = Course::create([
                'code' => $data['code'],
                'title' => $data['title'],
                'slug' => Str::slug($data['title']),
                'description' => $data['description'],
                'owner_department_id' => $departments[$data['dept']] ?? null,
                'sequential' => true,
                'status' => Course::STATUS_PUBLISHED,
                'published_at' => now()->subDays(rand(10, 60)),
                'duration_days' => $data['days'],
                'issue_certificate' => $data['cert'],
            ]);

            // Mỗi khoá 3 bài: đọc → video → kiểm tra
            Lesson::create([
                'course_id' => $course->id,
                'title' => 'Tổng quan và quy định chung',
                'content_type' => Lesson::TYPE_TEXT,
                'content_html' => '<p>Nội dung tổng quan về ' . $data['title'] . '.</p>'
                    . '<h2>Phạm vi áp dụng</h2><p>Áp dụng cho toàn bộ nhân viên thuộc phạm vi được giao.</p>',
                'sort_order' => 0,
                'estimated_minutes' => 15,
            ]);

            Lesson::create([
                'course_id' => $course->id,
                'title' => 'Tình huống thực tế',
                'content_type' => Lesson::TYPE_TEXT,
                'content_html' => '<p>Phân tích các tình huống thường gặp trong công việc hằng ngày.</p>',
                'sort_order' => 1,
                'estimated_minutes' => 20,
            ]);

            $quiz = $this->createQuizFor($course);

            Lesson::create([
                'course_id' => $course->id,
                'title' => 'Bài kiểm tra cuối khóa',
                'content_type' => Lesson::TYPE_QUIZ,
                'quiz_id' => $quiz->id,
                'sort_order' => 2,
                'estimated_minutes' => 15,
            ]);

            // Gán theo phòng ban sở hữu khoá
            if ($course->owner_department_id) {
                CourseAssignmentRule::create([
                    'course_id' => $course->id,
                    'department_id' => $course->owner_department_id,
                    'is_mandatory' => true,
                    'due_days' => $data['days'],
                    'is_active' => true,
                ]);
            }
        }
    }

    private function createQuizFor(Course $course): Quiz
    {
        $quiz = Quiz::create([
            'title' => 'Kiểm tra: ' . $course->title,
            'course_id' => $course->id,
            'duration_minutes' => 15,
            'pass_score' => 70,
            'max_attempts' => 3,
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'status' => Quiz::STATUS_PUBLISHED,
        ]);

        $bank = [
            ['Nội dung nào sau đây thuộc phạm vi áp dụng của quy định?', 'Toàn bộ nhân viên trong phạm vi được giao', 'Chỉ cấp quản lý', 'Chỉ phòng pháp chế'],
            ['Khi phát hiện dấu hiệu vi phạm, việc đầu tiên cần làm là gì?', 'Báo cáo cho bộ phận phụ trách', 'Tự xử lý rồi báo sau', 'Bỏ qua nếu chưa gây hậu quả'],
            ['Tài liệu nội bộ được phép chia sẻ ra ngoài khi nào?', 'Khi có phê duyệt của cấp có thẩm quyền', 'Khi đồng nghiệp cũ yêu cầu', 'Khi đã hết hiệu lực'],
            ['Trách nhiệm cập nhật kiến thức định kỳ thuộc về ai?', 'Từng nhân viên', 'Chỉ phòng đào tạo', 'Chỉ trưởng phòng'],
            ['Hành vi nào sau đây là vi phạm quy định bảo mật?', 'Dùng chung tài khoản đăng nhập', 'Đổi mật khẩu định kỳ', 'Khóa máy khi rời chỗ'],
        ];

        foreach ($bank as $index => $row) {
            $content = array_shift($row);

            $question = Question::create([
                'quiz_id' => $quiz->id,
                'content' => $content,
                'type' => Question::TYPE_SINGLE,
                'score' => 1,
                'sort_order' => $index,
            ]);

            foreach ($row as $optionIndex => $optionContent) {
                QuestionOption::create([
                    'question_id' => $question->id,
                    'content' => $optionContent,
                    // Đáp án đầu tiên luôn là đáp án đúng trong bộ mẫu này
                    'is_correct' => $optionIndex === 0,
                    'sort_order' => $optionIndex,
                ]);
            }
        }

        return $quiz;
    }

    private function assignCoursesToEveryone(): void
    {
        $assignment = app(CourseAssignmentService::class);

        Employee::active()->each(fn (Employee $e) => $assignment->syncForEmployee($e));

        // Gán thêm khoá An toàn thông tin cho tất cả để mọi người có ít nhất 2 khoá
        $security = Course::where('code', 'C-ATTT')->first();

        if ($security) {
            Employee::active()->each(function (Employee $employee) use ($assignment, $security) {
                $assignment->assignManually($employee, $security, mandatory: true, dueDays: 30);
            });
        }
    }

    /**
     * Mô phỏng nhiều mức tiến độ khác nhau: xong hẳn, đang học dở, chưa bắt đầu,
     * và một trường hợp quá hạn — để dashboard và báo cáo có đủ trạng thái.
     */
    private function simulateLearning(): void
    {
        $progress = app(ProgressService::class);
        $enrollments = Enrollment::with('course')->get();

        foreach ($enrollments as $index => $enrollment) {
            $lessons = $enrollment->course->lessonsInOrder()->get();

            if ($lessons->isEmpty()) {
                continue;
            }

            // Chia đều 4 nhóm trạng thái theo thứ tự enrollment
            $mode = $index % 4;

            $completeCount = match ($mode) {
                0 => $lessons->count(),                              // hoàn thành cả khoá
                1 => max(1, (int) floor($lessons->count() / 2)),     // học được nửa
                2 => 1,                                              // mới bắt đầu
                default => 0,                                        // chưa động tới
            };

            foreach ($lessons->take($completeCount) as $lesson) {
                // Bài quiz để dành cho simulateQuizAttempts xử lý qua đúng luồng chấm điểm
                if ($lesson->content_type === Lesson::TYPE_QUIZ) {
                    continue;
                }

                $progress->completeLesson($enrollment, $lesson);
            }

            // Một số bản ghi đặt hạn đã qua để thấy trạng thái quá hạn
            if ($mode === 3 && $index % 8 === 3) {
                $enrollment->update(['due_date' => now()->subDays(rand(3, 20))->toDateString()]);
                $progress->recalculate($enrollment->refresh());
            }
        }
    }

    /** Tạo lượt thi đã chấm điểm — cả đạt và chưa đạt (spec 3.2.3: lưu lịch sử từng lần). */
    private function simulateQuizAttempts(): void
    {
        $progress = app(ProgressService::class);

        $quizLessons = Lesson::where('content_type', Lesson::TYPE_QUIZ)
            ->whereNotNull('quiz_id')
            ->with('quiz.questions.options')
            ->get();

        foreach ($quizLessons as $lesson) {
            $enrollments = Enrollment::where('course_id', $lesson->course_id)
                ->whereIn('status', [Enrollment::STATUS_IN_PROGRESS, Enrollment::STATUS_COMPLETED])
                ->limit(4)
                ->get();

            foreach ($enrollments as $i => $enrollment) {
                // Người thứ hai trong danh sách trượt lần đầu rồi đạt lần hai
                $shouldFailFirst = $i === 1;

                if ($shouldFailFirst) {
                    $this->recordAttempt($lesson, $enrollment, correctRatio: 0.2, attemptNo: 1);
                }

                $attempt = $this->recordAttempt(
                    $lesson,
                    $enrollment,
                    correctRatio: 1.0,
                    attemptNo: $shouldFailFirst ? 2 : 1,
                );

                $progress->applyQuizResult($attempt);
            }
        }
    }

    /** Ghi một lượt thi đã chấm, đi đúng cấu trúc mà QuizService tạo ra. */
    private function recordAttempt(Lesson $lesson, Enrollment $enrollment, float $correctRatio, int $attemptNo): QuizAttempt
    {
        $quiz = $lesson->quiz;
        $questions = $quiz->questions;
        $correctCount = (int) round($questions->count() * $correctRatio);

        $maxScore = (float) $questions->sum('score');
        $score = 0.0;

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'employee_id' => $enrollment->employee_id,
            'lesson_id' => $lesson->id,
            'attempt_no' => $attemptNo,
            'status' => QuizAttempt::STATUS_GRADED,
            'max_score' => $maxScore,
            'started_at' => now()->subDays(rand(1, 15))->subMinutes(20),
            'submitted_at' => now()->subDays(rand(1, 15)),
            'ip_address' => '10.0.' . rand(1, 5) . '.' . rand(10, 200),
        ]);

        foreach ($questions as $index => $question) {
            $isCorrect = $index < $correctCount;
            $option = $question->options->firstWhere('is_correct', $isCorrect)
                ?? $question->options->first();

            $questionScore = $isCorrect ? (float) $question->score : 0.0;
            $score += $questionScore;

            QuizAttemptAnswer::create([
                'quiz_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'question_snapshot' => $question->content,
                'selected_option_ids' => [$option->id],
                'is_correct' => $isCorrect,
                'score' => $questionScore,
                'sort_order' => $index,
            ]);
        }

        $percentage = $maxScore > 0 ? round($score / $maxScore * 100, 2) : 0.0;

        $attempt->update([
            'score' => $score,
            'percentage' => $percentage,
            'is_passed' => $percentage >= $quiz->pass_score,
        ]);

        return $attempt->refresh();
    }

    /** Log xem tài liệu, gồm cả lượt bị từ chối để trung tâm bảo mật có dữ liệu. */
    private function createAccessLogs(): void
    {
        $service = app(DocumentAccessService::class);
        $documents = Document::published()->get();
        $employees = Employee::active()->with(['department', 'jobGrade'])->get();

        if ($documents->isEmpty() || $employees->isEmpty()) {
            return;
        }

        foreach ($employees as $employee) {
            foreach ($documents as $document) {
                $resolved = $service->resolve($employee, $document);

                // Mỗi người xem vài lượt rải rác trong 10 ngày gần đây
                $times = $resolved['can_view'] ? rand(1, 3) : 1;

                for ($i = 0; $i < $times; $i++) {
                    DocumentAccessLog::create([
                        'document_id' => $document->id,
                        'user_id' => $employee->user_id,
                        'employee_id' => $employee->id,
                        'action' => $resolved['can_view']
                            ? DocumentAccessLog::ACTION_VIEW
                            : DocumentAccessLog::ACTION_DENIED,
                        'ip_address' => '10.0.' . rand(1, 5) . '.' . rand(10, 200),
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
                        'watermark_token' => $resolved['can_view']
                            ? $service->watermarkToken($employee, $document)
                            : null,
                        'deny_reason' => $resolved['can_view'] ? null : $resolved['reason'],
                        'accessed_at' => now()->subDays(rand(0, 10))->subHours(rand(0, 23)),
                    ]);
                }
            }
        }
    }

    private function createLoginHistory(): void
    {
        $employees = Employee::active()->whereNotNull('user_id')->get();

        foreach ($employees as $employee) {
            $ip = '10.0.' . rand(1, 5) . '.' . rand(10, 200);

            // Vài lần đăng nhập thành công rải rác
            foreach (range(1, rand(3, 8)) as $i) {
                LoginHistory::create([
                    'user_id' => $employee->user_id,
                    'ip_address' => $ip,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
                    'device_label' => 'Windows · Chrome',
                    'result' => 'success',
                    'logged_in_at' => now()->subDays(rand(0, 14))->subHours(rand(0, 23)),
                ]);
            }

            // Phiên thiết bị đang hoạt động
            DeviceSession::create([
                'user_id' => $employee->user_id,
                'session_id' => Str::random(40),
                'device_label' => 'Windows · Chrome',
                'ip_address' => $ip,
                'last_activity_at' => now()->subMinutes(rand(5, 300)),
                'is_active' => true,
            ]);
        }

        // Một tài khoản có nhiều lần đăng nhập thất bại — dấu hiệu dò mật khẩu
        $target = $employees->first();

        if ($target) {
            foreach (range(1, 6) as $i) {
                LoginHistory::create([
                    'user_id' => $target->user_id,
                    'ip_address' => '203.0.113.' . rand(10, 99),
                    'device_label' => 'Linux · Trình duyệt khác',
                    'result' => 'failed',
                    'failure_reason' => 'Sai thông tin đăng nhập',
                    'logged_in_at' => now()->subMinutes(rand(5, 25)),
                ]);
            }
        }
    }

    private function createSecurityAlerts(): void
    {
        $employees = Employee::active()->whereNotNull('user_id')->get();

        if ($employees->isEmpty()) {
            return;
        }

        $samples = [
            [
                'type' => SecurityAlert::TYPE_BRUTE_FORCE,
                'severity' => 'critical',
                'title' => 'Đăng nhập thất bại nhiều lần',
                'detail' => '6 lần đăng nhập thất bại trong 30 phút.',
                'status' => SecurityAlert::STATUS_OPEN,
                'context' => ['fail_count' => 6],
            ],
            [
                'type' => SecurityAlert::TYPE_UNUSUAL_IP,
                'severity' => 'medium',
                'title' => 'Đăng nhập từ địa chỉ IP lạ',
                'detail' => 'Tài khoản đăng nhập từ IP 203.0.113.42 chưa từng xuất hiện trước đây.',
                'status' => SecurityAlert::STATUS_OPEN,
                'context' => ['ip' => '203.0.113.42'],
            ],
            [
                'type' => SecurityAlert::TYPE_MULTI_DEVICE,
                'severity' => 'medium',
                'title' => 'Vượt giới hạn thiết bị đăng nhập',
                'detail' => 'Tài khoản đăng nhập trên nhiều hơn 2 thiết bị. Đã thu hồi 1 phiên cũ.',
                'status' => SecurityAlert::STATUS_ACKNOWLEDGED,
                'context' => ['revoked_sessions' => 1, 'limit' => 2],
            ],
            [
                'type' => SecurityAlert::TYPE_MASS_DOWNLOAD,
                'severity' => 'high',
                'title' => 'Truy cập nhiều tài liệu bất thường',
                'detail' => 'Truy cập 24 tài liệu khác nhau trong 60 phút.',
                'status' => SecurityAlert::STATUS_RESOLVED,
                'context' => ['document_count' => 24, 'window_minutes' => 60],
            ],
        ];

        foreach ($samples as $index => $sample) {
            $employee = $employees[$index % $employees->count()];

            SecurityAlert::create(array_merge($sample, [
                'user_id' => $employee->user_id,
                'created_at' => now()->subDays(rand(0, 5))->subHours(rand(0, 23)),
            ]));
        }
    }

    private function createSupportRequests(): void
    {
        $employees = Employee::active()->get();

        if ($employees->isEmpty()) {
            return;
        }

        $samples = [
            ['Hợp đồng & chế độ', 'Hỏi về thời hạn hợp đồng', 'Tôi muốn biết hợp đồng của mình còn hạn đến khi nào.', 'normal', SupportRequest::STATUS_NEW, null],
            ['Nội dung bài giảng', 'Video bài học không phát được', 'Bài "Nhận diện email lừa đảo" không tải được video trên máy của tôi.', 'high', SupportRequest::STATUS_IN_PROGRESS, null],
            ['Sự cố kỹ thuật', 'Không đăng nhập được sáng nay', 'Tôi nhập đúng mật khẩu nhưng hệ thống báo sai.', 'high', SupportRequest::STATUS_RESOLVED, 'Tài khoản đã bị khóa tạm do nhập sai nhiều lần, đã mở lại lúc 9h30.'],
            ['Nội dung bài giảng', 'Đề nghị bổ sung tài liệu tham khảo', 'Mong bổ sung thêm ví dụ thực tế cho khóa AML.', 'low', SupportRequest::STATUS_NEW, null],
        ];

        foreach ($samples as $index => [$topic, $subject, $content, $priority, $status, $resolution]) {
            $employee = $employees[$index % $employees->count()];
            $createdAt = now()->subDays(rand(0, 12));

            SupportRequest::create([
                'ticket_no' => sprintf('HT-%s-%03d', $createdAt->format('ymd'), $index + 1),
                'employee_id' => $employee->id,
                'topic' => $topic,
                'subject' => $subject,
                'content' => $content,
                'priority' => $priority,
                'status' => $status,
                'resolution' => $resolution,
                'resolved_at' => $status === SupportRequest::STATUS_RESOLVED ? $createdAt->copy()->addHours(3) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
