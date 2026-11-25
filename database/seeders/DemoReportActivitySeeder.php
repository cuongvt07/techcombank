<?php

namespace Database\Seeders;

use App\Models\CourseAssignmentRule;
use App\Models\Document;
use App\Models\Employee;
use App\Models\SecurityAlert;
use App\Models\SupportRequest;
use App\Services\DocumentAccessService;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Hoạt động rải trong 180 ngày (học tập) / 90 ngày (tài liệu, đăng nhập, cảnh báo,
 * hỗ trợ) gần nhất, tính tới lúc chạy, để Tổng quan và 5 trang Báo cáo có số liệu
 * có ý nghĩa với kỳ 7/30 ngày/quý và kỳ so sánh liền trước.
 *
 * Mọi mốc tính tương đối với now() lúc chạy: dữ liệu "già" dần theo thời gian (sau
 * vài tuần kỳ 30 ngày gần như trống, mọi lượt dở thành quá hạn rất lâu) — chạy lại
 * seeder này trước khi demo. Phân bố trạng thái nhắm theo góp ý (bảng 1.2) cho kỳ 30
 * ngày: hoàn thành ~65%, đang học ~19%, chưa bắt đầu ~6%, quá hạn ~9% (5–45 ngày);
 * tiến độ theo đơn vị ~50–88% (mỗi đơn vị một mức chăm chỉ); giờ học được hiệu chỉnh
 * ~9,6 giờ/học viên hoạt động trong 30 ngày.
 *
 * Khác DemoActivitySeeder (đi qua service, dữ liệu dồn vào vài ngày), seeder này
 * insert theo lô với mốc thời gian tự dựng — vì service luôn đóng dấu now(), không
 * thể tạo lịch sử 90 ngày qua đường nghiệp vụ.
 *
 * Chạy lại được (idempotent):
 * - Enrollment/lesson_progress: upsert theo khóa unique, mỗi cặp (khóa học, nhân viên)
 *   có hạt giống ngẫu nhiên riêng nên lần chạy sau cho cùng kết quả (chỉ trượt theo now()).
 * - Bản ghi do seeder này tạo được đánh dấu rồi xóa-tạo lại mỗi lần chạy:
 *   quiz_attempts / document_access_logs / login_histories có ip_address '10.20.*',
 *   security_alerts có context.source = 'demo-report', support_requests có ticket_no 'YC-*'.
 *   Dữ liệu của seeder khác (ip 10.0.*, ticket HT-*...) không bị xóa.
 */
class DemoReportActivitySeeder extends Seeder
{
    private const DAYS = 90;

    /**
     * Ghi danh rải trong 180 ngày: đủ để kỳ "Quý này" và kỳ so sánh liền trước của nó
     * đều ở trạng thái ổn định (không có đoạn "mới bắt đầu có dữ liệu").
     */
    private const LEARNING_DAYS = 180;

    /** Giờ học trung bình mỗi học viên hoạt động trong 30 ngày gần nhất (bảng 1.2: 8–12 giờ). */
    private const HOURS_PER_ACTIVE_LEARNER = 9.6;

    /** Số nhân viên dùng để sinh nhật ký tài liệu (mỗi người phải resolve quyền từng tài liệu — chậm). */
    private const DOCUMENT_LOG_PEOPLE = 260;

    /**
     * Cường độ hoạt động theo ngày (khóa = số ngày trước hôm nay, âm = tương lai):
     * bước ngẫu nhiên có kéo về trung bình × hệ số cuối tuần. Mọi mốc học tập được
     * dời theo cường độ này nên biểu đồ xu hướng có nhiễu tự nhiên và sụt T7/CN.
     *
     * @var array<int, float>
     */
    private array $dayWeight = [];

    private float $maxDayWeight = 1.0;

    private const SEED = 20261022;

    /** Dải IP riêng để nhận ra bản ghi do seeder này sinh ra */
    private const MARK_IP = '10.20.';

    private const ALERT_SOURCE = 'demo-report';

    private const CHUNK = 500;

    private int $nowTs;

    /** 00:00 hôm nay theo giờ VN, dạng timestamp UTC */
    private int $todayStartTs;

    private const USER_AGENTS = [
        'Windows · Chrome' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
        'Windows · Edge' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0',
        'macOS · Safari' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
        'Android · Chrome' => 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
        'iOS · Safari' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
    ];

    public function run(): void
    {
        // Dữ liệu minh họa: seeder xóa-tạo lại theo dấu (IP 10.20.*, mã YC-*). Trên máy chủ thật
        // các dấu này có thể trùng dữ liệu thật (mạng nội bộ 10.20.x) nên tuyệt đối không chạy.
        if (app()->isProduction()) {
            $this->command?->warn('Bỏ qua DemoReportActivitySeeder: không chạy dữ liệu demo trên production.');

            return;
        }

        $this->nowTs = CarbonImmutable::now('UTC')->timestamp;
        $this->todayStartTs = CarbonImmutable::now(ReportPeriod::TIMEZONE)->startOfDay()->timestamp;
        $this->buildDayWeights();

        $counts = [];
        $counts += $this->seedLearning();
        $counts += $this->seedDocumentLogs();
        $counts += $this->seedLoginHistories();
        $counts += $this->seedSecurityAlerts();
        $counts += $this->seedSupportRequests();

        $this->printSummary($counts);
    }

    // ------------------------------------------------------------------
    // 1 + 2. Ghi danh, tiến độ bài học, lượt làm bài kiểm tra
    // ------------------------------------------------------------------

    private function seedLearning(): array
    {
        $courses = DB::table('courses')->where('status', 'published')->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'code']);

        $lessonsByCourse = DB::table('lessons')->whereNull('deleted_at')->where('is_required', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'course_id', 'content_type', 'quiz_id', 'estimated_minutes'])
            ->groupBy('course_id');

        $quizzes = $this->loadQuizzes();

        $employees = DB::table('employees')->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'user_id', 'department_id', 'job_title_id', 'job_grade_id', 'joined_at', 'employment_status']);

        $rules = $this->loadRules();

        // Mức chăm chỉ của từng đơn vị (0 → 1, cố định theo hạt giống): đơn vị chăm hoàn
        // thành sớm, ít lượt trễ hạn — để "Tiến độ theo đơn vị" chênh rõ (~50–90%)
        $deptIds = DB::table('departments')->whereNotNull('parent_id')->orderBy('id')->pluck('id')->all();
        mt_srand(self::SEED + 9);
        shuffle($deptIds);
        $diligence = [];
        foreach ($deptIds as $i => $id) {
            $diligence[$id] = count($deptIds) > 1 ? $i / (count($deptIds) - 1) : 0.5;
        }

        $existing = DB::table('enrollments')->get()
            ->keyBy(fn ($e) => $e->course_id . '-' . $e->employee_id);

        // Enrollment đã có chứng nhận thì phải giữ trạng thái hoàn thành
        $certified = DB::table('certificates')->pluck('enrollment_id')->flip();

        // Lượt thi do seeder khác tạo: giữ nguyên, chỉ neo mốc thời gian enrollment theo chúng
        DB::table('quiz_attempts')->where('ip_address', 'like', self::MARK_IP . '%')->delete();
        $foreignAttempts = DB::table('quiz_attempts')
            ->get(['quiz_id', 'employee_id', 'attempt_no', 'started_at', 'submitted_at', 'is_passed', 'percentage'])
            ->groupBy(fn ($a) => $a->quiz_id . '-' . $a->employee_id);

        $adminId = DB::table('users')->where('email', 'admin@techcombank.local')->value('id');

        $enrollRows = [];
        $plans = [];
        $created = 0;

        foreach ($employees as $employee) {
            $isWorking = in_array($employee->employment_status, ['official', 'probation'], true);

            // Năng lực làm bài riêng của từng người (ổn định giữa các lần chạy)
            mt_srand(self::SEED + $employee->id * 7919);
            $ability = 0.78 + $this->rnd() * 0.32;

            foreach ($courses as $course) {
                $key = $course->id . '-' . $employee->id;
                $current = $existing->get($key);
                $lessons = $lessonsByCourse->get($course->id, collect())->values();

                if ($lessons->isEmpty()) {
                    continue;
                }

                $rule = $this->matchRule($rules, $course->id, $employee);

                if (! $current) {
                    // Ghi danh thêm: theo rule thì chắc chắn có, còn lại ~80% được giao tay
                    if (! $isWorking) {
                        continue;
                    }

                    $roll = (crc32('enroll-' . $key) % 1000) / 1000;

                    if (! $rule && ! ($course->code === 'C-ATTT' || $roll < 0.8)) {
                        continue;
                    }

                    $created++;
                }

                mt_srand(self::SEED + crc32($key));

                $quizLessonIndex = null;
                foreach ($lessons as $i => $lesson) {
                    if ($lesson->content_type === 'quiz' && $lesson->quiz_id && isset($quizzes[$lesson->quiz_id])) {
                        $quizLessonIndex = $i;
                        break;
                    }
                }

                $quiz = $quizLessonIndex !== null ? $quizzes[$lessons[$quizLessonIndex]->quiz_id] : null;
                $foreign = $quiz ? $foreignAttempts->get($quiz['id'] . '-' . $employee->id) : null;

                // Thời hạn học: theo điều kiện giao khóa, gói trong 14–45 ngày cho vòng đời demo gọn
                $dueDays = $rule->due_days ?? (($rules[$course->id][0] ?? null)->due_days ?? [21, 30, 45][crc32($course->code) % 3]);

                $plan = $this->planEnrollment(
                    $course,
                    $lessons,
                    $quizLessonIndex,
                    $quiz,
                    $employee,
                    $ability,
                    forceCompleted: $current && $certified->has($current->id),
                    foreign: $foreign,
                    diligence: $diligence[$employee->department_id] ?? 0.5,
                    duration: max(14, min(45, (int) $dueDays)),
                );

                $isManual = $current ? $current->course_assignment_rule_id === null : $rule === null;

                $enrollRows[] = [
                    'course_id' => $course->id,
                    'employee_id' => $employee->id,
                    'course_assignment_rule_id' => $current ? $current->course_assignment_rule_id : ($rule->id ?? null),
                    'is_mandatory' => $current ? $current->is_mandatory : ($rule ? $rule->is_mandatory : ($course->code === 'C-ATTT' || $this->chance(0.45))),
                    'status' => $plan['status'],
                    'progress_percent' => $plan['percent'],
                    'completed_lessons' => $plan['completed_lessons'],
                    'total_lessons' => $lessons->count(),
                    'final_score' => $plan['final_score'],
                    'assigned_at' => $this->ts($plan['assigned']),
                    'started_at' => $this->ts($plan['started']),
                    'completed_at' => $this->ts($plan['completed']),
                    'due_date' => $plan['due_date'],
                    'last_accessed_at' => $this->ts($plan['last_access']),
                    'last_reminded_at' => $current->last_reminded_at ?? null,
                    'assigned_by' => $current ? $current->assigned_by : ($isManual ? $adminId : null),
                    'created_at' => $this->ts($plan['assigned']),
                    'updated_at' => $this->ts($plan['last_access'] ?? $plan['assigned']),
                ];

                $plans[$key] = $plan + ['lessons' => $lessons, 'quiz' => $quiz, 'employee_id' => $employee->id];
            }
        }


        DB::transaction(function () use ($enrollRows, $plans, $certified) {
            foreach (array_chunk($enrollRows, self::CHUNK) as $chunk) {
                DB::table('enrollments')->upsert($chunk, ['course_id', 'employee_id'], array_keys(array_diff_key($chunk[0], ['course_id' => 1, 'employee_id' => 1])));
            }

            $ids = DB::table('enrollments')->get(['id', 'course_id', 'employee_id'])
                ->mapWithKeys(fn ($e) => [$e->course_id . '-' . $e->employee_id => $e->id]);

            $this->writeLessonProgress($plans, $ids);
            $this->writeQuizAttempts($plans);

            // Chứng nhận đã phát hành: ngày cấp khớp ngày hoàn thành mới
            foreach ($ids as $key => $id) {
                if ($certified->has($id) && isset($plans[$key]['completed'])) {
                    DB::table('certificates')->where('enrollment_id', $id)
                        ->update(['issued_at' => $this->ts($plans[$key]['completed'])]);
                }
            }
        });

        $attempts = array_sum(array_map(fn ($p) => count($p['attempts']), $plans));

        return [
            'enrollments' => ['created' => $created, 'updated' => count($enrollRows) - $created],
            'quiz_attempts' => ['created' => $attempts, 'updated' => 0],
        ];
    }

    /**
     * Dựng toàn bộ dòng thời gian của một lượt ghi danh: giao → bắt đầu → từng bài → (bài kiểm tra) → hoàn thành.
     * Mọi mốc tính theo giờ VN rồi đổi ra timestamp UTC.
     */
    private function planEnrollment($course, $lessons, ?int $quizIndex, ?array $quiz, $employee, float $ability, bool $forceCompleted, $foreign, float $diligence = 0.5, int $duration = 30): array
    {
        $total = $lessons->count();
        $limit = $this->nowTs - 300;

        // 1. Ngày giao: rải đều 180 ngày (dòng ghi danh ổn định → kỳ này và kỳ trước so được
        //    với nhau); nhân viên mới vào thì giao sau ngày vào làm, khóa hội nhập giao ngay
        $joinedTs = $employee->joined_at ? CarbonImmutable::parse($employee->joined_at, ReportPeriod::TIMEZONE)->timestamp : null;
        $daysAgo = $this->rnd() * self::LEARNING_DAYS;

        if ($joinedTs && $joinedTs > $this->todayStartTs - self::LEARNING_DAYS * 86400) {
            $joinedAgo = max(0, ($this->todayStartTs - $joinedTs) / 86400);
            $isOnboarding = in_array($course->code, ['C-ONB', 'ONB-CULTURE', 'C-ATTT'], true);
            $daysAgo = $isOnboarding ? max(0, $joinedAgo - mt_rand(0, 2)) : $this->rnd() * $joinedAgo;
        }

        $assigned = $this->placeOnBusyDay($this->localTime((int) floor($daysAgo), mt_rand(8, 16), mt_rand(0, 59)));
        $assigned = min($assigned, $this->nowTs - 3600);

        // Lượt thi có sẵn của seeder khác phải nằm sau ngày giao
        $firstForeignStart = $foreign ? strtotime($foreign->min('started_at') . ' UTC') : null;
        if ($firstForeignStart) {
            $assigned = min($assigned, $firstForeignStart - mt_rand(2, 12) * 86400);
        }

        $dueDate = $this->vnDate($assigned + $duration * 86400);
        $dueStart = CarbonImmutable::parse($dueDate, ReportPeriod::TIMEZONE)->startOfDay()->timestamp;
        $dueEnd = $dueStart + 86399;

        // 2. Dòng thời gian dự kiến. Người "trễ" (tỷ lệ cao ở đơn vị kém chăm) học xong sau hạn
        //    10–45 ngày → quá hạn tối đa 45 ngày; còn lại xong trước hạn, đơn vị chăm xong sớm hơn
        if ($this->chance(0.04 + 0.45 * (1 - $diligence))) {
            $span = ($duration + 10 + $this->rnd() * 35) * 86400;
            $startAfter = $span * (0.03 + 0.27 * $this->rnd());
        } else {
            $span = $duration * 86400 * (0.1 + 0.88 * pow($this->rnd(), 1.5 + 5 * $diligence));
            $startAfter = $span * (0.01 + 0.21 * $this->rnd());
        }
        $span = max($span, 4 * 3600);

        // Mốc từng bài rải ngẫu nhiên từ lúc bắt đầu tới lúc xong (bài cuối = lúc xong), rồi dời
        // theo cường độ ngày; mốc rơi sau hiện tại nghĩa là bài đó chưa học
        $plannedStart = $assigned + max(1800, (int) $startAfter);
        $plannedEnd = $assigned + (int) $span;
        $times = [];
        for ($i = 0; $i < $total - 1; $i++) {
            $times[] = $this->placeOnBusyDay($plannedStart + (int) (($plannedEnd - $plannedStart) * $this->rnd()));
        }
        $times[] = $this->placeOnBusyDay($plannedEnd);
        sort($times);
        $floor = $assigned + 1800;
        foreach ($times as $i => $t) {
            $floor = $times[$i] = max($t, $floor + 60);
        }
        $started = max($assigned + 600, min($this->placeOnBusyDay($plannedStart), $times[0] - 300));

        $k = count(array_filter($times, fn ($t) => $t <= $limit));
        $todayStart = $this->todayStartTs;
        $overdueDays = $k < $total && $dueEnd < $this->nowTs ? (int) round(($todayStart - $dueStart) / 86400) : null;

        // 3. Ép hoàn thành: đã có chứng nhận, hoặc vừa quá hạn dưới 5 ngày (bảng 1.2: số ngày
        //    quá hạn 5–45) → coi như xong trễ trong mấy ngày qua; bài còn lại dồn tới hiện tại
        if ($k < $total && ($forceCompleted || ($overdueDays !== null && $overdueDays < 5))) {
            $started = max($assigned + 300, min($started, $limit - 7200));
            $from =max($started + 600, $k > 0 ? $times[$k - 1] + 60 : $started + 600);
            // Rải mốc xong trong khoảng còn lại (không dồn hết vào hôm nay); vừa quá hạn thì
            // phần lớn coi như xong sát hạn
            $until = $overdueDays !== null && $dueEnd > $from + 3600 && $this->chance(0.85) ? $dueEnd : $limit;
            $to = min($this->nowTs - 60, $from + 600 + (int) (max(0, $until - $from - 600) * (0.25 + 0.75 * $this->rnd())));
            $from = min($from, $to - 60);
            $times = array_merge(array_slice($times, 0, $k), $this->spread($from, $to, $total - $k));
            $k = $total;
            $overdueDays = null;
        }

        // 4. Lượt thi của seeder khác quyết định kết quả: đã đạt → xong sau lượt đạt; chưa đạt → dừng ở bài kiểm tra
        $foreignPassed = $foreign ? $foreign->firstWhere('is_passed', 1) : null;
        $lastAccess = null;

        if ($foreign) {
            $started = min($started, $firstForeignStart - 3600);
            if ($foreignPassed) {
                $end = min($limit, strtotime($foreign->where('is_passed', 1)->max('submitted_at') . ' UTC') + mt_rand(600, 7200));
                $times = $this->spread($started + 600, max($end, $started + 1200), $total);
                $k = $total;
            } else {
                $k = (int) $quizIndex;
                $times = $this->spread($started + 600, max($firstForeignStart - 600, $started + 1200), $k);
                $lastAccess = min($limit, strtotime($foreign->max('submitted_at') . ' UTC'));
            }
        }

        $completedAt = $k === $total ? $times[$total - 1] : null;
        if ($started > $limit) {
            $started = null;
        }

        if ($completedAt) {
            $lastAccess = $completedAt;
        } elseif ($started && ! $lastAccess) {
            // Lần mở gần nhất: sau bài vừa xong, trước bài kế tiếp (và trước hiện tại)
            $prev = $k > 0 ? $times[$k - 1] : $started;
            $next = min($times[$k] ?? $limit, $limit);
            $lastAccess = $prev + (int) (max(0, $next - $prev) * 0.6 * $this->rnd());
            $lastAccess = min(max($lastAccess, $prev), $limit);
        }

        $lessonTimes = array_slice($times, 0, $k);
        $duePassed = $dueEnd < $this->nowTs;

        // Bài kiểm tra: các lượt trượt trước rồi lượt đạt, hoặc đang dở/hết lượt
        $attempts = [];
        $finalScore = null;

        if ($quiz && $started && ! $foreign && $quizIndex <= $k) {
            $from = $quizIndex > 0 ? ($lessonTimes[$quizIndex - 1] ?? $started) : $started;
            $max = $quiz['max_attempts'] ?: 5;

            if ($quizIndex < $k) {
                $passAt = $lessonTimes[$quizIndex];
                $roll = $this->rnd();
                $fails = $roll < 0.68 ? 0 : ($roll < 0.92 ? 1 : 2);
                $fails = min($fails, $max - 1);
                $plan = array_merge(array_fill(0, $fails, false), [true]);
                $to = $passAt;
            } else {
                // Đang dừng ở bài kiểm tra
                $roll = $this->rnd();
                if ($roll < 0.3) {
                    $plan = [];
                } elseif ($roll < 0.5) {
                    // Hết lượt mà chưa đạt
                    $plan = array_fill(0, $max, false);
                } else {
                    $plan = array_fill(0, mt_rand(1, $max - 1), false);
                }
                $to = $lastAccess;
            }

            $n = count($plan);
            foreach ($plan as $i => $pass) {
                $submitted = $n === 1 ? $to : $from + (int) (($to - $from) * ($i + 1) / $n);
                if ($pass) {
                    $submitted = $to;
                }
                $submitted = min(max($submitted, $from + 300), $this->nowTs - 60);
                $attempts[] = $this->gradeAttempt($quiz, $ability, $pass, $i + 1, $submitted);
            }

            $passed = array_values(array_filter($attempts, fn ($a) => $a['is_passed']));
            if ($completedAt && $passed) {
                $finalScore = $passed[0]['percentage'];
            }
        } elseif ($foreignPassed && $completedAt) {
            $finalScore = (float) $foreign->where('is_passed', 1)->max('percentage');
        }

        $percent = $total > 0 ? round($k / $total * 100, 2) : 0.0;

        $status = match (true) {
            $completedAt !== null => 'completed',
            $duePassed => 'overdue',
            $started !== null => 'in_progress',
            default => 'not_started',
        };

        return [
            'assigned' => $assigned,
            'started' => $started,
            'completed' => $completedAt,
            'last_access' => $lastAccess,
            'due_date' => $dueDate,
            'status' => $status,
            'percent' => $percent,
            'completed_lessons' => $k,
            'final_score' => $finalScore,
            'lesson_times' => $lessonTimes,
            'quiz_index' => $quizIndex,
            'attempts' => $attempts,
        ];
    }

    /** Chấm một lượt: trả lời từng câu theo năng lực người làm và độ khó câu hỏi. */
    private function gradeAttempt(array $quiz, float $ability, bool $mustPass, int $attemptNo, int $submittedAt): array
    {
        $answers = [];
        $score = 0.0;

        for ($try = 0; $try < 40; $try++) {
            $answers = [];
            $score = 0.0;

            foreach ($quiz['questions'] as $q) {
                // Lượt sau làm tốt hơn lượt trước (đã ôn lại)
                $p = min(0.99, $q['easiness'] * $ability * (1 + 0.08 * ($attemptNo - 1)));
                $answers[] = $this->chance($p);
            }

            $score = $this->scoreOf($quiz, $answers);
            $passed = $quiz['max_score'] > 0 && $score / $quiz['max_score'] * 100 >= $quiz['pass_score'];

            if ($passed === $mustPass) {
                break;
            }
        }

        // Không bốc được kết quả mong muốn thì ép: đạt = đúng hết, trượt = bỏ dần câu đúng
        if ($mustPass && $this->percentOf($quiz, $answers) < $quiz['pass_score']) {
            $answers = array_fill(0, count($quiz['questions']), true);
        }
        foreach ($answers as $i => $correct) {
            if (! $mustPass && $this->percentOf($quiz, $answers) >= $quiz['pass_score'] && $correct) {
                $answers[$i] = false;
            }
        }

        $duration = $quiz['duration_minutes'] ?: 15;
        $startedAt = $submittedAt - mt_rand(2 * 60, $duration * 60 - 30);

        return [
            'answers' => $answers,
            'score' => $this->scoreOf($quiz, $answers),
            'percentage' => $this->percentOf($quiz, $answers),
            'is_passed' => $mustPass,
            'attempt_no' => $attemptNo,
            'started_at' => $startedAt,
            'submitted_at' => $submittedAt,
            'expires_at' => $startedAt + $duration * 60,
        ];
    }

    private function percentOf(array $quiz, array $answers): float
    {
        return $quiz['max_score'] > 0 ? round($this->scoreOf($quiz, $answers) / $quiz['max_score'] * 100, 2) : 0.0;
    }

    private function scoreOf(array $quiz, array $answers): float
    {
        $score = 0.0;
        foreach ($quiz['questions'] as $i => $q) {
            $score += ! empty($answers[$i]) ? $q['score'] : 0.0;
        }

        return $score;
    }

    /** Ghi lesson_progresses: upsert bài đã học, bài đang học; các dòng cũ còn lại đưa về chưa học. */
    private function writeLessonProgress(array $plans, $enrollmentIds): void
    {
        $rows = [];
        $meta = [];
        $touched = [];

        foreach ($plans as $key => $plan) {
            if (! $plan['started']) {
                continue;
            }

            $enrollmentId = $enrollmentIds[$key];
            $k = $plan['completed_lessons'];

            foreach ($plan['lessons'] as $i => $lesson) {
                if ($i > $k || ($i === $k && $plan['completed'])) {
                    break;
                }

                $isDone = $i < $k;
                $isVideo = $lesson->content_type === 'video';
                $isQuiz = $i === $plan['quiz_index'];

                // Thời gian học theo thời lượng dự kiến của bài (còn được hiệu chỉnh tổng ở dưới)
                $minutes = max(5, (int) ($lesson->estimated_minutes ?: 15));

                if ($isDone) {
                    $doneAt = $plan['lesson_times'][$i];
                    $spent = (int) ($minutes * 60 * (0.9 + 1.2 * $this->rnd()));
                    if ($isQuiz && $plan['attempts']) {
                        $spent = array_sum(array_map(fn ($a) => $a['submitted_at'] - $a['started_at'], $plan['attempts']));
                    }
                    $firstAt = $isQuiz && $plan['attempts'] ? $plan['attempts'][0]['started_at'] : $doneAt - $spent - mt_rand(0, 1800);
                    $updatedAt = $doneAt;
                } else {
                    $updatedAt = $plan['last_access'];
                    $spent = (int) ($minutes * 60 * (0.2 + 0.6 * $this->rnd()));
                    if ($isQuiz && $plan['attempts']) {
                        $spent = array_sum(array_map(fn ($a) => $a['submitted_at'] - $a['started_at'], $plan['attempts']));
                    }
                    $firstAt = $isQuiz && $plan['attempts'] ? $plan['attempts'][0]['started_at'] : $updatedAt - $spent;
                }

                $firstAt = max($firstAt, $plan['started']);
                $touched[$enrollmentId . '-' . $lesson->id] = true;

                $rows[] = [
                    'enrollment_id' => $enrollmentId,
                    'lesson_id' => $lesson->id,
                    'employee_id' => $plan['employee_id'],
                    'status' => $isDone ? 'completed' : 'in_progress',
                    'last_position_second' => $isVideo ? ($isDone ? null : mt_rand(30, 600)) : null,
                    'watch_percent' => $isVideo ? ($isDone ? 100 : mt_rand(10, 80)) : 0,
                    'time_spent_seconds' => max(60, $spent),
                    'first_accessed_at' => $this->ts($firstAt),
                    'completed_at' => $isDone ? $this->ts($doneAt) : null,
                    'created_at' => $this->ts($firstAt),
                    'updated_at' => $this->ts($updatedAt),
                ];
                $meta[] = [$updatedAt, $isQuiz && $plan['attempts']];
            }
        }

        // Hiệu chỉnh giờ học: tổng giờ của 30 ngày gần nhất = HOURS_PER_ACTIVE_LEARNER × số học
        // viên hoạt động (bảng 1.2: 6.000–9.000 giờ, TB 8–12 giờ/người). Cùng một hệ số cho mọi
        // ngày nên tỷ lệ kỳ này / kỳ trước vẫn theo nhịp hoạt động thật. Bài kiểm tra giữ thời
        // lượng làm bài thật.
        $windowStart = $this->todayStartTs - 29 * 86400;
        $active = [];
        foreach ($plans as $plan) {
            if ($plan['last_access'] && $plan['last_access'] >= $windowStart) {
                $active[$plan['employee_id']] = true;
            }
        }
        [$scalable, $fixed] = [0, 0];
        foreach ($rows as $i => $row) {
            if ($meta[$i][0] >= $windowStart) {
                if ($meta[$i][1]) {
                    $fixed += $row['time_spent_seconds'];
                } else {
                    $scalable += $row['time_spent_seconds'];
                }
            }
        }
        $target = self::HOURS_PER_ACTIVE_LEARNER * 3600 * count($active);
        $factor = $scalable > 0 ? max(0.3, min(8.0, ($target - $fixed) / $scalable)) : 1.0;
        foreach ($rows as $i => &$row) {
            if (! $meta[$i][1]) {
                $row['time_spent_seconds'] = max(60, min(4 * 3600, (int) round($row['time_spent_seconds'] * $factor)));
            }
        }
        unset($row);
        $this->command?->info(sprintf('Giờ học 30 ngày: %d học viên hoạt động, hệ số thời lượng ×%.2f', count($active), $factor));

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('lesson_progresses')->upsert($chunk, ['enrollment_id', 'lesson_id'], [
                'employee_id', 'status', 'last_position_second', 'watch_percent', 'time_spent_seconds',
                'first_accessed_at', 'completed_at', 'created_at', 'updated_at',
            ]);
        }

        // Dòng tiến độ cũ không còn khớp dòng thời gian mới → đưa về "chưa học"
        $stale = DB::table('lesson_progresses')->get(['id', 'enrollment_id', 'lesson_id'])
            ->reject(fn ($r) => isset($touched[$r->enrollment_id . '-' . $r->lesson_id]))
            ->pluck('id');

        foreach ($stale->chunk(self::CHUNK) as $chunk) {
            DB::table('lesson_progresses')->whereIn('id', $chunk->all())->update([
                'status' => 'not_started', 'completed_at' => null, 'watch_percent' => 0,
                'time_spent_seconds' => 0, 'last_position_second' => null,
            ]);
        }

        $this->command?->info('lesson_progresses: upsert ' . count($rows) . ', đặt lại ' . $stale->count());
    }

    private function writeQuizAttempts(array $plans): void
    {
        $rows = [];
        $answerPlan = [];

        foreach ($plans as $plan) {
            if (! $plan['attempts']) {
                continue;
            }

            $quiz = $plan['quiz'];
            $lessonId = $plan['lessons'][$plan['quiz_index']]->id;

            foreach ($plan['attempts'] as $a) {
                $rows[] = [
                    'quiz_id' => $quiz['id'],
                    'employee_id' => $plan['employee_id'],
                    'lesson_id' => $lessonId,
                    'attempt_no' => $a['attempt_no'],
                    'status' => 'graded',
                    'score' => $a['score'],
                    'max_score' => $quiz['max_score'],
                    'percentage' => $a['percentage'],
                    'is_passed' => $a['is_passed'],
                    'started_at' => $this->ts($a['started_at']),
                    'submitted_at' => $this->ts($a['submitted_at']),
                    'expires_at' => $this->ts($a['expires_at']),
                    'ip_address' => self::MARK_IP . mt_rand(1, 9) . '.' . mt_rand(10, 250),
                    'created_at' => $this->ts($a['started_at']),
                    'updated_at' => $this->ts($a['submitted_at']),
                ];
                $answerPlan[$quiz['id'] . '-' . $plan['employee_id'] . '-' . $a['attempt_no']] = [$quiz, $a['answers'], $a['submitted_at']];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('quiz_attempts')->insert($chunk);
        }

        $ids = DB::table('quiz_attempts')->where('ip_address', 'like', self::MARK_IP . '%')
            ->get(['id', 'quiz_id', 'employee_id', 'attempt_no']);

        mt_srand(self::SEED + 2);
        $answers = [];

        foreach ($ids as $attempt) {
            [$quiz, $correctness, $submittedAt] = $answerPlan[$attempt->quiz_id . '-' . $attempt->employee_id . '-' . $attempt->attempt_no];

            foreach ($quiz['questions'] as $i => $q) {
                $isCorrect = ! empty($correctness[$i]);
                $pool = $isCorrect ? $q['correct'] : $q['wrong'];
                $selected = $pool ? [$pool[mt_rand(0, count($pool) - 1)]] : [];

                $answers[] = [
                    'quiz_attempt_id' => $attempt->id,
                    'question_id' => $q['id'],
                    'question_snapshot' => $q['content'],
                    'selected_option_ids' => json_encode($selected),
                    'is_correct' => $isCorrect,
                    'score' => $isCorrect ? $q['score'] : 0,
                    'sort_order' => $i,
                    'created_at' => $this->ts($submittedAt),
                    'updated_at' => $this->ts($submittedAt),
                ];
            }

            if (count($answers) >= self::CHUNK) {
                DB::table('quiz_attempt_answers')->insert($answers);
                $answers = [];
            }
        }

        if ($answers) {
            DB::table('quiz_attempt_answers')->insert($answers);
        }
    }

    /** Bài kiểm tra đã xuất bản kèm câu hỏi, đáp án và độ dễ cố định cho từng câu. */
    private function loadQuizzes(): array
    {
        $quizzes = [];

        $questions = DB::table('questions')->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'quiz_id', 'content', 'score'])
            ->groupBy('quiz_id');

        $options = DB::table('question_options')->orderBy('sort_order')->get(['id', 'question_id', 'is_correct'])
            ->groupBy('question_id');

        foreach (DB::table('quizzes')->where('status', 'published')->whereNull('deleted_at')->get() as $quiz) {
            $list = [];

            foreach ($questions->get($quiz->id, collect()) as $q) {
                $opts = $options->get($q->id, collect());
                if ($opts->isEmpty()) {
                    continue;
                }

                // Câu khó / câu rất dễ để bảng phân tích câu hỏi có điểm nhấn
                $easiness = match ($q->id % 7) {
                    0 => 0.42,
                    3 => 0.55,
                    1 => 0.98,
                    default => 0.86,
                };

                $list[] = [
                    'id' => $q->id,
                    'content' => $q->content,
                    'score' => (float) $q->score,
                    'easiness' => $easiness,
                    'correct' => $opts->where('is_correct', 1)->pluck('id')->all(),
                    'wrong' => $opts->where('is_correct', 0)->pluck('id')->all(),
                ];
            }

            if (! $list) {
                continue;
            }

            $quizzes[$quiz->id] = [
                'id' => $quiz->id,
                'pass_score' => (int) $quiz->pass_score,
                'max_attempts' => $quiz->max_attempts ? (int) $quiz->max_attempts : null,
                'duration_minutes' => $quiz->duration_minutes ? (int) $quiz->duration_minutes : null,
                'questions' => $list,
                'max_score' => array_sum(array_column($list, 'score')),
            ];
        }

        return $quizzes;
    }

    private function loadRules(): array
    {
        $rules = [];

        // Phòng ban của điều kiện ở bảng nối (nhiều phòng); cùng cách tính với
        // CourseAssignmentRule::allowedDepartmentIds() — NULL = mọi phòng ban.
        $rows = CourseAssignmentRule::query()->active()->with(['departments', 'department'])->orderBy('id')->get();

        foreach ($rows as $rule) {
            $departments = $rule->allowedDepartmentIds();

            $rules[$rule->course_id][] = (object) [
                'id' => $rule->id,
                'is_mandatory' => (bool) $rule->is_mandatory,
                'departments' => $departments,
                'job_title_id' => $rule->job_title_id,
                'job_grade_id' => $rule->job_grade_id,
                'employment_status' => $rule->employment_status,
                'due_days' => $rule->due_days ? (int) $rule->due_days : null,
            ];
        }

        return $rules;
    }

    private function matchRule(array $rules, int $courseId, $employee): ?object
    {
        foreach ($rules[$courseId] ?? [] as $rule) {
            if ($rule->departments !== null && ! in_array($employee->department_id, $rule->departments)) {
                continue;
            }
            if ($rule->job_title_id && $rule->job_title_id != $employee->job_title_id) {
                continue;
            }
            if ($rule->job_grade_id && $rule->job_grade_id != $employee->job_grade_id) {
                continue;
            }
            if ($rule->employment_status && $rule->employment_status !== $employee->employment_status) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // 3. Nhật ký truy cập tài liệu
    // ------------------------------------------------------------------

    private function seedDocumentLogs(): array
    {
        mt_srand(self::SEED + 3);

        $service = app(DocumentAccessService::class);
        $documents = Document::published()->orderBy('id')->get();
        $employees = Employee::active()->whereNotNull('user_id')->with(['department', 'jobGrade'])->orderBy('id')->get();

        // Quy mô ~1.000 người: resolve quyền từng (người × tài liệu) chạy query mỗi lần nên chỉ
        // lấy một nhóm mẫu cố định để sinh nhật ký — đủ cho báo cáo truy cập tài liệu
        if ($employees->count() > self::DOCUMENT_LOG_PEOPLE) {
            $employees = $employees->shuffle(self::SEED)->take(self::DOCUMENT_LOG_PEOPLE)->sortBy('id')->values();
        }

        if ($documents->isEmpty() || $employees->isEmpty()) {
            return ['document_access_logs' => ['created' => 0, 'updated' => 0]];
        }

        // Độ phổ biến kiểu Zipf; 3 tài liệu gần như không ai mở
        $weights = [];
        $order = $documents->pluck('id')->shuffle(self::SEED)->values();
        foreach ($order as $rank => $id) {
            $weights[$id] = $rank >= $order->count() - 3 ? 0.012 : 1 / pow($rank + 1, 0.75);
        }

        $access = [];
        foreach ($employees as $employee) {
            foreach ($documents as $document) {
                $access[$employee->id][$document->id] = $service->resolve($employee, $document);
            }
        }

        $docs = $documents->keyBy('id');
        // shuffle($seed) và resolve() làm xáo trộn bộ sinh ngẫu nhiên → gieo lại để tái lập được
        mt_srand(self::SEED + 3);
        $rows = [];

        DB::transaction(function () use ($employees, $docs, $weights, $access, &$rows) {
            DB::table('document_access_logs')->where('ip_address', 'like', self::MARK_IP . '%')->delete();

            for ($d = self::DAYS - 1; $d >= 0; $d--) {
                $isWeekend = $this->isWeekend($d);
                $growth = 0.8 + 0.4 * (1 - $d / self::DAYS);
                $n = (int) round(($isWeekend ? mt_rand(3, 10) : mt_rand(50, 85)) * $growth);

                for ($i = 0; $i < $n; $i++) {
                    $employee = $employees[mt_rand(0, $employees->count() - 1)];
                    $allowed = array_filter($access[$employee->id], fn ($r) => $r['can_view']);
                    $denied = array_diff_key($access[$employee->id], $allowed);

                    $tryDenied = $denied && ($this->chance(0.05) || ! $allowed);
                    $docId = $this->weightedPick(array_intersect_key($weights, $tryDenied ? $denied : $allowed));
                    if (! $docId) {
                        continue;
                    }

                    $document = $docs[$docId];
                    $rights = $access[$employee->id][$docId];

                    $action = match (true) {
                        ! $rights['can_view'] => 'denied',
                        $document->kind === 'video' => $this->chance(0.82) ? 'stream' : 'view',
                        default => $this->weightedPick(array_filter([
                            'view' => 72,
                            'download' => $rights['can_download'] ? 20 : 0,
                            'print' => $rights['can_print'] ? 8 : 0,
                        ])),
                    };

                    $at = $this->chance(0.9) ? $this->workTime($d) : $this->offHoursTime($d);
                    $device = $this->chance(0.85) ? 'Windows · Chrome' : array_rand(self::USER_AGENTS);

                    $rows[] = [
                        'document_id' => $docId,
                        'user_id' => $employee->user_id,
                        'employee_id' => $employee->id,
                        'action' => $action,
                        'ip_address' => self::MARK_IP . mt_rand(1, 9) . '.' . mt_rand(10, 250),
                        'user_agent' => self::USER_AGENTS[$device],
                        'watermark_token' => $action !== 'denied' && $document->enable_watermark
                            ? bin2hex(pack('N*', mt_rand(), mt_rand(), mt_rand(), mt_rand()))
                            : null,
                        'duration_seconds' => match ($action) {
                            'view' => mt_rand(20, 900),
                            'stream' => mt_rand(60, max(120, (int) ($document->duration_seconds ?: 1500))),
                            default => null,
                        },
                        'deny_reason' => $action === 'denied' ? ($rights['reason'] ?? 'Không thuộc phạm vi được xem') : null,
                        'accessed_at' => $this->ts($at),
                        'created_at' => $this->ts($at),
                        'updated_at' => $this->ts($at),
                    ];
                }
            }

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('document_access_logs')->insert($chunk);
            }
        });

        return ['document_access_logs' => ['created' => count($rows), 'updated' => 0]];
    }

    // ------------------------------------------------------------------
    // 4. Lịch sử đăng nhập
    // ------------------------------------------------------------------

    private function seedLoginHistories(): array
    {
        mt_srand(self::SEED + 4);

        $userIds = DB::table('employees')->whereNull('deleted_at')
            ->whereIn('employment_status', ['official', 'probation'])
            ->whereNotNull('user_id')->orderBy('id')->pluck('user_id');
        $staffIds = DB::table('users')->whereIn('email', ['admin@techcombank.local', 'hr@techcombank.local'])->pluck('id');
        $users = $userIds->merge($staffIds)->unique()->values();

        // Mỗi người một thiết bị và dải IP quen thuộc
        $profiles = [];
        foreach ($users as $userId) {
            $profiles[$userId] = [
                'device' => $this->weightedPick(['Windows · Chrome' => 62, 'Windows · Edge' => 20, 'macOS · Safari' => 6, 'Android · Chrome' => 7, 'iOS · Safari' => 5]),
                'ip' => self::MARK_IP . mt_rand(1, 9) . '.' . mt_rand(10, 250),
                'active' => $staffIds->contains($userId) ? 0.95 : 0.4 + $this->rnd() * 0.4,
            ];
        }

        $rows = [];
        $push = function (int $userId, int $at, string $result, ?string $reason = null, ?string $ip = null, ?string $device = null) use (&$rows, $profiles) {
            $device ??= $profiles[$userId]['device'];
            $rows[] = [
                'user_id' => $userId,
                'ip_address' => $ip ?? $profiles[$userId]['ip'],
                'user_agent' => self::USER_AGENTS[$device],
                'device_label' => $device,
                'result' => $result,
                'failure_reason' => $reason,
                'logged_in_at' => $this->ts($at),
                'logged_out_at' => $result === 'success' && $this->chance(0.7)
                    ? $this->ts(min($this->nowTs, $at + mt_rand(3600, 9 * 3600)))
                    : null,
                'created_at' => $this->ts($at),
                'updated_at' => $this->ts($at),
            ];
        };

        for ($d = self::DAYS - 1; $d >= 0; $d--) {
            $isWeekend = $this->isWeekend($d);
            // Vài ngày gần đây tỷ lệ đăng nhập lỗi tăng (đợt đổi mật khẩu bắt buộc)
            $failRate = $d <= 3 ? 0.12 : 0.04;

            foreach ($users as $userId) {
                $p = $isWeekend ? 0.04 : $profiles[$userId]['active'];
                if (! $this->chance($p)) {
                    continue;
                }

                $at = $this->workTime($d, 7, 10);

                if ($this->chance($failRate)) {
                    $blocked = $this->chance(0.15);
                    $push($userId, $at - mt_rand(30, 600), $blocked ? 'blocked' : 'failed', $blocked ? 'Tài khoản đã bị khóa' : 'Sai thông tin đăng nhập');
                    if ($blocked) {
                        $at += mt_rand(1800, 5400);
                    }
                }

                $push($userId, $at, 'success');

                // Đăng nhập lại buổi chiều
                if ($this->chance(0.18)) {
                    $push($userId, $this->workTime($d, 13, 16), 'success');
                }
            }

            // Ngoài giờ: rạng sáng 00–02h, tối Chủ nhật 18–20h
            for ($j = mt_rand(0, 1); $j > 0; $j--) {
                $userId = $users[mt_rand(0, $users->count() - 1)];
                $at = $this->localTime($d, mt_rand(0, 1), mt_rand(0, 59));
                $odd = $this->chance(0.35);
                $push($userId, $at, $odd ? 'failed' : 'success', $odd ? 'Sai thông tin đăng nhập' : null, $odd ? '203.0.113.' . mt_rand(10, 99) : null, $odd ? 'Android · Chrome' : null);
            }

            if ($this->localWeekday($d) === 0) {
                foreach (range(1, mt_rand(2, 5)) as $_) {
                    $push($users[mt_rand(0, $users->count() - 1)], $this->localTime($d, mt_rand(18, 19), mt_rand(0, 59)), 'success');
                }
            }
        }

        // Bỏ các mốc rơi vào tương lai (ngày hôm nay)
        $rows = array_values(array_filter($rows, fn ($r) => strtotime($r['logged_in_at'] . ' UTC') <= $this->nowTs));

        DB::transaction(function () use ($rows) {
            DB::table('login_histories')->where('ip_address', 'like', self::MARK_IP . '%')->delete();
            // Lượt rạng sáng từ IP lạ của seeder này: dải 203.0.113.* + thiết bị Android
            // (DemoActivitySeeder cũng dùng dải đó nhưng nhãn thiết bị khác nên không bị xóa nhầm)
            DB::table('login_histories')->where('ip_address', 'like', '203.0.113.%')
                ->where('device_label', 'Android · Chrome')->delete();
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('login_histories')->insert($chunk);
            }
        });

        return ['login_histories' => ['created' => count($rows), 'updated' => 0]];
    }

    // ------------------------------------------------------------------
    // 5. Cảnh báo bảo mật
    // ------------------------------------------------------------------

    private function seedSecurityAlerts(): array
    {
        mt_srand(self::SEED + 5);

        $userIds = DB::table('employees')->whereNull('deleted_at')
            ->whereIn('employment_status', ['official', 'probation'])
            ->whereNotNull('user_id')->pluck('user_id')->values();
        $handlers = DB::table('users')->whereIn('email', ['admin@techcombank.local', 'hr@techcombank.local'])->pluck('id')->values();

        $templates = [
            SecurityAlert::TYPE_MULTI_DEVICE => [
                'weight' => 30, 'severity' => ['low' => 55, 'medium' => 45],
                'title' => 'Vượt giới hạn thiết bị đăng nhập',
                'detail' => fn () => 'Tài khoản đăng nhập trên ' . mt_rand(3, 4) . ' thiết bị trong cùng thời điểm. Đã thu hồi phiên cũ nhất.',
                'context' => fn () => ['limit' => 2, 'revoked_sessions' => 1],
            ],
            SecurityAlert::TYPE_UNUSUAL_IP => [
                'weight' => 28, 'severity' => ['low' => 25, 'medium' => 55, 'high' => 20],
                'title' => 'Đăng nhập từ địa chỉ IP lạ',
                'detail' => fn () => 'Tài khoản đăng nhập từ IP ngoài mạng nội bộ chưa từng xuất hiện trước đây.',
                'context' => fn () => ['ip' => '203.0.113.' . mt_rand(10, 250)],
            ],
            SecurityAlert::TYPE_BRUTE_FORCE => [
                'weight' => 22, 'severity' => ['medium' => 20, 'high' => 50, 'critical' => 30],
                'title' => 'Đăng nhập thất bại nhiều lần',
                'detail' => fn () => mt_rand(5, 14) . ' lần đăng nhập thất bại trong 30 phút.',
                'context' => fn () => ['fail_count' => mt_rand(5, 14), 'window_minutes' => 30],
            ],
            SecurityAlert::TYPE_MASS_DOWNLOAD => [
                'weight' => 20, 'severity' => ['medium' => 35, 'high' => 50, 'critical' => 15],
                'title' => 'Truy cập nhiều tài liệu bất thường',
                'detail' => fn () => 'Truy cập ' . mt_rand(15, 40) . ' tài liệu khác nhau trong 60 phút.',
                'context' => fn () => ['document_count' => mt_rand(15, 40), 'window_minutes' => 60],
            ],
        ];

        $rows = [];
        $count = mt_rand(78, 88);

        for ($i = 0; $i < $count; $i++) {
            $type = $this->weightedPick(array_map(fn ($t) => $t['weight'], $templates));
            $tpl = $templates[$type];
            $severity = $this->weightedPick($tpl['severity']);
            $d = (int) floor(pow($this->rnd(), 1.1) * self::DAYS);
            $created = $this->chance(0.8) ? $this->workTime($d) : $this->offHoursTime($d);
            $ageHours = ($this->nowTs - $created) / 3600;

            $status = $ageHours < 72
                ? $this->weightedPick(['open' => 45, 'acknowledged' => 30, 'resolved' => 25])
                : $this->weightedPick(['open' => 3, 'acknowledged' => 4, 'false_positive' => 9, 'resolved' => 84]);

            // Cảnh báo nghiêm trọng được ghi nhận nhanh hơn
            $ackAfter = (int) ((in_array($severity, ['critical', 'high'], true) ? 0.5 + $this->rnd() * 1.5 : 0.5 + $this->rnd() * 5.5) * 3600);
            $resolveAfter = max($ackAfter + 1800, (int) ((4 + 44 * pow($this->rnd(), 1.4)) * 3600));

            $ackAt = $status !== 'open' ? $created + $ackAfter : null;
            $resolvedAt = in_array($status, ['resolved', 'false_positive'], true) ? $created + $resolveAfter : null;

            // Mốc xử lý không được vượt hiện tại
            if ($resolvedAt && $resolvedAt > $this->nowTs) {
                $status = 'acknowledged';
                $resolvedAt = null;
            }
            if ($ackAt && $ackAt > $this->nowTs) {
                $status = 'open';
                $ackAt = null;
            }

            $handledAt = $resolvedAt ?? $ackAt;

            $rows[] = [
                'user_id' => $userIds[mt_rand(0, $userIds->count() - 1)],
                'type' => $type,
                'severity' => $severity,
                'title' => $tpl['title'],
                'detail' => $tpl['detail'](),
                'context' => json_encode($tpl['context']() + ['source' => self::ALERT_SOURCE]),
                'status' => $status,
                'handled_by' => $handledAt ? $handlers[mt_rand(0, $handlers->count() - 1)] : null,
                'handled_at' => $this->ts($handledAt),
                'acknowledged_at' => $this->ts($ackAt),
                'resolved_at' => $this->ts($resolvedAt),
                'created_at' => $this->ts($created),
                'updated_at' => $this->ts($handledAt ?? $created),
            ];
        }

        DB::transaction(function () use ($rows) {
            DB::table('security_alerts')->where('context->source', self::ALERT_SOURCE)->delete();
            DB::table('security_alerts')->insert($rows);
        });

        return ['security_alerts' => ['created' => count($rows), 'updated' => 0]];
    }

    // ------------------------------------------------------------------
    // 6. Phiếu hỗ trợ
    // ------------------------------------------------------------------

    private function seedSupportRequests(): array
    {
        mt_srand(self::SEED + 6);

        $employeeIds = DB::table('employees')->whereNull('deleted_at')
            ->whereIn('employment_status', ['official', 'probation'])->pluck('id')->values();
        $assignees = DB::table('users')->whereIn('email', ['admin@techcombank.local', 'hr@techcombank.local'])->pluck('id', 'email');

        $topics = [
            'Tài khoản & đăng nhập' => [
                'weight' => 24, 'speed' => 0.8, 'on_time' => 0.92,
                'samples' => [
                    ['Quên mật khẩu đăng nhập', 'Tôi không nhớ mật khẩu, nhờ hỗ trợ đặt lại.'],
                    ['Tài khoản bị khóa', 'Hệ thống báo tài khoản đã bị khóa sau khi nhập sai.'],
                    ['Không nhận được email kích hoạt', 'Tôi là nhân viên mới nhưng chưa nhận được email kích hoạt tài khoản.'],
                ],
                'resolution' => 'Đã đặt lại mật khẩu và mở khóa tài khoản, hướng dẫn nhân viên đăng nhập lại.',
            ],
            'Nội dung bài giảng' => [
                'weight' => 20, 'speed' => 1.0, 'on_time' => 0.93,
                'samples' => [
                    ['Video bài học không phát được', 'Video trong khóa học dừng giữa chừng và không tải tiếp.'],
                    ['Nội dung bài học có lỗi chính tả', 'Bài 2 của khóa có một số lỗi chính tả và số liệu cũ.'],
                    ['Đề nghị bổ sung tài liệu tham khảo', 'Mong bổ sung thêm ví dụ thực tế cho khóa học.'],
                ],
                'resolution' => 'Đã kiểm tra và cập nhật nội dung bài giảng, thông báo lại cho nhân viên.',
            ],
            'Hồ sơ & chế độ' => [
                'weight' => 14, 'speed' => 1.1, 'on_time' => 0.93,
                'samples' => [
                    ['Hỏi về thời hạn hợp đồng', 'Tôi muốn biết hợp đồng của mình còn hạn đến khi nào.'],
                    ['Cập nhật thông tin hồ sơ cá nhân', 'Tôi đã đổi số điện thoại, nhờ cập nhật trên hồ sơ.'],
                    ['Xin bản sao phụ lục hợp đồng', 'Nhờ gửi giúp bản sao phụ lục hợp đồng gần nhất.'],
                ],
                'resolution' => 'Phòng Nhân sự đã cập nhật hồ sơ và gửi thông tin cho nhân viên.',
            ],
            'Kỹ thuật, trình duyệt' => [
                'weight' => 18, 'speed' => 1.6, 'on_time' => 0.72,
                'samples' => [
                    ['Trang tải rất chậm', 'Trang khóa học tải rất chậm vào buổi sáng.'],
                    ['Không xem được tài liệu trên Safari', 'Tài liệu PDF không hiển thị trên Safari của máy Mac.'],
                    ['Lỗi khi tải tệp lên', 'Tải ảnh đại diện lên thì báo lỗi không xác định.'],
                ],
                'resolution' => 'Bộ phận CNTT đã xử lý lỗi, nhân viên xác nhận sử dụng bình thường.',
            ],
            'Bài kiểm tra' => [
                'weight' => 16, 'speed' => 0.9, 'on_time' => 0.93,
                'samples' => [
                    ['Xin cấp thêm lượt làm bài', 'Tôi đã dùng hết lượt làm bài kiểm tra nhưng chưa đạt.'],
                    ['Bài làm bị nộp tự động', 'Mạng chập chờn nên bài kiểm tra bị nộp khi chưa làm xong.'],
                    ['Thắc mắc đáp án câu hỏi', 'Tôi nghĩ đáp án câu 3 chưa chính xác, nhờ kiểm tra.'],
                ],
                'resolution' => 'Đã kiểm tra kết quả bài làm và cấp thêm lượt / phản hồi đáp án cho nhân viên.',
            ],
            'Khác' => [
                'weight' => 8, 'speed' => 1.0, 'on_time' => 0.93,
                'samples' => [
                    ['Góp ý giao diện', 'Đề nghị thêm chế độ tối cho hệ thống.'],
                    ['Hỏi lịch đào tạo quý tới', 'Cho tôi hỏi lịch các khóa học bắt buộc quý tới.'],
                ],
                'resolution' => 'Đã phản hồi thông tin cho nhân viên.',
            ],
        ];

        $tickets = [];

        for ($d = self::DAYS - 1; $d >= 0; $d--) {
            $growth = 0.78 + 0.3 * (1 - $d / self::DAYS);
            $n = $this->isWeekend($d) ? mt_rand(0, 1) : (int) round(mt_rand(2, 5) * $growth);

            for ($i = 0; $i < $n; $i++) {
                $created = $this->chance(0.88) ? $this->workTime($d) : $this->offHoursTime($d);
                if ($created > $this->nowTs - 600) {
                    continue;
                }

                $topic = $this->weightedPick(array_map(fn ($t) => $t['weight'], $topics));
                $tpl = $topics[$topic];
                [$subject, $content] = $tpl['samples'][mt_rand(0, count($tpl['samples']) - 1)];
                $priority = $this->weightedPick(['high' => 20, 'normal' => 55, 'low' => 25]);
                $slaHours = SupportRequest::SLA_HOURS[$priority];
                $ageDays = ($this->nowTs - $created) / 86400;

                $pOpen = $ageDays < 3 ? 0.7 : ($ageDays < 10 ? 0.3 : 0.05);
                $isOpen = $this->chance($pOpen);
                $resolvedAt = null;
                $closedAt = null;

                if (! $isOpen) {
                    $onTime = $this->chance($tpl['on_time']);
                    $factor = $onTime ? 0.08 + 0.87 * pow($this->rnd(), 1.4) : 1.05 + 2 * $this->rnd();
                    $resolvedAt = $created + (int) ($slaHours * 3600 * min($factor * $tpl['speed'], $onTime ? 0.98 : 99));

                    if ($resolvedAt > $this->nowTs - 300) {
                        $isOpen = true;
                        $resolvedAt = null;
                    } elseif ($this->chance(0.65)) {
                        $closedAt = $resolvedAt + mt_rand(4, 72) * 3600;
                        if ($closedAt > $this->nowTs) {
                            $closedAt = null;
                        }
                    }
                }

                // Phiếu mở: ~40% chưa ai nhận
                $unassigned = $isOpen && $this->chance(0.4);
                $status = match (true) {
                    $closedAt !== null => SupportRequest::STATUS_CLOSED,
                    $resolvedAt !== null => SupportRequest::STATUS_RESOLVED,
                    $unassigned => SupportRequest::STATUS_NEW,
                    default => SupportRequest::STATUS_IN_PROGRESS,
                };

                $assignee = $topic === 'Hồ sơ & chế độ' ? 'hr@techcombank.local'
                    : ($this->chance(0.7) ? 'admin@techcombank.local' : 'hr@techcombank.local');

                $tickets[] = [
                    'employee_id' => $employeeIds[mt_rand(0, $employeeIds->count() - 1)],
                    'topic' => $topic,
                    'subject' => $subject,
                    'content' => $content,
                    'priority' => $priority,
                    'status' => $status,
                    'assigned_to' => $unassigned ? null : $assignees[$assignee] ?? null,
                    'resolution' => $resolvedAt ? $tpl['resolution'] : null,
                    'resolved_at' => $this->ts($resolvedAt),
                    'sla_due_at' => $this->ts($created + $slaHours * 3600),
                    'closed_at' => $this->ts($closedAt),
                    'created_at' => $this->ts($created),
                    'updated_at' => $this->ts($closedAt ?? $resolvedAt ?? $created),
                ];
            }
        }

        usort($tickets, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));

        DB::transaction(function () use (&$tickets) {
            DB::table('support_requests')->where('ticket_no', 'like', 'YC-%')->delete();

            // Đánh số YC-YYYY-NNNN nối tiếp số lớn nhất còn lại của từng năm
            $next = [];
            foreach ($tickets as &$ticket) {
                $year = substr($ticket['created_at'], 0, 4);
                $next[$year] ??= (int) DB::table('support_requests')->where('ticket_no', 'like', "YC-{$year}-%")
                    ->selectRaw('MAX(CAST(SUBSTRING(ticket_no, 9) AS UNSIGNED)) AS m')->value('m');
                $ticket = ['ticket_no' => sprintf('YC-%s-%04d', $year, ++$next[$year])] + $ticket;
            }
            unset($ticket);

            foreach (array_chunk($tickets, self::CHUNK) as $chunk) {
                DB::table('support_requests')->insert($chunk);
            }
        });

        return ['support_requests' => ['created' => count($tickets), 'updated' => 0]];
    }

    // ------------------------------------------------------------------
    // Tiện ích thời gian / ngẫu nhiên
    // ------------------------------------------------------------------

    /**
     * Cường độ hoạt động từng ngày: bước ngẫu nhiên AR(1) quanh 1 (khoảng ×0,8–×1,3,
     * có nhiễu ngày) và chỉ còn ~20–30% vào Thứ Bảy/Chủ nhật. Gieo hạt cố định.
     */
    private function buildDayWeights(): void
    {
        mt_srand(self::SEED + 11);
        $x = 0.0;

        for ($d = self::LEARNING_DAYS + 15; $d >= -150; $d--) {
            $x = 0.85 * $x + ($this->rnd() - 0.5) * 0.22;
            $w = exp($x) * (0.9 + 0.2 * $this->rnd());
            if ($this->isWeekend($d)) {
                $w *= 0.18 + 0.1 * $this->rnd();
            }
            $this->dayWeight[$d] = $w;
        }

        $this->maxDayWeight = max($this->dayWeight);
    }

    /** Số ngày trước hôm nay (giờ VN) của một mốc: 0 = hôm nay, âm = tương lai. */
    private function daysAgoOf(int $ts): int
    {
        return (int) ceil(($this->todayStartTs - $ts) / 86400);
    }

    /**
     * Dời một mốc theo cường độ ngày: ngày "vắng" (cuối tuần, đáy của bước ngẫu nhiên)
     * hay bị chuyển sang ngày đông hơn trong ±3 ngày, giờ đặt lại vào giờ làm việc.
     * Mốc đã ở quá khứ thì không bị đẩy sang tương lai.
     */
    private function placeOnBusyDay(int $ts): int
    {
        $d = $this->daysAgoOf($ts);
        if (! isset($this->dayWeight[$d]) || $this->rnd() < $this->dayWeight[$d] / $this->maxDayWeight) {
            return $ts;
        }

        $candidates = [];
        for ($k = -3; $k <= 3; $k++) {
            $candidates[$d + $k] = $this->dayWeight[$d + $k] ?? 0.0;
        }
        $day = (int) $this->weightedPick($candidates);
        $hour = $this->chance(0.9) ? mt_rand(8, 17) : mt_rand(19, 21);
        $moved = $this->localTime($day, $hour, mt_rand(0, 59));

        return $ts <= $this->nowTs && $moved > $this->nowTs - 300 ? $ts : $moved;
    }

    /** $n mốc tăng dần trong [from, to], mốc cuối đúng bằng to. */
    private function spread(int $from, int $to, int $n): array
    {
        if ($n <= 0) {
            return [];
        }

        $points = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $points[] = $from + (int) (max(0, $to - $from) * $this->rnd());
        }
        sort($points);
        $points[] = max($to, $points ? end($points) : $from);

        return $points;
    }

    private function rnd(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function chance(float $p): bool
    {
        return $this->rnd() < $p;
    }

    /** Chọn khóa theo trọng số (mảng khóa => trọng số). */
    private function weightedPick(array $weights): int|string|null
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return null;
        }

        $r = $this->rnd() * $sum;
        foreach ($weights as $key => $w) {
            $r -= $w;
            if ($r <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /** Mốc giờ VN cụ thể của ngày cách hôm nay $daysAgo ngày, trả về timestamp UTC. */
    private function localTime(int $daysAgo, int $hour, int $minute): int
    {
        return $this->todayStartTs - $daysAgo * 86400 + $hour * 3600 + $minute * 60 + mt_rand(0, 59);
    }

    /** Giờ hành chính ngẫu nhiên (giờ VN) của một ngày; với hôm nay không vượt quá hiện tại. */
    private function workTime(int $daysAgo, int $fromHour = 8, int $toHour = 17): int
    {
        $ts = $this->localTime($daysAgo, mt_rand($fromHour, $toHour - 1), mt_rand(0, 59));

        return $this->clampToNow($ts, $daysAgo);
    }

    /** Ngoài giờ: sáng sớm 6–7h hoặc tối 19–23h. */
    private function offHoursTime(int $daysAgo): int
    {
        $hour = $this->chance(0.3) ? mt_rand(6, 7) : mt_rand(19, 22);

        return $this->clampToNow($this->localTime($daysAgo, $hour, mt_rand(0, 59)), $daysAgo);
    }

    private function clampToNow(int $ts, int $daysAgo): int
    {
        if ($ts <= $this->nowTs - 60) {
            return $ts;
        }

        $dayStart = $this->todayStartTs - $daysAgo * 86400;

        return $dayStart + mt_rand(0, max(60, $this->nowTs - 60 - $dayStart));
    }

    /** Dời mốc rơi vào nửa đêm về giờ hành chính cùng ngày (giờ VN). */
    private function snapToDay(int $ts): int
    {
        $local = CarbonImmutable::createFromTimestamp($ts, ReportPeriod::TIMEZONE);
        if ($local->hour >= 7 && $local->hour < 22) {
            return $ts;
        }

        return $local->setTime(mt_rand(8, 17), mt_rand(0, 59), mt_rand(0, 59))->timestamp;
    }

    private function localWeekday(int $daysAgo): int
    {
        return (int) CarbonImmutable::createFromTimestamp($this->todayStartTs - $daysAgo * 86400 + 43200, ReportPeriod::TIMEZONE)->dayOfWeek;
    }

    private function isWeekend(int $daysAgo): bool
    {
        return in_array($this->localWeekday($daysAgo), [0, 6], true);
    }

    private function vnDate(int $ts): string
    {
        return CarbonImmutable::createFromTimestamp($ts, ReportPeriod::TIMEZONE)->toDateString();
    }

    private function ts(?int $ts): ?string
    {
        return $ts === null ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    private function printSummary(array $counts): void
    {
        if (! $this->command) {
            return;
        }

        $tables = [
            'enrollments' => 'assigned_at',
            'lesson_progresses' => 'updated_at',
            'quiz_attempts' => 'submitted_at',
            'quiz_attempt_answers' => 'created_at',
            'document_access_logs' => 'accessed_at',
            'login_histories' => 'logged_in_at',
            'security_alerts' => 'created_at',
            'support_requests' => 'created_at',
        ];

        $rows = [];
        foreach ($tables as $table => $column) {
            $stat = DB::table($table)->selectRaw("COUNT(*) AS n, MIN({$column}) AS mi, MAX({$column}) AS ma")->first();
            $rows[] = [
                $table, $column, $stat->n, $stat->mi, $stat->ma,
                $counts[$table]['created'] ?? '-', $counts[$table]['updated'] ?? '-',
            ];
        }

        $this->command->table(['Bảng', 'Cột mốc', 'COUNT', 'MIN (UTC)', 'MAX (UTC)', 'Tạo', 'Sửa'], $rows);
    }
}
