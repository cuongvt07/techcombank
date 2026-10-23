<?php

namespace App\Livewire\Admin\Reports;

use App\Livewire\Concerns\HasSearchFilters;
use App\Livewire\Concerns\ChecksPermissions;
use App\Livewire\Concerns\HasReportPeriod;
use App\Models\Department;
use App\Models\Question;
use App\Services\LearningReminderService;
use App\Services\Reports\QuizResultReportService;
use App\Support\Reports\ReportExport;
use App\Support\Reports\ReportPeriod;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Báo cáo kết quả kiểm tra (SCR_003) — hai tab trên cùng trang:
 *   - "Kết quả theo nhân viên": một dòng = một NV × một bài, gộp mọi lượt; cấp thêm lượt / gửi nhắc.
 *   - "Phân tích câu hỏi": phân bố điểm, tỷ lệ đạt theo bài, câu hỏi kéo điểm xuống.
 * Truy vấn nằm ở QuizResultReportService; component chỉ giữ bộ lọc và thao tác.
 */
class QuizResultReport extends Component
{
    use HasSearchFilters;

    use ChecksPermissions;
    use HasReportPeriod;
    use WithPagination;

    public const REPORT_MAX_DAYS = 366;

    public const TABS = ['employees' => 'Kết quả theo nhân viên', 'questions' => 'Phân tích câu hỏi'];

    #[Url(as: 'tab')]
    public string $tab = 'employees';

    #[Url(as: 'khoa')]
    public ?string $courseId = null;

    #[Url(as: 'bai')]
    public ?string $quizId = null;

    /** Chỉ áp cho tab nhân viên. */
    #[Url(as: 'don-vi')]
    public ?string $departmentId = null;

    /** Kết quả cuối của dòng NV × bài: passed | retry | exhausted. */
    #[Url(as: 'ket-qua')]
    public string $result = '';

    /** Tab câu hỏi: lần làm (first | last) và kết quả lượt (passed | failed). */
    #[Url(as: 'lan')]
    public string $attemptScope = '';

    #[Url(as: 'kq-luot')]
    public string $attemptResult = '';

    /** Tab câu hỏi: khoảng điểm 0–9 chọn từ biểu đồ phân bố. */
    #[Url(as: 'khoang')]
    public ?string $band = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sx')]
    public string $sort = '';

    #[Url(as: 'chieu')]
    public string $dir = 'asc';

    #[Url(as: 'so-dong')]
    public int $perPage = 20;

    /** Dòng đang chọn, dạng "employeeId:quizId". */
    public array $selected = [];

    public ?string $notice = null;

    private const FILTERS = ['courseId', 'quizId', 'departmentId', 'result', 'attemptScope', 'attemptResult', 'band', 'search', 'perPage'];

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'employees';
        }
    }

    public function updated(string $property): void
    {
        // Ô chọn "Tất cả" gửi chuỗi rỗng — đưa về null để URL không còn tham số thừa
        foreach (['courseId', 'quizId', 'departmentId', 'band'] as $nullable) {
            if ($this->{$nullable} === '') {
                $this->{$nullable} = null;
            }
        }

        if ($property === 'courseId') {
            // Bài đang chọn không thuộc khóa mới → bỏ chọn bài
            $valid = $this->service()->quizOptions($this->courseId ? (int) $this->courseId : null)->pluck('id');
            if ($this->quizId && ! $valid->contains((int) $this->quizId)) {
                $this->quizId = null;
            }
        }

        if (in_array($property, self::FILTERS, true)) {
            $this->perPage = in_array($this->perPage, [20, 50, 100], true) ? $this->perPage : 20;
            $this->resetPage();
            $this->selected = [];
        }
    }

    protected function onPeriodChanged(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'employees';
        $this->sort = '';
        $this->dir = 'asc';
        $this->selected = [];
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        $this->dir = $this->sort === $column && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->resetPage();
    }

    /** Bấm thanh đơn vị → lọc bảng theo đơn vị đó; bấm lại để bỏ. */
    public function filterDepartment(string $id): void
    {
        $this->departmentId = $this->departmentId === $id ? null : $id;
        $this->updated('departmentId');
    }

    public function filterQuiz(string $id): void
    {
        $this->quizId = $this->quizId === $id ? null : $id;
        $this->updated('quizId');
    }

    public function filterBand(int $index): void
    {
        $this->band = $this->band === (string) $index ? null : (string) max(0, min(9, $index));
        $this->updated('band');
    }

    /** Từ tab câu hỏi: mở danh sách người hết lượt để cấp thêm. */
    public function showExhausted(): void
    {
        $this->setTab('employees');
        $this->result = QuizResultReportService::RESULT_EXHAUSTED;
    }

    public function resetFilters(): void
    {
        $this->reset(['courseId', 'quizId', 'departmentId', 'result', 'attemptScope', 'attemptResult', 'band', 'search', 'sort', 'dir', 'selected']);
        $this->preset = '30d';
        $this->from = null;
        $this->to = null;
        $this->resetPage();
    }

    /** Ô chọn ở tiêu đề bảng: chọn / bỏ cả trang đang xem. */
    public function togglePage(array $keys): void
    {
        $keys = array_values(array_filter($keys, fn ($k) => is_string($k) && preg_match('/^\d+:\d+$/', $k)));
        $allSelected = array_diff($keys, $this->selected) === [];

        $this->selected = $allSelected
            ? array_values(array_diff($this->selected, $keys))
            : array_values(array_unique([...$this->selected, ...$keys]));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    // ---- Thao tác ghi ---------------------------------------------------------

    public function grantSelected(): void
    {
        $this->requirePermission('report_quiz.update');

        $res = $this->service()->grantExtraAttempts($this->selectedPairs(), auth()->id());
        $this->notice = $res['granted'] > 0
            ? "Đã cấp thêm 1 lượt làm cho {$res['granted']} nhân viên."
                . ($res['skipped'] ? " Bỏ qua {$res['skipped']} dòng chưa hết lượt hoặc đã đạt." : '')
            : 'Không có dòng nào cần cấp thêm lượt — chỉ cấp cho người hết lượt và chưa đạt.';
        $this->selected = [];
    }

    public function remindSelected(): void
    {
        $this->requirePermission('report_quiz.update');

        $this->notifyReminder($this->service()->remindRetake($this->selectedPairs(), auth()->id()));
        $this->selected = [];
    }

    /** Nhắc mọi người chưa đạt còn lượt khớp bộ lọc hiện tại. */
    public function remindAllRetry(): void
    {
        $this->requirePermission('report_quiz.update');

        $pairs = $this->service()->pairRows($this->filters(), $this->period())
            ->where('final_result', QuizResultReportService::RESULT_RETRY)
            ->get(['employee_id', 'quiz_id'])
            ->map(fn ($r) => [(int) $r->employee_id, (int) $r->quiz_id])
            ->all();

        $this->notifyReminder($this->service()->remindRetake($pairs, auth()->id()));
    }

    public function export(string $format = 'xlsx')
    {
        $period = $this->period();
        $service = $this->service();

        if ($this->tab === 'questions') {
            $canSeeAnswers = auth()->user()?->can('quizzes.update');
            $rows = $service->enrichQuestions(
                $service->questionTable($this->filters(), $period, $this->sort ?: 'rate', $this->dir)->get(),
                $this->filters(),
                $period,
            )->map(fn ($q) => array_merge([
                $q->sort_order + 1,
                $q->quiz_title,
                $q->content,
                self::typeLabel($q->type),
                $q->shown,
                round($q->correct_rate, 1),
                $q->top_wrong ? $q->top_wrong['text'] . ' (' . number_format($q->top_wrong['pct'], 1, ',', '.') . '%)' : '',
                $q->flag['label'] ?? '',
            ], $canSeeAnswers ? [$q->correct_text] : []))->all();

            $headings = ['STT', 'Bài kiểm tra', 'Nội dung câu hỏi', 'Loại', 'Số lần ra', '% trả lời đúng',
                'Đáp án sai được chọn nhiều nhất', 'Cờ chất lượng', ...($canSeeAnswers ? ['Đáp án đúng'] : [])];

            return ReportExport::download('phan-tich-cau-hoi', $headings, $rows, $format, 'Phân tích câu hỏi');
        }

        $rows = $service->employeeTable($this->filters(), $period, $this->sort, $this->dir)->get()
            ->map(fn ($r) => [
                $r->employee_code,
                $r->full_name,
                $r->department_name,
                $r->quiz_title,
                (int) $r->used,
                $r->allowed === null ? 'Không giới hạn' : (int) $r->allowed,
                self::score($r->first_pct),
                self::score($r->best_pct),
                self::score($r->last_pct),
                QuizResultReportService::RESULT_LABELS[$r->final_result],
                $r->pass_no ?? '',
                $r->passed_at ? ReportPeriod::local($r->passed_at)->format('d/m/Y') : '',
                $r->remaining ?? 'Không giới hạn',
            ])->all();

        return ReportExport::download('ket-qua-kiem-tra', [
            'Mã NV', 'Họ tên', 'Phòng ban', 'Bài kiểm tra', 'Số lượt đã làm', 'Lượt tối đa',
            'Điểm lần đầu', 'Điểm cao nhất', 'Điểm lần cuối', 'Kết quả cuối', 'Lượt đến khi đạt', 'Ngày đạt', 'Lượt còn lại',
        ], $rows, $format, 'Kết quả kiểm tra');
    }

    // ---- Hiển thị -------------------------------------------------------------

    public function render(): View
    {
        $period = $this->period();
        $service = $this->service();
        $filters = $this->filters();
        $quizzes = $service->quizOptions($this->courseId ? (int) $this->courseId : null);
        $selectedQuiz = $this->quizId ? $quizzes->firstWhere('id', (int) $this->quizId) : null;

        $data = [
            'period' => $period,
            'courses' => $service->courseOptions(),
            'quizzes' => $quizzes,
            'selectedQuiz' => $selectedQuiz,
            'departments' => Department::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ];

        if ($this->tab === 'questions') {
            // "Đạt lần đầu" và "Hết lượt" là chỉ số theo người — không lọc theo đơn vị ở tab này
            $data['pairKpi'] = $service->pairKpis($filters, $period, false);
            $data['attemptKpi'] = $service->attemptKpis($filters, $period);
            $data['histogram'] = $service->scoreHistogram($filters, $period);
            $data['quizRates'] = $service->passRateByQuiz($filters, $period);
            $questions = $service->questionTable($filters, $period, $this->sort ?: 'rate', $this->dir)->paginate($this->perPage);
            $questions->setCollection($service->enrichQuestions($questions->getCollection(), $filters, $period));
            $data['questions'] = $questions;
            $data['canSeeAnswers'] = (bool) auth()->user()?->can('quizzes.update');
        } else {
            $data['pairKpi'] = $service->pairKpis($filters, $period);
            $data['departmentRates'] = $service->passRateByDepartment($filters, $period);
            $data['rows'] = $service->employeeTable($filters, $period, $this->sort, $this->dir)->paginate($this->perPage);
            // Dòng vừa được nhắc làm lại (trong thời gian chờ) hiện "Đã nhắc HH:mm · nhắc lại sau HH:mm"
            $data['retakeReminded'] = app(LearningReminderService::class)->recentRetakeReminders(
                $data['rows']->getCollection()->map(fn ($r) => [(int) $r->employee_id, (int) $r->quiz_id])
            );
            $data['selection'] = $service->selectionSummary($this->selectedPairs(), $filters, $period);
            $data['retryCount'] = $data['pairKpi']['retry'];
        }

        return view('livewire.admin.reports.quiz-result-report', $data)
            ->layout('layouts.admin', ['title' => 'Báo cáo kết quả kiểm tra']);
    }

    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            Question::TYPE_MULTIPLE => 'Nhiều lựa chọn',
            Question::TYPE_TRUE_FALSE => 'Đúng / Sai',
            default => 'Một lựa chọn',
        };
    }

    /** Điểm phần trăm 1 chữ số thập phân kiểu VN, vd "65,0". */
    public static function score($value): string
    {
        return $value === null ? '—' : number_format((float) $value, 1, ',', '.');
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.') . '%';
    }

    // ---- Nội bộ ---------------------------------------------------------------

    private function filters(): array
    {
        return [
            'course_id' => $this->courseId,
            'quiz_id' => $this->quizId,
            'department_id' => $this->departmentId,
            'result' => $this->result,
            'search' => $this->search,
            'attempt' => $this->attemptScope,
            'attempt_result' => $this->attemptResult,
            'band' => $this->band,
        ];
    }

    /** @return array<int, array{0: int, 1: int}> */
    private function selectedPairs(): array
    {
        return collect($this->selected)
            ->filter(fn ($k) => is_string($k) && preg_match('/^\d+:\d+$/', $k))
            ->map(fn ($k) => array_map('intval', explode(':', $k)))
            ->unique(fn ($p) => $p[0] . ':' . $p[1])
            ->values()
            ->all();
    }

    private function notifyReminder(array $res): void
    {
        $this->notice = $res['sent'] > 0
            ? "Đã gửi nhắc làm lại cho {$res['sent']} nhân viên."
                . ($res['skipped'] ? " Bỏ qua {$res['skipped']} dòng đã đạt, đã hết lượt hoặc vừa được nhắc trong " . LearningReminderService::COOLDOWN_HOURS . ' giờ.' : '')
            : 'Không có ai cần nhắc — chỉ nhắc người chưa đạt, còn lượt làm và chưa được nhắc trong ' . LearningReminderService::COOLDOWN_HOURS . ' giờ qua.';
    }

    private function service(): QuizResultReportService
    {
        return app(QuizResultReportService::class);
    }
}
