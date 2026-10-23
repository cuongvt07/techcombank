<?php

namespace App\Services\Reports;

use App\Enums\RoleName;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\NotificationCenter;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Số liệu báo cáo yêu cầu hỗ trợ (SCR_009).
 *
 * Quy ước dùng chung cho mọi chỉ số:
 * - "Xong" = COALESCE(resolved_at, closed_at): phiếu đóng thẳng không qua bước
 *   giải quyết vẫn tính là đã xử lý, khớp với SupportRequest::isOverSla().
 * - Phiếu còn mở là tồn đọng dù gửi trước kỳ: bỏ chúng ra thì phiếu treo lâu nhất
 *   (đáng chú ý nhất) lại biến mất khỏi báo cáo.
 * - Mốc "bây giờ" luôn bind từ PHP, không dùng NOW() của MySQL (lệch múi giờ).
 *
 * Bộ lọc $filters: topic, priority, assignee ('' = tất cả, 'none' = chưa phân công, id),
 * status ('open' | '' | một trạng thái), over_sla (bool) — hai khóa sau chỉ áp cho bảng.
 */
class SupportReportService
{
    private const DONE_AT = 'COALESCE(support_requests.resolved_at, support_requests.closed_at)';

    /** Số chủ đề tối đa vẽ riêng; phần còn lại gộp một dòng để biểu đồ không dài vô tận. */
    private const MAX_TOPICS = 8;

    /** Phiếu khớp bộ lọc chủ đề / ưu tiên / người phụ trách. */
    public function base(array $filters): Builder
    {
        $assignee = (string) ($filters['assignee'] ?? '');

        return SupportRequest::query()
            ->when(($filters['topic'] ?? '') !== '', fn ($q) => $q->where('support_requests.topic', $filters['topic']))
            ->when(($filters['priority'] ?? '') !== '', fn ($q) => $q->where('support_requests.priority', $filters['priority']))
            ->when($assignee === 'none', fn ($q) => $q->whereNull('support_requests.assigned_to'))
            ->when(ctype_digit($assignee), fn ($q) => $q->where('support_requests.assigned_to', (int) $assignee));
    }

    /**
     * Sáu thẻ chỉ số.
     *
     * @return array{new: int, open: int, open_over_sla: int, resolved: int, median_hours: ?float,
     *               sla_rate: ?float, sla_rate_prev: ?float, unassigned: int}
     */
    public function kpis(ReportPeriod $period, array $filters): array
    {
        $now = now();

        $backlog = $this->base($filters)->open()->where('created_at', '<=', $period->to());

        $done = $this->doneRows($period, $filters);
        $prev = $this->slaCounts($period->previous(), $filters);

        return [
            'new' => $this->base($filters)->whereBetween('created_at', $period->range())->count(),
            'open' => (clone $backlog)->count(),
            'open_over_sla' => (clone $backlog)->where('sla_due_at', '<', $now)->count(),
            'resolved' => $done->count(),
            'median_hours' => $this->median($done->pluck('seconds')),
            'sla_rate' => $this->rate($done->where('with_sla', 1)->count(), $done->where('on_time', 1)->count()),
            'sla_rate_prev' => $this->rate($prev['with_sla'], $prev['on_time']),
            'unassigned' => (clone $backlog)->whereNull('assigned_to')->count(),
        ];
    }

    /**
     * Mới / đã giải quyết / tồn đọng cuối mỗi tuần — gom một query (mỗi tuần 3 cột SUM).
     *
     * @return array{labels: array, new: array, resolved: array, backlog: array, note: ?string}
     */
    public function weekly(ReportPeriod $period, array $filters): array
    {
        $weeks = $period->weeks()->values();
        $selects = [];
        $bindings = [];

        foreach ($weeks as $i => $week) {
            $from = $week['start']->utc();
            $to = $week['end']->utc();

            $selects[] = "SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS new_{$i}";
            array_push($bindings, $from, $to);

            $selects[] = "SUM(CASE WHEN status IN ('resolved', 'closed') AND " . self::DONE_AT . " BETWEEN ? AND ? THEN 1 ELSE 0 END) AS resolved_{$i}";
            array_push($bindings, $from, $to);

            // Tồn đọng cuối tuần: đã gửi trước mốc đó và lúc đó chưa xong.
            // Phiếu mở lại bị xóa mốc giải quyết cũ, nên được tính là tồn suốt từ lúc gửi.
            $selects[] = "SUM(CASE WHEN created_at <= ? AND (status IN ('new', 'in_progress') OR " . self::DONE_AT . " > ?) THEN 1 ELSE 0 END) AS backlog_{$i}";
            array_push($bindings, $to, $to);
        }

        $row = $selects
            ? (array) $this->base($filters)->toBase()->selectRaw(implode(', ', $selects), $bindings)->first()
            : [];

        $new = $weeks->keys()->map(fn ($i) => (int) ($row["new_{$i}"] ?? 0))->all();
        $resolved = $weeks->keys()->map(fn ($i) => (int) ($row["resolved_{$i}"] ?? 0))->all();
        $backlog = $weeks->keys()->map(fn ($i) => (int) ($row["backlog_{$i}"] ?? 0))->all();

        return [
            'labels' => $weeks->pluck('label')->all(),
            'new' => $new,
            'resolved' => $resolved,
            'backlog' => $backlog,
            'note' => $this->backlogNote($new, $resolved, $backlog),
        ];
    }

    /**
     * Số phiếu gửi trong kỳ theo chủ đề + trung vị giờ xử lý của phiếu xong trong kỳ.
     *
     * @return array{rows: array<int, array{topic: string, count: int, median_hours: ?float, done: int, late: int}>, note: ?string, alert: bool}
     */
    public function topics(ReportPeriod $period, array $filters): array
    {
        $counts = $this->base($filters)
            ->whereBetween('created_at', $period->range())
            ->groupBy('topic')
            ->selectRaw('topic, COUNT(*) AS total')
            ->pluck('total', 'topic');

        $done = $this->doneRows($period, $filters)->groupBy('topic');

        $rows = $counts->keys()->merge($done->keys())->unique()
            ->map(function ($topic) use ($counts, $done) {
                $items = $done->get($topic, collect());

                return [
                    'topic' => (string) $topic,
                    'count' => (int) ($counts[$topic] ?? 0),
                    'seconds' => $items->pluck('seconds'),
                    'done' => $items->count(),
                    'late' => $items->where('with_sla', 1)->where('on_time', 0)->count(),
                ];
            })
            ->sortByDesc('count')
            ->values();

        // Gộp đuôi dài thành một dòng "Chủ đề khác" — tính lại trung vị trên toàn bộ phiếu gộp
        if ($rows->count() > self::MAX_TOPICS) {
            $tail = $rows->slice(self::MAX_TOPICS - 1);
            $rows = $rows->take(self::MAX_TOPICS - 1)->push([
                'topic' => 'Chủ đề khác (' . $tail->count() . ')',
                'count' => $tail->sum('count'),
                'seconds' => $tail->pluck('seconds')->flatten(),
                'done' => $tail->sum('done'),
                'late' => $tail->sum('late'),
                'grouped' => true,
            ]);
        }

        $rows = $rows->map(function ($row) {
            $row['median_hours'] = $this->median($row['seconds']);
            unset($row['seconds']);

            return $row;
        });

        [$note, $alert] = $this->topicNote($rows);

        return ['rows' => $rows->all(), 'note' => $note, 'alert' => $alert];
    }

    /**
     * Danh sách phiếu cho bảng / file xuất: gửi trong kỳ, cộng phiếu còn mở gửi trước kỳ.
     * Kèm cột overdue_seconds = (lúc xong hoặc bây giờ) − hạn SLA; âm = còn hạn.
     */
    public function listQuery(ReportPeriod $period, array $filters, string $sort = 'overdue', string $direction = 'desc'): Builder
    {
        $now = now();
        [$from, $to] = $period->range();
        $status = (string) ($filters['status'] ?? 'open');

        $query = $this->base($filters)
            ->with([
                'employee:id,full_name,employee_code,department_id',
                'employee.department:id,name',
                'assignedTo:id,name',
            ])
            ->select('support_requests.*')
            ->selectRaw('TIMESTAMPDIFF(SECOND, support_requests.sla_due_at, COALESCE(support_requests.resolved_at, support_requests.closed_at, ?)) AS overdue_seconds', [$now])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('support_requests.created_at', [$from, $to])
                    ->orWhere(fn ($open) => $open->open()->where('support_requests.created_at', '<', $from));
            })
            ->when($status === 'open', fn ($q) => $q->open())
            ->when(! in_array($status, ['', 'open'], true), fn ($q) => $q->where('support_requests.status', $status))
            ->when(! empty($filters['over_sla']), fn ($q) => $q->whereNotNull('support_requests.sla_due_at')
                ->whereRaw('COALESCE(support_requests.resolved_at, support_requests.closed_at, ?) > support_requests.sla_due_at', [$now]));

        $direction = $direction === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'created_at' => $query->orderBy('support_requests.created_at', $direction),
            'sla_due_at' => $query->orderBy('support_requests.sla_due_at', $direction),
            'priority' => $query->orderByRaw("FIELD(support_requests.priority, 'high', 'normal', 'low') " . ($direction === 'desc' ? 'ASC' : 'DESC')),
            default => $query->orderBy('overdue_seconds', $direction),
        };

        return $query->orderBy('support_requests.id');
    }

    /** Các chủ đề đang có trong dữ liệu (topic là chuỗi tự do, không có bảng danh mục). */
    public function topicOptions(): Collection
    {
        return SupportRequest::query()->distinct()->orderBy('topic')->pluck('topic')->filter()->values();
    }

    /** Người có thể nhận phiếu: quản trị viên hoặc người có quyền xử lý phiếu hỗ trợ. */
    public function assignableUsers(): Collection
    {
        $ids = User::role(RoleName::ADMIN->value)->pluck('id')
            ->merge(User::permission('support.update')->pluck('id'))
            ->unique();

        return User::active()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    /** Lựa chọn bộ lọc "Người phụ trách": người nhận được phiếu + người đang giữ phiếu (kể cả đã khóa). */
    public function assigneeOptions(): Collection
    {
        $holders = SupportRequest::query()->whereNotNull('assigned_to')->distinct()->pluck('assigned_to');

        return User::query()
            ->whereIn('id', $this->assignableUsers()->pluck('id')->merge($holders)->unique())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Phân công hàng loạt — cùng luật với SupportManager::assign: phiếu mới được gán
     * người thì coi như đã tiếp nhận (new → in_progress). Phiếu đã xong bị bỏ qua.
     *
     * @param  array<int, int|string>  $ids
     * @return array{assigned: int, skipped: int}
     */
    public function bulkAssign(array $ids, int $userId): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return ['assigned' => 0, 'skipped' => 0];
        }

        // Chỉ nhận phiếu đang mở CHƯA có người phụ trách: phiếu đã có người nhận thì
        // không phân công đè ở đây (đổi người phụ trách làm từng phiếu ở màn Phiếu hỗ trợ)
        $targetIds = collect();
        $assigned = DB::transaction(function () use ($ids, $userId, &$targetIds) {
            // Ghi lại đúng các phiếu sắp được giao (khóa dòng) để chỉ báo cho chúng
            $targetIds = SupportRequest::whereIn('id', $ids)
                ->whereIn('status', [SupportRequest::STATUS_NEW, SupportRequest::STATUS_IN_PROGRESS])
                ->whereNull('assigned_to')
                ->lockForUpdate()
                ->pluck('id');

            $fromNew = SupportRequest::whereIn('id', $ids)->where('status', SupportRequest::STATUS_NEW)
                ->whereNull('assigned_to')
                ->update(['assigned_to' => $userId, 'status' => SupportRequest::STATUS_IN_PROGRESS, 'updated_at' => now()]);
            $inProgress = SupportRequest::whereIn('id', $ids)->where('status', SupportRequest::STATUS_IN_PROGRESS)
                ->whereNull('assigned_to')
                ->update(['assigned_to' => $userId, 'updated_at' => now()]);

            return $fromNew + $inProgress;
        });

        $existing = SupportRequest::whereIn('id', $ids)->count();

        $this->notifyAssigned($targetIds);

        return ['assigned' => $assigned, 'skipped' => $existing - $assigned];
    }

    // ---- Nội bộ ----------------------------------------------------------------

    /**
     * Báo người được giao cho từng phiếu THỰC SỰ vừa giao (phiếu bị bỏ qua vì đã
     * có người phụ trách thì không báo); nhân viên gửi phiếu được báo đã tiếp nhận.
     */
    private function notifyAssigned(Collection $targetIds): void
    {
        if ($targetIds->isEmpty()) {
            return;
        }

        $notifier = app(NotificationCenter::class);
        $actorId = auth()->id();

        SupportRequest::whereIn('id', $targetIds)->get()
            ->each(function (SupportRequest $request) use ($notifier, $actorId) {
                $notifier->supportAssigned($request, $actorId);
                $notifier->supportAccepted($request);
            });
    }

    /** Phiếu xong trong kỳ: chủ đề, số giây xử lý, có hạn SLA không, đúng hạn không. */
    private function doneRows(ReportPeriod $period, array $filters): Collection
    {
        return $this->base($filters)
            ->toBase()
            ->whereIn('status', [SupportRequest::STATUS_RESOLVED, SupportRequest::STATUS_CLOSED])
            ->whereRaw(self::DONE_AT . ' BETWEEN ? AND ?', $period->range())
            ->selectRaw('topic, TIMESTAMPDIFF(SECOND, created_at, ' . self::DONE_AT . ') AS seconds,'
                . ' CASE WHEN sla_due_at IS NULL THEN 0 ELSE 1 END AS with_sla,'
                . ' CASE WHEN sla_due_at IS NOT NULL AND ' . self::DONE_AT . ' <= sla_due_at THEN 1 ELSE 0 END AS on_time')
            ->get()
            ->map(fn ($r) => ['topic' => $r->topic, 'seconds' => max(0, (int) $r->seconds), 'with_sla' => (int) $r->with_sla, 'on_time' => (int) $r->on_time]);
    }

    /** @return array{with_sla: int, on_time: int} */
    private function slaCounts(ReportPeriod $period, array $filters): array
    {
        $row = $this->base($filters)
            ->toBase()
            ->whereIn('status', [SupportRequest::STATUS_RESOLVED, SupportRequest::STATUS_CLOSED])
            ->whereNotNull('sla_due_at')
            ->whereRaw(self::DONE_AT . ' BETWEEN ? AND ?', $period->range())
            ->selectRaw('COUNT(*) AS with_sla, SUM(CASE WHEN ' . self::DONE_AT . ' <= sla_due_at THEN 1 ELSE 0 END) AS on_time')
            ->first();

        return ['with_sla' => (int) ($row->with_sla ?? 0), 'on_time' => (int) ($row->on_time ?? 0)];
    }

    /** Trung vị (giờ) — dùng trung vị vì vài phiếu treo cả tuần kéo lệch trung bình. */
    private function median(Collection $seconds): ?float
    {
        $values = $seconds->map(fn ($s) => (int) $s)->sort()->values();
        $n = $values->count();

        if ($n === 0) {
            return null;
        }

        $mid = intdiv($n, 2);
        $median = $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;

        return round($median / 3600, 1);
    }

    private function rate(int $total, int $part): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }

    /** Nhận định khi tồn đọng tăng nhiều tuần liền ở cuối kỳ. */
    private function backlogNote(array $new, array $resolved, array $backlog): ?string
    {
        $last = count($backlog) - 1;
        $streak = 0;

        for ($i = $last; $i > 0 && $backlog[$i] > $backlog[$i - 1]; $i--) {
            $streak++;
        }

        if ($streak >= 2) {
            $weeks = range($last - $streak + 1, $last);
            $allBehind = collect($weeks)->every(fn ($i) => $resolved[$i] < $new[$i]);

            return "Tồn đọng tăng {$streak} tuần liên tiếp"
                . ($allBehind ? ': mỗi tuần đều giải quyết ít hơn số nhận vào.' : '.');
        }

        return null;
    }

    /** @return array{0: ?string, 1: bool} */
    private function topicNote(Collection $rows): array
    {
        $slowest = $rows->filter(fn ($r) => $r['median_hours'] !== null && empty($r['grouped']))
            ->sortByDesc('median_hours')
            ->first();

        if (! $slowest) {
            return [null, false];
        }

        $limit = SupportRequest::SLA_HOURS[SupportRequest::PRIORITY_NORMAL];
        $hours = number_format($slowest['median_hours'], 1, ',', '.');
        $late = $slowest['late'] > 0 ? " {$slowest['late']}/{$slowest['done']} phiếu giải quyết trễ hạn." : '';

        if ($slowest['median_hours'] > $limit) {
            return ['Nhóm <strong class="text-brand-ink">' . e($slowest['topic']) . "</strong> mất {$hours} giờ (trung vị), vượt SLA {$limit} giờ của mức Thường." . $late, true];
        }

        return ['Chủ đề xử lý chậm nhất: <strong class="text-brand-ink">' . e($slowest['topic']) . "</strong> — trung vị {$hours} giờ, trong SLA {$limit} giờ của mức Thường." . $late, false];
    }
}
