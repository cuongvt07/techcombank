<?php

namespace App\Services\Reports;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentAccessRule;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Services\DocumentAccessService;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Số liệu báo cáo truy cập & sử dụng tài liệu (SCR_007).
 *
 * Bộ lọc dùng chung (mảng $filters):
 *  - category_id: danh mục, gồm cả danh mục con
 *  - confidentiality: public|internal|confidential|restricted
 *  - owner_department_id: đơn vị sở hữu, gồm cả đơn vị con
 *  - action: view|download|print|stream|denied — chỉ giữ log của hành động đó;
 *    chỉ số thuộc hành động khác trả về null để màn hình hiện "—" thay vì số 0 gây hiểu nhầm.
 */
class DocumentAccessReportService
{
    /** Lượt xem lặp của cùng người trên cùng tài liệu cách nhau dưới 5 phút được gộp làm một. */
    public const VIEW_MERGE_MINUTES = 5;

    /** Tài liệu không đổi phiên bản quá 12 tháng coi như quá hạn rà soát. */
    public const REVIEW_STALE_MONTHS = 12;

    /** Ngưỡng một người bị chặn nhiều lần trong cùng một giờ — dấu hiệu dò quyền. */
    public const BLOCKED_BURST_PER_HOUR = 5;

    /** Tỷ lệ tiếp cận dưới ngưỡng này tô đỏ. */
    public const LOW_REACH_PERCENT = 15.0;

    /** Số người được cấp quyền tính lại mỗi ngày (giây). */
    public const GRANTED_CACHE_SECONDS = 86400;

    public const ACTION_LABELS = [
        DocumentAccessLog::ACTION_VIEW => 'Xem',
        DocumentAccessLog::ACTION_DOWNLOAD => 'Tải',
        DocumentAccessLog::ACTION_PRINT => 'In',
        DocumentAccessLog::ACTION_STREAM => 'Xem video',
        DocumentAccessLog::ACTION_DENIED => 'Bị chặn',
    ];

    /** Nhãn cấp độ bảo mật — giống màn Thư viện tài liệu. */
    public const CONFIDENTIALITY_LABELS = [
        Document::CONF_PUBLIC => 'Công khai',
        Document::CONF_INTERNAL => 'Nội bộ',
        Document::CONF_CONFIDENTIAL => 'Mật',
        Document::CONF_RESTRICTED => 'Tối mật',
    ];

    /** "Mở tài liệu" gồm xem file và xem video. */
    private const OPEN_ACTIONS = [DocumentAccessLog::ACTION_VIEW, DocumentAccessLog::ACTION_STREAM];

    /** Nhân viên đang hoạt động, nạp một lần cho mọi lần tính quyền trong request. */
    private ?Collection $activeEmployees = null;

    public function __construct(private readonly DocumentAccessService $access)
    {
    }

    // ---- Chỉ số tóm tắt ------------------------------------------------------

    /**
     * @return array{views: ?int, viewers: ?int, downloads: ?int, denied: ?int, burst_people: ?int, unopened: int, stale: int}
     */
    public function kpis(ReportPeriod $period, array $filters): array
    {
        $openActions = $this->allowedActions($filters, self::OPEN_ACTIONS);

        return [
            'views' => $openActions ? (clone $this->mergedViewsQuery($period, $filters))->count() : null,
            'viewers' => $openActions
                ? (int) $this->logsQuery($period, $filters, $openActions)->selectRaw('COUNT(DISTINCT ' . $this->personExpr() . ') as c')->value('c')
                : null,
            'downloads' => $this->countAction($period, $filters, DocumentAccessLog::ACTION_DOWNLOAD),
            'denied' => $this->countAction($period, $filters, DocumentAccessLog::ACTION_DENIED),
            'burst_people' => $this->blockedBurstPeople($period, $filters),
            'unopened' => $this->unopenedDocumentsQuery($period, $filters)->count(),
            'stale' => $this->staleDocumentsQuery($filters)->count(),
        ];
    }

    /**
     * Số người bị chặn từ 5 lượt trở lên trong cùng một giờ.
     * Gom theo giờ đồng hồ; múi giờ VN lệch UTC tròn giờ nên giờ UTC cũng là ranh giới giờ VN.
     */
    public function blockedBurstPeople(ReportPeriod $period, array $filters): ?int
    {
        if (! $this->allowedActions($filters, [DocumentAccessLog::ACTION_DENIED])) {
            return null;
        }

        $perHour = $this->logsQuery($period, $filters, [DocumentAccessLog::ACTION_DENIED])
            ->selectRaw($this->personExpr() . " as person, DATE_FORMAT(l.accessed_at, '%Y-%m-%d %H') as hour_bucket, COUNT(*) as c")
            ->groupBy('person', 'hour_bucket')
            ->havingRaw('COUNT(*) >= ?', [self::BLOCKED_BURST_PER_HOUR]);

        return (int) DB::query()->fromSub($perHour, 'b')->selectRaw('COUNT(DISTINCT person) as c')->value('c');
    }

    /** Tài liệu đã xuất bản (trước cuối kỳ) mà trong kỳ không ai xem/phát. Bộ lọc hành động không áp dụng. */
    public function unopenedDocumentsQuery(ReportPeriod $period, array $filters): EloquentBuilder
    {
        return $this->documentsQuery($filters)
            ->where(fn (EloquentBuilder $q) => $q->whereNull('documents.published_at')->orWhere('documents.published_at', '<=', $period->to()))
            ->whereNotExists(function (Builder $q) use ($period) {
                $q->selectRaw('1')->from('document_access_logs as ul')
                    ->whereColumn('ul.document_id', 'documents.id')
                    ->whereIn('ul.action', self::OPEN_ACTIONS)
                    ->whereBetween('ul.accessed_at', $period->range());
            });
    }

    /**
     * Tài liệu đã xuất bản không có phiên bản mới quá 12 tháng (tính tới hôm nay, không theo kỳ).
     * Chưa có phiên bản nào thì lấy ngày xuất bản / ngày tạo làm mốc.
     */
    public function staleDocumentsQuery(array $filters): EloquentBuilder
    {
        return $this->documentsQuery($filters)
            ->whereRaw(
                'COALESCE((SELECT MAX(v.created_at) FROM document_versions v WHERE v.document_id = documents.id), documents.published_at, documents.created_at) < ?',
                [now()->subMonths(self::REVIEW_STALE_MONTHS)]
            );
    }

    // ---- Biểu đồ theo tuần ---------------------------------------------------

    /**
     * @return Collection<int, array{label: string, days: int, views: int, downloads: int, denied: int}>
     */
    public function weekly(ReportPeriod $period, array $filters): Collection
    {
        $views = $this->allowedActions($filters, self::OPEN_ACTIONS)
            ? DB::query()->fromSub($this->mergedViewsQuery($period, $filters), 'mv')
                ->selectRaw(ReportPeriod::localDate('mv.accessed_at') . ' as d, COUNT(*) as c')
                ->groupBy('d')->pluck('c', 'd')
            : collect();

        $byAction = fn (string $action) => $this->allowedActions($filters, [$action])
            ? $this->logsQuery($period, $filters, [$action])
                ->selectRaw(ReportPeriod::localDate('l.accessed_at') . ' as d, COUNT(*) as c')
                ->groupBy('d')->pluck('c', 'd')
            : collect();

        $downloads = $byAction(DocumentAccessLog::ACTION_DOWNLOAD);
        $denied = $byAction(DocumentAccessLog::ACTION_DENIED);

        return $period->weeks()->map(function (array $week) use ($views, $downloads, $denied) {
            $sum = function (Collection $byDate) use ($week) {
                return (int) $byDate->filter(fn ($c, $d) => $d >= $week['start']->toDateString() && $d <= $week['end']->toDateString())->sum();
            };

            return [
                'label' => $week['label'],
                'days' => (int) $week['start']->diffInDays($week['end']->startOfDay()) + 1,
                'views' => $sum($views),
                'downloads' => $sum($downloads),
                'denied' => $sum($denied),
            ];
        })->values();
    }

    // ---- Bảng theo tài liệu --------------------------------------------------

    /**
     * Mọi tài liệu đã xuất bản khớp bộ lọc kèm số liệu trong kỳ (chưa có "được cấp quyền").
     * Số tài liệu xuất bản cỡ vài trăm nên gom hết rồi sắp xếp trong PHP.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function documentRows(ReportPeriod $period, array $filters): Collection
    {
        $documents = $this->documentsQuery($filters)
            ->with(['category:id,name', 'currentVersion:id,version_no'])
            ->withCount(['lessons as course_count' => fn (EloquentBuilder $q) => $q->select(DB::raw('COUNT(DISTINCT course_id)'))])
            ->get();

        $openActions = $this->allowedActions($filters, self::OPEN_ACTIONS);

        $stats = $this->logsQuery($period, $filters)
            ->selectRaw('l.document_id')
            ->selectRaw('COUNT(DISTINCT CASE WHEN l.action IN (\'view\', \'stream\') THEN ' . $this->personExpr() . ' END) as viewers')
            ->selectRaw('SUM(l.action = \'download\') as downloads')
            ->selectRaw('SUM(l.action = \'denied\') as denied')
            ->groupBy('l.document_id')
            ->get()->keyBy('document_id');

        $views = $openActions
            ? DB::query()->fromSub($this->mergedViewsQuery($period, $filters), 'mv')
                ->selectRaw('mv.document_id, COUNT(*) as c')->groupBy('mv.document_id')->pluck('c', 'document_id')
            : collect();

        $hasDownload = (bool) $this->allowedActions($filters, [DocumentAccessLog::ACTION_DOWNLOAD]);
        $hasDenied = (bool) $this->allowedActions($filters, [DocumentAccessLog::ACTION_DENIED]);

        return $documents->map(function (Document $doc) use ($stats, $views, $openActions, $hasDownload, $hasDenied) {
            $s = $stats->get($doc->id);

            return [
                'id' => $doc->id,
                'title' => $doc->title,
                'category' => $doc->category?->name,
                'confidentiality' => $doc->confidentiality,
                'version' => $doc->currentVersion?->version_no,
                'published_at' => $doc->published_at,
                'is_video' => $doc->isVideo(),
                'watermark' => (bool) $doc->enable_watermark,
                'course_count' => (int) $doc->course_count,
                'views' => $openActions ? (int) ($views[$doc->id] ?? 0) : null,
                'viewers' => $openActions ? (int) ($s->viewers ?? 0) : null,
                'downloads' => $hasDownload ? (int) ($s->downloads ?? 0) : null,
                'denied' => $hasDenied ? (int) ($s->denied ?? 0) : null,
                'granted' => null,
                'reach' => null,
            ];
        })->values();
    }

    /**
     * Gắn số người được cấp quyền + tỷ lệ tiếp cận cho các dòng truyền vào.
     * Tỷ lệ = người xem phân biệt / người được cấp quyền; chưa ai được cấp quyền thì để null.
     */
    public function withReach(Collection $rows): Collection
    {
        $granted = $this->grantedCounts($rows->pluck('id')->all());

        return $rows->map(function (array $row) use ($granted) {
            $row['granted'] = $granted[$row['id']] ?? 0;
            $row['reach'] = $row['granted'] > 0 && $row['viewers'] !== null
                ? round($row['viewers'] / $row['granted'] * 100, 1)
                : null;

            return $row;
        });
    }

    /**
     * Số nhân viên đang hoạt động được xem từng tài liệu, theo đúng quy tắc phân quyền.
     *
     * Dùng DocumentAccessService::countViewers — cùng logic với canView lúc nhân viên mở
     * tài liệu nhưng nạp quy tắc / cây phòng ban một lần cho cả lô. Kết quả cache 1 ngày
     * theo id tài liệu: quyền đổi trong ngày sẽ hiện ở lần tính sau.
     *
     * @param  array<int, int>  $documentIds
     * @return array<int, int>
     */
    public function grantedCounts(array $documentIds): array
    {
        $result = [];
        $missing = [];

        foreach ($documentIds as $id) {
            $cached = Cache::get($this->grantedCacheKey($id));

            if ($cached === null) {
                $missing[] = $id;
            } else {
                $result[$id] = (int) $cached;
            }
        }

        if ($missing) {
            // Đếm hàng loạt: rule, cây phòng ban, danh mục nạp một lần cho mọi tài liệu thiếu cache
            $this->activeEmployees ??= Employee::active()->with(['jobGrade', 'jobGrades'])->get();
            $counts = $this->access->countViewers(Document::whereIn('id', $missing)->get(), $this->activeEmployees);

            foreach ($counts as $id => $count) {
                Cache::put($this->grantedCacheKey($id), $count, self::GRANTED_CACHE_SECONDS);
                $result[$id] = $count;
            }
        }

        return $result;
    }

    public function grantedCacheKey(int $documentId): string
    {
        return 'reports:documents:granted:' . $documentId;
    }

    /** Sắp xếp các dòng; giá trị null (không áp dụng) luôn nằm cuối dù tăng hay giảm. */
    public function sortRows(Collection $rows, string $column, string $direction): Collection
    {
        $desc = $direction === 'desc';

        return $rows->sort(function (array $a, array $b) use ($column, $desc) {
            $va = $a[$column] ?? null;
            $vb = $b[$column] ?? null;

            if ($va === null || $vb === null) {
                return ($va === null) <=> ($vb === null);
            }

            if ($va instanceof \DateTimeInterface) {
                $va = $va->getTimestamp();
                $vb = $vb instanceof \DateTimeInterface ? $vb->getTimestamp() : 0;
            }

            $cmp = is_string($va) ? strcasecmp($va, (string) $vb) : $va <=> $vb;

            // Bằng nhau thì xếp theo tiêu đề để thứ tự ổn định giữa các trang
            return ($desc ? -$cmp : $cmp) ?: strcasecmp($a['title'], $b['title']);
        })->values();
    }

    // ---- Nhật ký truy cập ----------------------------------------------------

    /** Từng lượt truy cập (không gộp) — dùng cho tab nhật ký, cần quyền security.view. */
    public function accessLogQuery(ReportPeriod $period, array $filters): Builder
    {
        return $this->logsQuery($period, $filters)
            ->leftJoin('employees as e', 'e.id', '=', 'l.employee_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->select([
                'l.id', 'l.accessed_at', 'l.action', 'l.ip_address', 'l.watermark_token', 'l.deny_reason',
                'l.document_id', 'd.title as document_title',
                'e.full_name as employee_name', 'e.employee_code', 'u.name as user_name',
            ])
            ->orderByDesc('l.accessed_at')
            ->orderByDesc('l.id');
    }

    // ---- Danh mục cho bộ lọc -------------------------------------------------

    public function categoryOptions(): Collection
    {
        return DocumentCategory::orderBy('name')->get(['id', 'name']);
    }

    public function departmentOptions(): array
    {
        return Department::orderBy('name')->get(['id', 'name'])
            ->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all();
    }

    // ---- Nền truy vấn --------------------------------------------------------

    /** Tài liệu đã xuất bản khớp bộ lọc danh mục / độ mật / đơn vị sở hữu. */
    public function documentsQuery(array $filters): EloquentBuilder
    {
        $query = Document::query()->published();

        $this->applyDocumentFilters($query, $filters, 'documents');

        return $query;
    }

    /**
     * Log trong kỳ, nối bảng tài liệu để lọc theo thuộc tính tài liệu.
     *
     * @param  array<int, string>|null  $actions  giới hạn thêm theo hành động (giao với bộ lọc)
     */
    public function logsQuery(ReportPeriod $period, array $filters, ?array $actions = null, ?array $range = null): Builder
    {
        $query = DB::table('document_access_logs as l')
            ->join('documents as d', 'd.id', '=', 'l.document_id')
            ->whereNull('d.deleted_at')
            ->whereBetween('l.accessed_at', $range ?? $period->range());

        $this->applyDocumentFilters($query, $filters, 'd');

        $allowed = $this->allowedActions($filters, $actions ?? array_keys(self::ACTION_LABELS));
        $query->whereIn('l.action', $allowed ?: ['__none__']);

        return $query;
    }

    /**
     * Lượt xem đã gộp: mỗi dòng là một lượt mở (xem file hoặc phát video) được tính.
     *
     * Cách gộp: xếp các lượt mở của cùng một người trên cùng tài liệu theo thời gian, dùng LAG()
     * lấy lượt liền trước. Lượt chỉ được tính khi không có lượt trước hoặc lượt trước cách
     * từ 5 phút trở lên — lượt cách dưới 5 phút nối vào lượt trước (bấm tải lại trang, mở
     * lại tab không làm phồng số). Gộp theo chuỗi: xem liên tục cách nhau 4 phút trong một
     * giờ vẫn là một lượt.
     *
     * Quét lùi thêm 5 phút trước đầu kỳ để lượt sát đầu kỳ nối được vào lượt cuối kỳ trước,
     * rồi mới bỏ các dòng nằm ngoài kỳ.
     */
    public function mergedViewsQuery(ReportPeriod $period, array $filters): Builder
    {
        $minutes = self::VIEW_MERGE_MINUTES;
        $person = $this->personExpr();
        [$from, $to] = $period->range();

        $ordered = $this->logsQuery($period, $filters, self::OPEN_ACTIONS, [$from->subMinutes($minutes), $to])
            ->select(['l.id', 'l.document_id', 'l.accessed_at'])
            ->selectRaw($person . ' as person')
            ->selectRaw('LAG(l.accessed_at) OVER (PARTITION BY l.document_id, ' . $person . ' ORDER BY l.accessed_at, l.id) as prev_at');

        return DB::query()->fromSub($ordered, 'v')
            ->select(['v.id', 'v.document_id', 'v.person', 'v.accessed_at'])
            ->where('v.accessed_at', '>=', $from)
            ->where(fn (Builder $q) => $q->whereNull('v.prev_at')
                ->orWhereRaw('TIMESTAMPDIFF(SECOND, v.prev_at, v.accessed_at) >= ?', [$minutes * 60]));
    }

    /**
     * Khóa định danh người truy cập: ưu tiên nhân viên, rồi tài khoản; log mồ côi coi như một người riêng.
     */
    private function personExpr(): string
    {
        return "COALESCE(CONCAT('e', l.employee_id), CONCAT('u', l.user_id), CONCAT('l', l.id))";
    }

    private function countAction(ReportPeriod $period, array $filters, string $action): ?int
    {
        return $this->allowedActions($filters, [$action])
            ? $this->logsQuery($period, $filters, [$action])->count()
            : null;
    }

    /** Giao giữa nhóm hành động cần đếm và bộ lọc hành động. */
    private function allowedActions(array $filters, array $actions): array
    {
        $filter = $filters['action'] ?? null;

        if ($filter && array_key_exists($filter, self::ACTION_LABELS)) {
            return in_array($filter, $actions, true) ? [$filter] : [];
        }

        return array_values($actions);
    }

    private function applyDocumentFilters($query, array $filters, string $table): void
    {
        if (! empty($filters['category_id'])) {
            $category = DocumentCategory::find($filters['category_id']);
            $query->whereIn($table . '.document_category_id', $category ? $category->descendantIds() : [0]);
        }

        if (! empty($filters['confidentiality']) && array_key_exists($filters['confidentiality'], self::CONFIDENTIALITY_LABELS)) {
            $query->where($table . '.confidentiality', $filters['confidentiality']);
        }

        if (! empty($filters['owner_department_id'])) {
            $department = Department::find($filters['owner_department_id']);
            $query->whereIn($table . '.owner_department_id', $department ? $department->descendantIds() : [0]);
        }
    }
}
