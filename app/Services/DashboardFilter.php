<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Support\Reports\ReportPeriod;

/**
 * Bộ lọc của trang Tổng quan đào tạo, gom lại một chỗ để mọi khối số liệu
 * (KPI, biểu đồ, bảng nhắc học) cùng áp một phạm vi — số trên thẻ và số
 * trong bảng phải khớp nhau thì người xem mới tin.
 */
final class DashboardFilter
{
    public const COURSE_ALL = 'all';
    public const COURSE_MANDATORY = 'mandatory';
    public const COURSE_ELECTIVE = 'elective';

    public const COURSE_TYPES = [
        self::COURSE_ALL => 'Bắt buộc + Tự chọn',
        self::COURSE_MANDATORY => 'Bắt buộc',
        self::COURSE_ELECTIVE => 'Tự chọn',
    ];

    public const COMPARE_PREVIOUS = 'previous';
    public const COMPARE_LAST_YEAR = 'last_year';

    public const COMPARES = [
        self::COMPARE_PREVIOUS => 'Kỳ trước',
        self::COMPARE_LAST_YEAR => 'Cùng kỳ năm trước',
    ];

    /** Mặc định chỉ tính người đang làm việc: nghỉ việc / tạm hoãn làm sai tỷ lệ. */
    public const DEFAULT_STATUSES = [Employee::STATUS_PROBATION, Employee::STATUS_OFFICIAL];

    /** @var array<int, int>|null */
    private ?array $departmentIdsCache = null;

    /**
     * @param  array<int, string>  $employmentStatuses  rỗng = mọi trạng thái
     */
    public function __construct(
        public readonly ReportPeriod $period,
        public readonly string $compare = self::COMPARE_PREVIOUS,
        public readonly ?int $departmentId = null,
        public readonly ?int $jobTitleId = null,
        public readonly string $courseType = self::COURSE_ALL,
        public readonly array $employmentStatuses = self::DEFAULT_STATUSES,
    ) {
    }

    /** Kỳ so sánh cùng độ dài: liền trước hoặc cùng kỳ năm trước. */
    public function comparePeriod(): ReportPeriod
    {
        return $this->compare === self::COMPARE_LAST_YEAR
            ? $this->period->sameLastYear()
            : $this->period->previous();
    }

    /** Cùng bộ lọc nhưng đổi kỳ — để tính chỉ số của kỳ so sánh. */
    public function withPeriod(ReportPeriod $period): self
    {
        $clone = new self($period, $this->compare, $this->departmentId, $this->jobTitleId,
            $this->courseType, $this->employmentStatuses);
        $clone->departmentIdsCache = $this->departmentIdsCache;

        return $clone;
    }

    /**
     * Đơn vị đã chọn + toàn bộ đơn vị con. Nạp cây một lần rồi duyệt ở PHP thay vì
     * Department::descendantIds() (mỗi cấp một query).
     *
     * @return array<int, int>|null null = không lọc đơn vị
     */
    public function departmentIds(): ?array
    {
        if (! $this->departmentId) {
            return null;
        }

        if ($this->departmentIdsCache === null) {
            $childrenOf = Department::query()->pluck('parent_id', 'id')
                ->groupBy(fn ($parent) => (int) $parent, preserveKeys: true)
                ->map(fn ($group) => $group->keys()->all());

            $ids = [];
            $queue = [$this->departmentId];
            while ($queue) {
                $id = array_shift($queue);
                if (in_array($id, $ids, true)) {
                    continue;
                }
                $ids[] = $id;
                array_push($queue, ...($childrenOf[$id] ?? []));
            }

            $this->departmentIdsCache = $ids;
        }

        return $this->departmentIdsCache;
    }

    /** Ngày "hôm nay" theo giờ VN — mốc tính quá hạn (cột due_date là ngày VN). */
    public static function today(): string
    {
        return now(ReportPeriod::TIMEZONE)->toDateString();
    }
}
