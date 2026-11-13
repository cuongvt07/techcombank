<?php

namespace App\Services\Reports;

use App\Livewire\Admin\Reports\QuizResultReport;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Quiz;
use App\Models\SecurityAlert;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\DashboardFilter;
use App\Services\DashboardMetricsService;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\TemplateReport;

/**
 * Dựng "báo cáo khuôn mẫu" (TemplateReport) cho từng màn báo cáo từ query string —
 * cùng tên tham số với URL của màn đó (#[Url] của component), nên nút "Xuất báo cáo (mẫu)"
 * chỉ việc chuyển nguyên bộ lọc đang xem sang. Mọi số liệu lấy từ service sẵn có của
 * từng báo cáo; ở đây chỉ đọc + làm sạch tham số và định dạng bảng.
 */
class TemplateReportService
{
    /** Khóa mẫu → [route màn báo cáo, quyền xem]. */
    public const REPORTS = [
        'tong-quan' => ['admin.dashboard', 'dashboard.view'],
        'tien-do-hoc-tap' => ['admin.reports.learning', 'report_learning.view'],
        'ket-qua-kiem-tra' => ['admin.reports.quiz', 'report_quiz.view'],
        'truy-cap-tai-lieu' => ['admin.reports.documents', 'report_documents.view'],
        'bao-mat-dang-nhap' => ['admin.reports.security', 'report_security.view'],
        'yeu-cau-ho-tro' => ['admin.reports.support', 'report_support.view'],
    ];

    public static function permission(string $key): ?string
    {
        return self::REPORTS[$key][1] ?? null;
    }

    /** @param  array<string, mixed>  $query  query string của request */
    public function build(string $key, array $query, User $user): TemplateReport
    {
        $back = route(self::REPORTS[$key][0], array_diff_key($query, ['dinh-dang' => true]));
        $preparedBy = $user->employee?->full_name ?: $user->name;

        return match ($key) {
            'tong-quan' => $this->dashboard($query, $preparedBy, $back),
            'tien-do-hoc-tap' => $this->learning($query, $preparedBy, $back),
            'ket-qua-kiem-tra' => $this->quiz($query, $preparedBy, $back),
            'truy-cap-tai-lieu' => $this->documents($query, $preparedBy, $back),
            'bao-mat-dang-nhap' => $this->security($query, $user, $preparedBy, $back),
            'yeu-cau-ho-tro' => $this->support($query, $preparedBy, $back),
            default => throw new \InvalidArgumentException("Không có báo cáo mẫu '{$key}'."),
        };
    }

    // ---- Mẫu 1: Hiệu quả chương trình đào tạo (từ Tiến độ học tập) -----------

    public function learning(array $q, ?string $preparedBy = null, ?string $back = null): TemplateReport
    {
        $period = $this->period($q, 366);
        $filters = $this->learningFilters($q);
        $data = app(TrainingEffectivenessReportService::class)->build($filters, $period);
        $svc = TrainingEffectivenessReportService::class;

        $rows = $data['rows']->values()->map(fn (array $r, int $i) => [
            TemplateReport::num($i + 1),
            TemplateReport::text($r['course'], ['bold' => true]),
            TemplateReport::text($r['unit']),
            TemplateReport::num($r['employees']),
            TemplateReport::num($r['on_time']),
            TemplateReport::pct($r['on_time_rate'], 0, TemplateReport::tone($r['on_time_rate'], ...$svc::TONE_ON_TIME)),
            TemplateReport::num($r['avg_score'], 1, TemplateReport::tone($r['avg_score'], ...$svc::TONE_SCORE)),
            TemplateReport::pct($r['first_pass_rate'], 0, TemplateReport::tone($r['first_pass_rate'], ...$svc::TONE_FIRST_PASS)),
            TemplateReport::num($r['avg_days'], 0),
            TemplateReport::text($r['rating'] ?? '—', [
                'align' => 'center', 'bold' => true, 'tone' => $svc::RATING_TONES[$r['rating']] ?? null,
            ]),
        ])->all();

        $t = $data['totals'];
        $notes = [
            'Đánh giá theo chỉ số tổng hợp = trung bình (Tỷ lệ hoàn thành đúng hạn, Điểm KT TB, Tỷ lệ đạt lần 1): '
                . '≥ 90 Xuất sắc · ≥ 80 Rất tốt · ≥ 70 Tốt · < 70 Cần cải thiện.',
            'Màu số: xanh lá = tốt (đúng hạn / đạt lần 1 ≥ 85%, điểm ≥ 80) · xanh dương = đạt (≥ 70) · đỏ = dưới ngưỡng. '
                . 'Điểm KT và tỷ lệ đạt tính trên lượt làm lần đầu; TG ĐT = từ ngày giao tới ngày hoàn thành.',
            'Phạm vi: lượt ghi danh theo ' . mb_strtolower(LearningProgressReportService::BASIS_LABELS[$filters['basis']])
                . ' trong kỳ, không tính lượt đã hủy' . ($filters['course'] ? ' · Khóa: ' . (Course::find($filters['course'])?->title ?? '—') : '') . '.',
        ];

        return new TemplateReport(
            key: 'tien-do-hoc-tap',
            title: 'BÁO CÁO HIỆU QUẢ CHƯƠNG TRÌNH ĐÀO TẠO',
            periodLabel: $period->label(),
            unitLabel: $this->departmentLabel($filters['department']),
            summary: [
                'Tổng ' . $this->int($data['employees']) . ' nhân viên đào tạo',
                'Hoàn thành đúng hạn: ' . $this->int($t['on_time']) . ' (' . $this->pct($t['on_time_rate']) . ')',
                'Điểm KT TB: ' . $this->dec($t['avg_score']),
                'TG ĐT TB: ' . ($t['avg_days'] === null ? '—' : $this->int($t['avg_days']) . ' ngày'),
                'Tỷ lệ đạt KT lần 1: ' . $this->pct($t['first_pass_rate']),
            ],
            columns: [
                ['label' => 'STT', 'width' => 6, 'align' => 'center'],
                ['label' => 'Chương trình đào tạo', 'width' => 34],
                ['label' => 'Đơn vị', 'width' => 22],
                ['label' => 'Số NV', 'width' => 9, 'align' => 'center'],
                ['label' => 'Số NV', 'group' => 'Hoàn thành đúng hạn', 'width' => 10, 'align' => 'center'],
                ['label' => 'Tỷ lệ', 'group' => 'Hoàn thành đúng hạn', 'width' => 10, 'align' => 'center'],
                ['label' => 'Điểm KT TB', 'width' => 11, 'align' => 'center'],
                ['label' => 'Tỷ lệ đạt lần 1', 'width' => 12, 'align' => 'center'],
                ['label' => 'TG ĐT TB (ngày)', 'width' => 12, 'align' => 'center'],
                ['label' => 'Đánh giá', 'width' => 16, 'align' => 'center'],
            ],
            rows: TemplateReport::capRows($rows, $notes),
            totals: [
                TemplateReport::text('Tổng cộng', ['span' => 3, 'align' => 'right']),
                TemplateReport::num($t['employees']),
                TemplateReport::num($t['on_time']),
                TemplateReport::pct($t['on_time_rate']),
                TemplateReport::num($t['avg_score'], 1),
                TemplateReport::pct($t['first_pass_rate']),
                TemplateReport::num($t['avg_days'], 0),
                TemplateReport::text(''),
            ],
            notes: $notes,
            preparedBy: $preparedBy,
            landscape: true,
            fileBase: 'bao-cao-hieu-qua-dao-tao',
            sheetTitle: 'Hiệu quả đào tạo',
            emptyText: 'Không có lượt ghi danh nào trong phạm vi lọc.',
            backUrl: $back,
        );
    }

    /** Bộ lọc nền của Báo cáo tiến độ học tập từ URL (cùng tên tham số với màn). */
    public function learningFilters(array $q): array
    {
        $basis = $this->str($q, 'moc');
        $progress = fn (string $key) => is_numeric($q[$key] ?? null) ? max(0, min(100, (int) $q[$key])) : null;

        return [
            'basis' => array_key_exists($basis, LearningProgressReportService::BASIS_LABELS) ? $basis : 'due',
            'department' => ctype_digit($this->str($q, 'don-vi')) ? (int) $q['don-vi'] : null,
            'course' => ctype_digit($this->str($q, 'khoa')) ? (int) $q['khoa'] : null,
            'courseType' => in_array($this->str($q, 'loai'), ['mandatory', 'elective'], true) ? $q['loai'] : '',
            'progressMin' => $progress('tien-do-tu'),
            'progressMax' => $progress('tien-do-den'),
            'source' => in_array($this->str($q, 'nguon'), ['rule', 'manual'], true) ? $q['nguon'] : '',
            'excludeResigned' => $this->bool($q, 'bo-nghi', false),
        ];
    }

    // ---- Kết quả kiểm tra -------------------------------------------------------

    public function quiz(array $q, ?string $preparedBy = null, ?string $back = null): TemplateReport
    {
        $period = $this->period($q, 366);
        $service = app(QuizResultReportService::class);
        $f = [
            'course_id' => ctype_digit($this->str($q, 'khoa')) ? $q['khoa'] : null,
            'quiz_id' => ctype_digit($this->str($q, 'bai')) ? $q['bai'] : null,
            'department_id' => ctype_digit($this->str($q, 'don-vi')) ? $q['don-vi'] : null,
            'result' => array_key_exists($this->str($q, 'ket-qua'), QuizResultReportService::RESULT_LABELS) ? $q['ket-qua'] : '',
            'search' => $this->str($q, 'q'),
            'attempt' => in_array($this->str($q, 'lan'), ['first', 'last'], true) ? $q['lan'] : '',
            'attempt_result' => in_array($this->str($q, 'kq-luot'), ['passed', 'failed'], true) ? $q['kq-luot'] : '',
            'band' => ctype_digit($this->str($q, 'khoang')) ? min(9, (int) $q['khoang']) : null,
        ];
        $sort = $this->str($q, 'sx');
        $dir = $this->str($q, 'chieu') === 'desc' ? 'desc' : 'asc';

        $scope = array_filter([
            $f['course_id'] ? 'Khóa: ' . (Course::find((int) $f['course_id'])?->title ?? '—') : null,
            $f['quiz_id'] ? 'Bài: ' . (Quiz::find((int) $f['quiz_id'])?->title ?? '—') : null,
        ]);
        $notes = ['Chỉ tính lượt đã nộp trong kỳ; đạt = điểm ≥ điểm đạt hiện tại của bài kiểm tra.'];

        if ($this->str($q, 'tab') === 'questions') {
            $kpi = $service->attemptKpis($f, $period);
            $questions = $service->enrichQuestions(
                $service->questionTable($f, $period, $sort ?: 'rate', $dir)->limit(TemplateReport::MAX_ROWS)->get(), $f, $period
            );
            $rows = $questions->values()->map(fn ($r, $i) => [
                TemplateReport::num($i + 1),
                TemplateReport::text($r->quiz_title),
                TemplateReport::text(strip_tags((string) $r->content)),
                TemplateReport::text(QuizResultReport::typeLabel($r->type), ['align' => 'center']),
                TemplateReport::num($r->shown),
                TemplateReport::pct($r->correct_rate, 1, TemplateReport::tone($r->correct_rate,
                    QuizResultReportService::REVIEW_BELOW, QuizResultReportService::TOO_HARD_BELOW)),
                TemplateReport::text($r->top_wrong ? $r->top_wrong['text'] . ' (' . $this->pct($r->top_wrong['pct'], 1) . ')' : '—'),
                TemplateReport::text($r->flag['label'] ?? '—', ['align' => 'center', 'tone' => match ($r->flag['tone'] ?? null) {
                    'danger' => 'bad', 'outline' => 'warn', 'muted' => 'muted', default => null,
                }]),
            ])->all();
            $notes[] = '% trả lời đúng: đỏ < ' . QuizResultReportService::TOO_HARD_BELOW . '% · xanh dương < '
                . QuizResultReportService::REVIEW_BELOW . '% · xanh lá từ ' . QuizResultReportService::REVIEW_BELOW . '%.';

            return new TemplateReport(
                key: 'ket-qua-kiem-tra',
                title: 'BÁO CÁO PHÂN TÍCH CÂU HỎI KIỂM TRA',
                periodLabel: $period->label(),
                unitLabel: implode(' · ', ['Tất cả đơn vị', ...$scope]),
                summary: [
                    'Lượt nộp: ' . $this->int($kpi['attempts']) . ' (' . $this->int($kpi['employees']) . ' nhân viên)',
                    'Lượt đạt: ' . $this->int($kpi['passed']) . ' (' . $this->pct($kpi['pass_rate'], 1) . ')',
                    'Điểm TB: ' . $this->dec($kpi['avg_score']),
                    'Số câu hỏi: ' . $this->int(count($rows)),
                ],
                columns: [
                    ['label' => 'STT', 'width' => 6, 'align' => 'center'],
                    ['label' => 'Bài kiểm tra', 'width' => 24],
                    ['label' => 'Nội dung câu hỏi', 'width' => 46],
                    ['label' => 'Loại', 'width' => 14, 'align' => 'center'],
                    ['label' => 'Số lần ra', 'width' => 10, 'align' => 'center'],
                    ['label' => '% trả lời đúng', 'width' => 11, 'align' => 'center'],
                    ['label' => 'Đáp án sai chọn nhiều nhất', 'width' => 30],
                    ['label' => 'Cờ chất lượng', 'width' => 13, 'align' => 'center'],
                ],
                rows: $rows,
                notes: $notes,
                preparedBy: $preparedBy,
                fileBase: 'bao-cao-phan-tich-cau-hoi',
                sheetTitle: 'Phân tích câu hỏi',
                emptyText: 'Không có lượt nộp bài nào trong phạm vi lọc.',
                backUrl: $back,
            );
        }

        $kpi = $service->pairKpis($f, $period);
        $all = $service->employeeTable($f, $period, $sort, $dir)->limit(TemplateReport::MAX_ROWS + 1)->get();
        $rows = $all->take(TemplateReport::MAX_ROWS)->values()->map(function ($r, $i) {
            $passTone = fn ($v) => $v === null ? null : ((float) $v >= (float) $r->pass_score ? 'great' : 'bad');

            return [
                TemplateReport::num($i + 1),
                TemplateReport::text($r->employee_code, ['align' => 'center']),
                TemplateReport::text($r->full_name, ['bold' => true]),
                TemplateReport::text($r->department_name ?? '—'),
                TemplateReport::text($r->quiz_title),
                TemplateReport::text($this->int($r->used) . ' / ' . ($r->allowed === null ? '∞' : $this->int($r->allowed)), ['align' => 'center']),
                TemplateReport::num($r->first_pct === null ? null : (float) $r->first_pct, 1, $passTone($r->first_pct)),
                TemplateReport::num($r->best_pct === null ? null : (float) $r->best_pct, 1, $passTone($r->best_pct)),
                TemplateReport::text(QuizResultReportService::RESULT_LABELS[$r->final_result] ?? $r->final_result, [
                    'align' => 'center',
                    'tone' => ['passed' => 'great', 'retry' => 'warn', 'exhausted' => 'bad'][$r->final_result] ?? null,
                ]),
                TemplateReport::num($r->pass_no === null ? null : (int) $r->pass_no),
            ];
        })->all();

        if ($all->count() > TemplateReport::MAX_ROWS) {
            $notes[] = 'Bản mẫu chỉ hiển thị ' . $this->int(TemplateReport::MAX_ROWS) . ' dòng đầu — dùng "Xuất dữ liệu" để lấy đủ.';
        }

        $shown = $all->take(TemplateReport::MAX_ROWS);
        $avg = fn (string $col) => $shown->whereNotNull($col)->isEmpty() ? null : (float) $shown->whereNotNull($col)->avg($col);

        return new TemplateReport(
            key: 'ket-qua-kiem-tra',
            title: 'BÁO CÁO KẾT QUẢ KIỂM TRA',
            periodLabel: $period->label(),
            unitLabel: implode(' · ', [$this->departmentLabel($f['department_id'] ? (int) $f['department_id'] : null), ...$scope]),
            summary: [
                'Tổng ' . $this->int($kpi['total']) . ' lượt NV × bài (' . $this->int($kpi['employees']) . ' nhân viên)',
                'Đã đạt: ' . $this->int($kpi['passed']) . ' (' . $this->pct($kpi['passed_rate']) . ')',
                'Đạt lần đầu: ' . $this->int($kpi['pass1']) . ' (' . $this->pct($kpi['first_pass_rate']) . ')',
                'Chưa đạt còn lượt: ' . $this->int($kpi['retry']),
                'Hết lượt chưa đạt: ' . $this->int($kpi['exhausted']),
            ],
            columns: [
                ['label' => 'STT', 'width' => 6, 'align' => 'center'],
                ['label' => 'Mã NV', 'width' => 11, 'align' => 'center'],
                ['label' => 'Họ tên', 'width' => 22],
                ['label' => 'Đơn vị', 'width' => 20],
                ['label' => 'Bài kiểm tra', 'width' => 30],
                ['label' => 'Lượt đã làm / tối đa', 'width' => 11, 'align' => 'center'],
                ['label' => 'Lần đầu', 'group' => 'Điểm (%)', 'width' => 9, 'align' => 'center'],
                ['label' => 'Cao nhất', 'group' => 'Điểm (%)', 'width' => 9, 'align' => 'center'],
                ['label' => 'Kết quả', 'width' => 16, 'align' => 'center'],
                ['label' => 'Lượt đến khi đạt', 'width' => 10, 'align' => 'center'],
            ],
            rows: $rows,
            totals: $rows === [] ? null : [
                TemplateReport::text('Tổng cộng', ['span' => 5, 'align' => 'right']),
                TemplateReport::num($shown->sum(fn ($r) => (int) $r->used)),
                TemplateReport::num($avg('first_pct'), 1),
                TemplateReport::num($avg('best_pct'), 1),
                TemplateReport::text('Đạt ' . $this->int($shown->where('final_result', 'passed')->count()) . ' / ' . $this->int($shown->count()), ['align' => 'center']),
                TemplateReport::num($avg('pass_no'), 1),
            ],
            notes: [...$notes, 'Điểm xanh lá = đạt điểm chuẩn của bài, đỏ = chưa đạt. Dòng Tổng cộng: tổng lượt làm, điểm trung bình, số dòng đã đạt.'],
            preparedBy: $preparedBy,
            fileBase: 'bao-cao-ket-qua-kiem-tra',
            sheetTitle: 'Kết quả kiểm tra',
            emptyText: 'Không có lượt nộp bài nào trong phạm vi lọc.',
            backUrl: $back,
        );
    }

    // ---- Truy cập tài liệu ------------------------------------------------------

    public function documents(array $q, ?string $preparedBy = null, ?string $back = null): TemplateReport
    {
        $period = $this->period($q, 366);
        $service = app(DocumentAccessReportService::class);
        $f = [
            'category_id' => ctype_digit($this->str($q, 'dm')) ? (int) $q['dm'] : null,
            'confidentiality' => array_key_exists($this->str($q, 'mat'), DocumentAccessReportService::CONFIDENTIALITY_LABELS) ? $q['mat'] : null,
            'action' => array_key_exists($this->str($q, 'hd'), DocumentAccessReportService::ACTION_LABELS) ? $q['hd'] : null,
            'owner_department_id' => ctype_digit($this->str($q, 'dv')) ? (int) $q['dv'] : null,
        ];
        $sortable = ['title', 'category', 'version', 'published_at', 'granted', 'viewers', 'reach', 'downloads', 'denied'];
        $sort = in_array($this->str($q, 'sx'), $sortable, true) ? $q['sx'] : 'reach';

        $kpis = $service->kpis($period, $f);
        $docs = $service->sortRows($service->withReach($service->documentRows($period, $f)), $sort, $this->str($q, 'chieu') === 'desc' ? 'desc' : 'asc');
        $low = DocumentAccessReportService::LOW_REACH_PERCENT;

        $rows = $docs->values()->map(fn (array $r, int $i) => [
            TemplateReport::num($i + 1),
            TemplateReport::text($r['title'], ['bold' => true]),
            TemplateReport::text($r['category'] ?? '—'),
            TemplateReport::text(DocumentAccessReportService::CONFIDENTIALITY_LABELS[$r['confidentiality']] ?? $r['confidentiality'], ['align' => 'center']),
            TemplateReport::text($r['version'] ? 'v' . $r['version'] : '—', ['align' => 'center']),
            TemplateReport::num($r['granted']),
            TemplateReport::num($r['views']),
            TemplateReport::num($r['viewers']),
            TemplateReport::pct($r['reach'], 1, TemplateReport::tone($r['reach'], 50, $low)),
            TemplateReport::num($r['is_video'] ? null : $r['downloads']),
            TemplateReport::num($r['denied'], 0, $r['denied'] ? 'bad' : null),
        ])->all();

        $sum = fn (string $col) => $docs->whereNotNull($col)->isEmpty() ? null : (int) $docs->sum($col);
        $granted = (int) $docs->sum('granted');
        $notes = [
            'Tỷ lệ tiếp cận = người xem trong kỳ ÷ người được cấp quyền: đỏ < ' . $this->dec($low) . '%, xanh lá ≥ 50%. '
                . 'Lượt xem lặp của cùng người trong ' . DocumentAccessReportService::VIEW_MERGE_MINUTES . ' phút được gộp.',
        ];
        if ($f['action']) {
            $notes[] = 'Chỉ tính hành động: ' . DocumentAccessReportService::ACTION_LABELS[$f['action']] . '.';
        }

        return new TemplateReport(
            key: 'truy-cap-tai-lieu',
            title: 'BÁO CÁO TRUY CẬP VÀ SỬ DỤNG TÀI LIỆU',
            periodLabel: $period->label(),
            unitLabel: $this->departmentLabel($f['owner_department_id'], 'Tất cả đơn vị sở hữu'),
            summary: [
                'Lượt xem: ' . $this->int($kpis['views']),
                'Người xem: ' . $this->int($kpis['viewers']),
                'Lượt tải: ' . $this->int($kpis['downloads']),
                'Bị chặn: ' . $this->int($kpis['denied']),
                'Tài liệu chưa ai mở: ' . $this->int($kpis['unopened']),
                'Quá ' . DocumentAccessReportService::REVIEW_STALE_MONTHS . ' tháng chưa cập nhật: ' . $this->int($kpis['stale']),
            ],
            columns: [
                ['label' => 'STT', 'width' => 6, 'align' => 'center'],
                ['label' => 'Tài liệu', 'width' => 36],
                ['label' => 'Danh mục', 'width' => 18],
                ['label' => 'Độ mật', 'width' => 11, 'align' => 'center'],
                ['label' => 'Bản', 'width' => 7, 'align' => 'center'],
                ['label' => 'Được cấp quyền', 'width' => 11, 'align' => 'center'],
                ['label' => 'Lượt xem', 'group' => 'Trong kỳ', 'width' => 10, 'align' => 'center'],
                ['label' => 'Người xem', 'group' => 'Trong kỳ', 'width' => 10, 'align' => 'center'],
                ['label' => 'Tỷ lệ tiếp cận', 'group' => 'Trong kỳ', 'width' => 11, 'align' => 'center'],
                ['label' => 'Lượt tải', 'group' => 'Trong kỳ', 'width' => 9, 'align' => 'center'],
                ['label' => 'Bị chặn', 'group' => 'Trong kỳ', 'width' => 9, 'align' => 'center'],
            ],
            rows: TemplateReport::capRows($rows, $notes),
            totals: $rows === [] ? null : [
                TemplateReport::text('Tổng cộng', ['span' => 5, 'align' => 'right']),
                TemplateReport::num($granted),
                TemplateReport::num($sum('views')),
                TemplateReport::num($sum('viewers')),
                TemplateReport::pct($granted > 0 && $sum('viewers') !== null ? $sum('viewers') / $granted * 100 : null, 1),
                TemplateReport::num($sum('downloads')),
                TemplateReport::num($sum('denied')),
            ],
            notes: $notes,
            preparedBy: $preparedBy,
            fileBase: 'bao-cao-truy-cap-tai-lieu',
            sheetTitle: 'Truy cập tài liệu',
            emptyText: 'Không có tài liệu nào khớp bộ lọc.',
            backUrl: $back,
        );
    }

    // ---- Bảo mật & đăng nhập ------------------------------------------------------

    public function security(array $q, User $user, ?string $preparedBy = null, ?string $back = null): TemplateReport
    {
        $period = $this->period($q, 92, '7d');
        $service = app(SecurityReportService::class);
        $f = [
            'type' => array_key_exists($this->str($q, 'loai'), SecurityAlert::TYPE_LABELS) ? $q['loai'] : null,
            'severity' => array_key_exists($this->str($q, 'muc-do'), SecurityReportService::SEVERITY_LABELS) ? $q['muc-do'] : null,
            'status' => array_key_exists($this->str($q, 'trang-thai'), SecurityReportService::STATUS_LABELS) ? $q['trang-thai'] : null,
        ];
        $dir = $this->str($q, 'sap-xep') === 'asc' ? 'asc' : 'desc';
        $canSee = (bool) $user->can('employees.view');
        $code = fn (?string $c) => $canSee ? ($c ?: '—') : SecurityReportService::maskCode($c);
        $kpis = $service->kpis($period, $f);
        $notes = [];

        $summary = [
            'Lượt đăng nhập: ' . $this->int($kpis['logins']),
            'Tỷ lệ thất bại: ' . SecurityReportService::pct($kpis['fail_rate']),
            'Tài khoản đang khóa: ' . $this->int($kpis['locked_accounts']),
            'Cảnh báo: ' . $this->int($kpis['alerts']) . ' (chưa đóng ' . $this->int($kpis['alerts_unclosed']) . ')',
            'Phản hồi trung vị: ' . SecurityReportService::hours($kpis['mtta_hours']) . ' giờ',
            'Xử lý trung vị: ' . SecurityReportService::hours($kpis['mttr_hours']) . ' giờ',
        ];

        if ($this->str($q, 'bang') === 'logins') {
            $weekday = ctype_digit($this->str($q, 'thu')) ? max(1, min(7, (int) $q['thu'])) : null;
            $hour = ctype_digit($this->str($q, 'gio')) ? max(0, min(23, (int) $q['gio'])) : null;
            $logins = $service->loginQuery($period, $weekday, $hour)->with('user.employee')
                ->orderBy('logged_in_at', $dir)->orderBy('id', $dir)
                ->limit(TemplateReport::MAX_ROWS + 1)->get();
            if ($logins->count() > TemplateReport::MAX_ROWS) {
                $notes[] = 'Bản mẫu chỉ hiển thị ' . $this->int(TemplateReport::MAX_ROWS) . ' lượt đầu — dùng "Xuất dữ liệu" để lấy đủ.';
            }
            if ($weekday !== null || $hour !== null) {
                $notes[] = 'Lọc theo ô bản đồ nhiệt: ' . ($weekday ? SecurityReportService::WEEKDAY_LABELS[$weekday] : 'mọi ngày')
                    . ($hour !== null ? ', ' . SecurityReportService::hourRange($hour) : '') . ' (giờ VN).';
            }

            $rows = $logins->take(TemplateReport::MAX_ROWS)->values()->map(fn ($l, $i) => [
                TemplateReport::num($i + 1),
                TemplateReport::text(ReportPeriod::local($l->logged_in_at)->format('d/m/Y H:i'), ['align' => 'center']),
                TemplateReport::text($l->user?->name ?? '—', ['bold' => true]),
                TemplateReport::text($code($l->user?->employee?->employee_code), ['align' => 'center']),
                TemplateReport::text($l->ip_address ?? '—'),
                TemplateReport::text($l->device_label ?? $l->user_agent ?? '—'),
                TemplateReport::text(SecurityReportService::RESULT_LABELS[$l->result] ?? $l->result, [
                    'align' => 'center', 'tone' => $l->result === 'success' ? 'great' : 'bad',
                ]),
                TemplateReport::text($l->failure_reason ?? ''),
            ])->all();

            return $this->securityReport('LỊCH SỬ ĐĂNG NHẬP', $period, $summary, [
                ['label' => 'STT', 'width' => 6, 'align' => 'center'],
                ['label' => 'Thời điểm', 'width' => 16, 'align' => 'center'],
                ['label' => 'Tài khoản', 'width' => 22],
                ['label' => 'Mã NV', 'width' => 11, 'align' => 'center'],
                ['label' => 'IP', 'width' => 15],
                ['label' => 'Thiết bị', 'width' => 30],
                ['label' => 'Kết quả', 'width' => 12, 'align' => 'center'],
                ['label' => 'Lý do thất bại', 'width' => 24],
            ], $rows, $notes, $preparedBy, $back, 'lich-su-dang-nhap');
        }

        $alerts = $service->alertQuery($period, $f)->with(['user.employee', 'handledBy'])
            ->orderBy('created_at', $dir)->orderBy('id', $dir)->get();
        $severityTone = ['critical' => 'bad', 'high' => 'bad', 'medium' => 'warn', 'low' => 'muted'];

        $rows = $alerts->values()->map(function (SecurityAlert $a, int $i) use ($code, $severityTone) {
            $overdue = SecurityReportService::isOpenOverdue($a);

            return [
                TemplateReport::num($i + 1),
                TemplateReport::text(SecurityReportService::alertCode($a), ['align' => 'center', 'bold' => true]),
                TemplateReport::text(ReportPeriod::local($a->created_at)->format('d/m/Y H:i'), ['align' => 'center']),
                TemplateReport::text($a->typeLabel()),
                TemplateReport::text(SecurityReportService::SEVERITY_LABELS[$a->severity] ?? $a->severity, [
                    'align' => 'center', 'tone' => $severityTone[$a->severity] ?? null,
                ]),
                TemplateReport::text($code($a->user?->employee?->employee_code), ['align' => 'center']),
                TemplateReport::text($a->detail ?: $a->title),
                TemplateReport::text((SecurityReportService::STATUS_LABELS[$a->status] ?? $a->status)
                    . ($overdue ? ' · quá ' . SecurityReportService::OPEN_OVERDUE_HOURS . ' giờ' : ''), [
                        'align' => 'center', 'tone' => $overdue ? 'bad' : null,
                    ]),
                TemplateReport::text($a->handledBy?->name ?? '—'),
                TemplateReport::num(SecurityReportService::handlingHours($a), 1),
            ];
        })->all();

        $byType = $service->alertsByType($period, $f);
        if ($byType->isNotEmpty()) {
            $notes[] = 'Theo loại: ' . $byType->map(fn ($t) => $t['label'] . ' ' . $t['total'] . ' (cao ' . $t['high'] . ')')->implode(' · ') . '.';
        }

        return $this->securityReport('CẢNH BÁO BẢO MẬT', $period, $summary, [
            ['label' => 'STT', 'width' => 6, 'align' => 'center'],
            ['label' => 'Mã', 'width' => 14, 'align' => 'center'],
            ['label' => 'Phát sinh', 'width' => 16, 'align' => 'center'],
            ['label' => 'Loại', 'width' => 22],
            ['label' => 'Mức độ', 'width' => 12, 'align' => 'center'],
            ['label' => 'Mã NV', 'width' => 11, 'align' => 'center'],
            ['label' => 'Tóm tắt', 'width' => 36],
            ['label' => 'Trạng thái', 'width' => 16, 'align' => 'center'],
            ['label' => 'Người xử lý', 'width' => 18],
            ['label' => 'TG xử lý (giờ)', 'width' => 10, 'align' => 'center'],
        ], TemplateReport::capRows($rows, $notes), $notes, $preparedBy, $back, 'canh-bao-bao-mat');
    }

    private function securityReport(string $part, ReportPeriod $period, array $summary, array $columns, array $rows, array $notes, ?string $preparedBy, ?string $back, string $file): TemplateReport
    {
        return new TemplateReport(
            key: 'bao-mat-dang-nhap',
            title: 'BÁO CÁO BẢO MẬT VÀ ĐĂNG NHẬP – ' . $part,
            periodLabel: $period->label(),
            unitLabel: 'Toàn hệ thống',
            summary: $summary,
            columns: $columns,
            rows: $rows,
            notes: [...$notes, 'Mã nhân viên được che khi người xuất không có quyền Xem nhân sự. Giờ theo GMT+7.'],
            preparedBy: $preparedBy,
            fileBase: 'bao-cao-' . $file,
            sheetTitle: 'Bảo mật',
            emptyText: 'Không có dữ liệu trong kỳ.',
            backUrl: $back,
        );
    }

    // ---- Yêu cầu hỗ trợ -------------------------------------------------------

    public function support(array $q, ?string $preparedBy = null, ?string $back = null): TemplateReport
    {
        $period = $this->period($q, 184);
        $service = app(SupportReportService::class);
        // Màn để trống = tất cả trạng thái; link mẫu gửi 'tat-ca' vì tham số rỗng dễ bị bỏ khỏi URL
        $status = array_key_exists('trang_thai', $q) ? $this->str($q, 'trang_thai') : 'open';
        $status = $status === 'tat-ca' ? '' : $status;
        $f = [
            'topic' => $this->str($q, 'chu_de'),
            'priority' => array_key_exists($this->str($q, 'uu_tien'), SupportRequest::PRIORITY_LABELS) ? $q['uu_tien'] : '',
            'status' => $status === '' || $status === 'open' || array_key_exists($status, SupportRequest::STATUS_LABELS) ? $status : 'open',
            'assignee' => $this->str($q, 'phu_trach'),
            'over_sla' => $this->bool($q, 'qua_sla', true),
        ];
        $sort = in_array($this->str($q, 'sap_xep'), ['overdue', 'created_at', 'sla_due_at', 'priority'], true) ? $q['sap_xep'] : 'overdue';

        $kpi = $service->kpis($period, $f);
        $all = $service->listQuery($period, $f, $sort, $this->str($q, 'chieu') === 'asc' ? 'asc' : 'desc')
            ->limit(TemplateReport::MAX_ROWS + 1)->get();
        $notes = [];
        if ($all->count() > TemplateReport::MAX_ROWS) {
            $notes[] = 'Bản mẫu chỉ hiển thị ' . $this->int(TemplateReport::MAX_ROWS) . ' phiếu đầu — dùng "Xuất dữ liệu" để lấy đủ.';
        }

        $rows = $all->take(TemplateReport::MAX_ROWS)->values()->map(function (SupportRequest $r, int $i) {
            $overdue = $r->overdue_seconds !== null && $r->overdue_seconds > 0 ? round($r->overdue_seconds / 3600, 1) : null;

            return [
                TemplateReport::num($i + 1),
                TemplateReport::text($r->ticket_no, ['align' => 'center', 'bold' => true]),
                TemplateReport::text(ReportPeriod::local($r->created_at)?->format('d/m/Y H:i'), ['align' => 'center']),
                TemplateReport::text($r->employee?->full_name ?? '—'),
                TemplateReport::text($r->employee?->department?->name ?? '—'),
                TemplateReport::text($r->topic),
                TemplateReport::text($r->subject),
                TemplateReport::text(SupportRequest::PRIORITY_LABELS[$r->priority] ?? $r->priority, [
                    'align' => 'center', 'tone' => $r->priority === SupportRequest::PRIORITY_HIGH ? 'bad' : null,
                ]),
                TemplateReport::text(SupportRequest::STATUS_LABELS[$r->status] ?? $r->status, ['align' => 'center']),
                TemplateReport::text($r->assignedTo?->name ?? 'Chưa phân công', ['tone' => $r->assignedTo ? null : 'warn']),
                TemplateReport::text(ReportPeriod::local($r->sla_due_at)?->format('d/m/Y H:i') ?? '—', ['align' => 'center']),
                TemplateReport::num($overdue, 1, $overdue ? 'bad' : null),
            ];
        })->all();

        $statusText = match ($f['status']) {
            'open' => 'Đang mở', '' => 'Tất cả', default => SupportRequest::STATUS_LABELS[$f['status']],
        };
        $notes[] = 'Bảng: trạng thái ' . $statusText . ($f['over_sla'] ? ' · chỉ phiếu quá SLA' : '')
            . ($f['topic'] !== '' ? ' · chủ đề ' . $f['topic'] : '')
            . ' · gồm phiếu gửi trong kỳ và phiếu còn mở gửi trước kỳ. SLA: '
            . collect(SupportRequest::SLA_HOURS)->map(fn ($h, $p) => SupportRequest::PRIORITY_LABELS[$p] . ' ' . $h . ' giờ')->implode(', ') . '.';

        return new TemplateReport(
            key: 'yeu-cau-ho-tro',
            title: 'BÁO CÁO YÊU CẦU HỖ TRỢ',
            periodLabel: $period->label(),
            unitLabel: 'Toàn hệ thống',
            summary: [
                'Phiếu mới: ' . $this->int($kpi['new']),
                'Đang mở: ' . $this->int($kpi['open']) . ' (quá SLA ' . $this->int($kpi['open_over_sla']) . ')',
                'Đã giải quyết: ' . $this->int($kpi['resolved']),
                'Trung vị xử lý: ' . $this->dec($kpi['median_hours']) . ' giờ',
                'Đúng SLA: ' . $this->pct($kpi['sla_rate'], 1),
                'Chưa phân công: ' . $this->int($kpi['unassigned']),
            ],
            columns: [
                ['label' => 'STT', 'width' => 6, 'align' => 'center'],
                ['label' => 'Mã yêu cầu', 'width' => 14, 'align' => 'center'],
                ['label' => 'Ngày gửi', 'width' => 15, 'align' => 'center'],
                ['label' => 'Người gửi', 'width' => 20],
                ['label' => 'Đơn vị', 'width' => 18],
                ['label' => 'Chủ đề', 'width' => 16],
                ['label' => 'Tiêu đề', 'width' => 30],
                ['label' => 'Ưu tiên', 'width' => 9, 'align' => 'center'],
                ['label' => 'Trạng thái', 'width' => 12, 'align' => 'center'],
                ['label' => 'Phụ trách', 'width' => 16],
                ['label' => 'Hạn SLA', 'width' => 15, 'align' => 'center'],
                ['label' => 'Quá hạn (giờ)', 'width' => 9, 'align' => 'center'],
            ],
            rows: $rows,
            notes: $notes,
            preparedBy: $preparedBy,
            fileBase: 'bao-cao-yeu-cau-ho-tro',
            sheetTitle: 'Yêu cầu hỗ trợ',
            emptyText: 'Không có phiếu nào khớp bộ lọc.',
            backUrl: $back,
        );
    }

    // ---- Tổng quan đào tạo ----------------------------------------------------

    public function dashboard(array $q, ?string $preparedBy = null, ?string $back = null): TemplateReport
    {
        $statuses = array_key_exists('nv', $q)
            ? array_values(array_intersect(explode(',', is_array($q['nv']) ? implode(',', $q['nv']) : (string) $q['nv']), array_keys(Employee::STATUS_LABELS)))
            : DashboardFilter::DEFAULT_STATUSES;
        $compare = array_key_exists($this->str($q, 'ss'), DashboardFilter::COMPARES) ? $q['ss'] : DashboardFilter::COMPARE_PREVIOUS;
        $courseType = array_key_exists($this->str($q, 'loai'), DashboardFilter::COURSE_TYPES) ? $q['loai'] : DashboardFilter::COURSE_ALL;
        $departmentId = ctype_digit($this->str($q, 'dv')) ? (int) $q['dv'] : null;
        $jobTitleId = ctype_digit($this->str($q, 'cd')) ? (int) $q['cd'] : null;

        $filter = new DashboardFilter(
            period: $this->period($q, 92),
            compare: $compare,
            departmentId: $departmentId ?: null,
            jobTitleId: $jobTitleId ?: null,
            courseType: $courseType,
            employmentStatuses: $statuses,
        );
        $metrics = app(DashboardMetricsService::class);
        $kpis = $metrics->kpis($filter);
        $units = $metrics->departmentProgress($filter);
        $labels = DashboardMetricsService::STATUS_LABELS;

        $rows = $units['rows']->values()->map(fn (array $u, int $i) => [
            TemplateReport::num($i + 1),
            TemplateReport::text($u['name'], ['bold' => true]),
            TemplateReport::num($u['total']),
            ...array_map(fn ($key) => TemplateReport::num($u['counts'][$key], 0, $key === 'overdue' && $u['counts'][$key] > 0 ? 'bad' : null), array_keys($labels)),
            TemplateReport::pct($u['rate'], 1, TemplateReport::tone($u['rate'], 80, 50)),
            TemplateReport::text($u['small'] ? 'Mẫu nhỏ (< ' . DashboardMetricsService::SMALL_SAMPLE . ' lượt)' : '', ['tone' => 'muted']),
        ])->all();

        $sum = fn (string $key) => (int) $units['rows']->sum(fn ($u) => $u['counts'][$key]);
        ['on_time' => $onTime, 'active' => $active, 'overdue' => $overdue, 'first_pass' => $pass, 'hours' => $hours] =
            array_map(fn ($k) => $k['current'], $kpis);

        $scope = array_filter([
            $jobTitleId ? 'Chức danh: ' . (JobTitle::find($jobTitleId)?->name ?? '—') : null,
            'Loại khóa: ' . DashboardFilter::COURSE_TYPES[$courseType],
            'Nhân sự: ' . (collect($statuses)->map(fn ($s) => Employee::statusLabel($s))->implode(', ') ?: 'Tất cả'),
        ]);

        return new TemplateReport(
            key: 'tong-quan',
            title: 'BÁO CÁO TỔNG QUAN ĐÀO TẠO',
            periodLabel: $filter->period->label(),
            unitLabel: $this->departmentLabel($departmentId),
            summary: [
                'Hoàn thành đúng hạn: ' . $this->pct($onTime['value'], 1) . ' (' . $this->int($onTime['on_time']) . '/' . $this->int($onTime['due']) . ' lượt)',
                'Học viên hoạt động: ' . $this->int($active['value']),
                'Lượt quá hạn: ' . $this->int($overdue['value']),
                'Đạt KT lần đầu: ' . $this->pct($pass['value'], 1),
                'Tổng giờ học: ' . $this->dec($hours['value']),
            ],
            columns: [
                ['label' => 'STT', 'width' => 6, 'align' => 'center'],
                ['label' => 'Đơn vị', 'width' => 30],
                ['label' => 'Tổng lượt', 'width' => 9, 'align' => 'center'],
                ...array_map(fn ($label) => ['label' => $label, 'group' => 'Số lượt theo trạng thái', 'width' => 10, 'align' => 'center'], array_values($labels)),
                ['label' => 'Tỷ lệ hoàn thành', 'width' => 11, 'align' => 'center'],
                ['label' => 'Ghi chú', 'width' => 16],
            ],
            rows: $rows,
            totals: $rows === [] ? null : [
                TemplateReport::text('Tổng cộng', ['span' => 2, 'align' => 'right']),
                TemplateReport::num((int) $units['rows']->sum('total')),
                ...array_map(fn ($key) => TemplateReport::num($sum($key)), array_keys($labels)),
                TemplateReport::pct($units['overall'], 1),
                TemplateReport::text(''),
            ],
            notes: [
                implode(' · ', $scope) . ' · So sánh: ' . DashboardFilter::COMPARES[$compare] . '.',
                'Bảng: lượt ghi danh còn mở trong kỳ theo phòng ban trực tiếp của nhân viên; tỷ lệ hoàn thành xanh lá ≥ 80%, đỏ < 50%.',
            ],
            preparedBy: $preparedBy,
            landscape: false,
            fileBase: 'bao-cao-tong-quan-dao-tao',
            sheetTitle: 'Tổng quan đào tạo',
            emptyText: 'Không có lượt ghi danh nào trong phạm vi lọc.',
            backUrl: $back,
        );
    }

    // ---- Tiện ích ---------------------------------------------------------------

    /** Kỳ báo cáo từ ky / tu / den — cùng tham số với HasReportPeriod. */
    private function period(array $q, int $maxDays, string $default = '30d'): ReportPeriod
    {
        $preset = $this->str($q, 'ky') ?: $default;

        return ReportPeriod::make($preset, $this->str($q, 'tu') ?: null, $this->str($q, 'den') ?: null, $maxDays);
    }

    private function str(array $q, string $key): string
    {
        return is_scalar($q[$key] ?? null) ? trim((string) $q[$key]) : '';
    }

    private function bool(array $q, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $q)) {
            return $default;
        }

        return in_array(strtolower($this->str($q, $key)), ['1', 'true', 'on', 'yes'], true);
    }

    private function departmentLabel(?int $id, string $all = 'Tất cả đơn vị'): string
    {
        return $id ? (Department::find($id)?->name ?? $all) : $all;
    }

    private function int(int|float|null $v): string
    {
        return $v === null ? '—' : number_format((float) $v, 0, ',', '.');
    }

    private function dec(int|float|null $v, int $decimals = 1): string
    {
        return $v === null ? '—' : number_format((float) $v, $decimals, ',', '.');
    }

    private function pct(int|float|null $v, int $decimals = 0): string
    {
        return $v === null ? '—' : number_format((float) $v, $decimals, ',', '.') . '%';
    }
}
