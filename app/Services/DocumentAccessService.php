<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentAccessRule;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Tính quyền truy cập tài liệu theo điều kiện cấu hình (spec 3.3.1)
 * và ghi log truy cập phục vụ truy vết rò rỉ (spec 3.3.2).
 *
 * Nguyên tắc giải quyết rule:
 *  1. Chỉ xét các rule đang active áp cho chính tài liệu hoặc danh mục chứa nó.
 *  2. Rule của tài liệu đè rule của danh mục (cụ thể thắng chung).
 *  3. Trong cùng mức, priority cao xét trước; deny thắng allow khi cùng priority.
 *  4. Không rule nào khớp => không có quyền (mặc định đóng, an toàn cho ngân hàng).
 */
class DocumentAccessService
{
    /** Nhân viên đã nghỉ việc bị chặn ở mọi tài liệu, bất kể rule cấu hình thế nào. */
    public function canView(Employee $employee, Document $document): bool
    {
        return $this->resolve($employee, $document)['can_view'];
    }

    public function canDownload(Employee $employee, Document $document): bool
    {
        $permission = $this->resolve($employee, $document);

        // Cờ allow_download của tài liệu là trần cứng: rule không thể nới rộng hơn
        return $permission['can_download'] && $document->allow_download;
    }

    public function canPrint(Employee $employee, Document $document): bool
    {
        return $this->resolve($employee, $document)['can_print'];
    }

    /**
     * Trả về ma trận quyền đã giải quyết cho cặp (nhân viên, tài liệu).
     *
     * @return array{can_view: bool, can_download: bool, can_print: bool, reason: string}
     */
    public function resolve(Employee $employee, Document $document): array
    {
        $denied = ['can_view' => false, 'can_download' => false, 'can_print' => false];

        if (! $employee->isActive()) {
            return array_merge($denied, ['reason' => 'Nhân viên không còn ở trạng thái làm việc']);
        }

        if ($document->status !== Document::STATUS_PUBLISHED) {
            return array_merge($denied, ['reason' => 'Tài liệu chưa được xuất bản']);
        }

        $rules = $this->applicableRules($document)
            ->filter(fn (DocumentAccessRule $rule) => $rule->matches($employee));

        if ($rules->isEmpty()) {
            return array_merge($denied, ['reason' => 'Không có quyền được cấu hình cho nhân viên này']);
        }

        // Rule gắn trực tiếp tài liệu là mức cụ thể hơn, xét trước rule của danh mục.
        // Trong cùng mức: priority giảm dần, deny trước allow.
        $winner = $rules->sort(function (DocumentAccessRule $a, DocumentAccessRule $b) {
            return [$b->document_id ? 1 : 0, $b->priority, $b->isDeny() ? 1 : 0]
                <=> [$a->document_id ? 1 : 0, $a->priority, $a->isDeny() ? 1 : 0];
        })->first();

        if ($winner->isDeny()) {
            return array_merge($denied, ['reason' => 'Bị chặn bởi quy tắc phân quyền']);
        }

        return [
            'can_view' => (bool) $winner->can_view,
            'can_download' => (bool) $winner->can_download,
            'can_print' => (bool) $winner->can_print,
            'reason' => '',
        ];
    }

    /**
     * Query các tài liệu nhân viên được xem — dùng cho màn hình danh mục (spec 4.1).
     * Lọc ở tầng SQL thay vì duyệt từng tài liệu để danh sách còn phân trang được.
     */
    public function visibleDocumentsQuery(Employee $employee): Builder
    {
        $query = Document::query()->published();

        if (! $employee->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($employee) {
            $q->whereHas('accessRules', fn (Builder $r) => $this->constrainRuleQuery($r, $employee))
                ->orWhereHas('category.accessRules', fn (Builder $r) => $this->constrainRuleQuery($r, $employee));
        })->whereDoesntHave('accessRules', function (Builder $r) use ($employee) {
            // Loại sớm các tài liệu có rule deny khớp nhân viên
            $this->constrainRuleQuery($r, $employee)->where('effect', DocumentAccessRule::EFFECT_DENY);
        });
    }

    /** Ghi log một lần truy cập tài liệu (spec 3.3.2). */
    public function logAccess(
        Employee $employee,
        Document $document,
        string $action = DocumentAccessLog::ACTION_VIEW,
        ?string $denyReason = null,
    ): DocumentAccessLog {
        return DocumentAccessLog::create([
            'document_id' => $document->id,
            'user_id' => $employee->user_id,
            'employee_id' => $employee->id,
            'action' => $action,
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 500, ''),
            'watermark_token' => $this->watermarkToken($employee, $document),
            'deny_reason' => $denyReason,
            'accessed_at' => now(),
        ]);
    }

    /**
     * Mã watermark nhúng vào bản xem, cho phép truy ngược bản rò rỉ về đúng
     * phiên truy cập (spec 3.3.2). Băm để không lộ id nội bộ trên mặt tài liệu.
     */
    public function watermarkToken(Employee $employee, Document $document): string
    {
        return substr(hash('sha256', implode('|', [
            $employee->id,
            $document->id,
            now()->timestamp,
            config('app.key'),
        ])), 0, 32);
    }

    /** Nội dung watermark hiển thị đè lên tài liệu/video khi xem (spec 3.3.2). */
    public function watermarkText(Employee $employee): string
    {
        return implode(' • ', array_filter([
            $employee->full_name,
            $employee->email ?: $employee->employee_code,
            now()->format('d/m/Y H:i'),
        ]));
    }

    /** Các rule áp dụng cho tài liệu: rule riêng + rule của danh mục và danh mục cha. */
    private function applicableRules(Document $document)
    {
        $categoryIds = [];

        if ($document->document_category_id) {
            $category = $document->category;

            while ($category) {
                $categoryIds[] = $category->id;
                $category = $category->parent;
            }
        }

        return DocumentAccessRule::query()
            ->active()
            ->with(['department'])
            ->where(function (Builder $q) use ($document, $categoryIds) {
                $q->where('document_id', $document->id);

                if ($categoryIds) {
                    $q->orWhereIn('document_category_id', $categoryIds);
                }
            })
            ->get();
    }

    /** Ràng buộc query rule theo hồ sơ nhân viên, dùng chung cho các whereHas ở trên. */
    private function constrainRuleQuery(Builder $query, Employee $employee): Builder
    {
        $departmentIds = [$employee->department_id];

        if ($employee->department) {
            // Rule của phòng ban cha có include_sub_departments vẫn phải khớp
            $node = $employee->department;

            while ($node = $node->parent) {
                $departmentIds[] = $node->id;
            }
        }

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($employee, $departmentIds) {
                $q->whereNull('department_id')->orWhereIn('department_id', array_filter($departmentIds));
            })
            ->where(function (Builder $q) use ($employee) {
                $q->whereNull('job_title_id')->orWhere('job_title_id', $employee->job_title_id);
            })
            ->where(function (Builder $q) use ($employee) {
                $q->whereNull('job_grade_id')->orWhere('job_grade_id', $employee->job_grade_id);
            })
            ->where(function (Builder $q) use ($employee) {
                $q->whereNull('min_grade_level')
                    ->orWhere('min_grade_level', '<=', $employee->jobGrade?->level ?? 0);
            })
            ->where(function (Builder $q) use ($employee) {
                $q->whereNull('employment_status')->orWhere('employment_status', $employee->employment_status);
            });
    }
}
