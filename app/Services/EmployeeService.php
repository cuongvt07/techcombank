<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAssignmentHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Nghiệp vụ hồ sơ nhân sự (spec 3.1.2).
 *
 * Điểm mấu chốt: mỗi lần đổi phòng ban/chức danh/cấp bậc phải làm ba việc cùng lúc,
 * nếu tách rời sẽ sinh dữ liệu không nhất quán —
 *   1. Đóng bản ghi lịch sử đang hiệu lực, mở bản ghi mới (audit trail).
 *   2. Cập nhật hồ sơ.
 *   3. Gán lại khoá học theo điều kiện mới (spec 3.3.1).
 */
class EmployeeService
{
    public function __construct(private readonly CourseAssignmentService $assignment)
    {
    }

    /**
     * Điều chuyển nhân viên sang vị trí mới.
     *
     * @param  array{department_id?: int|null, job_title_id?: int|null, job_grade_id?: int|null, employment_status?: string}  $changes
     * @return int Số khoá học mới được gán theo điều kiện mới
     */
    public function reassign(Employee $employee, array $changes, ?string $reason = null, ?int $changedBy = null): int
    {
        $tracked = ['department_id', 'job_title_id', 'job_grade_id', 'employment_status'];
        $payload = array_intersect_key($changes, array_flip($tracked));

        // Không có gì thay đổi thì không ghi lịch sử rỗng
        $hasChange = collect($payload)->contains(
            fn ($value, $key) => $employee->{$key} !== $value
        );

        if (! $hasChange) {
            return 0;
        }

        DB::transaction(function () use ($employee, $payload, $reason, $changedBy) {
            // Đóng bản ghi đang hiệu lực trước khi mở bản mới
            $employee->assignmentHistories()
                ->whereNull('effective_to')
                ->update(['effective_to' => now()->toDateString()]);

            $employee->update($payload);

            EmployeeAssignmentHistory::create([
                'employee_id' => $employee->id,
                'department_id' => $employee->department_id,
                'job_title_id' => $employee->job_title_id,
                'job_grade_id' => $employee->job_grade_id,
                'employment_status' => $employee->employment_status,
                'effective_from' => now()->toDateString(),
                'change_reason' => $reason,
                'changed_by' => $changedBy,
            ]);
        });

        return $this->assignment->syncForEmployee($employee->refresh());
    }

    /**
     * Cho nhân viên nghỉ việc: khoá tài khoản đăng nhập nhưng giữ nguyên hồ sơ
     * và toàn bộ lịch sử học tập (spec 3.1.1 — vòng đời nhân sự).
     */
    public function resign(Employee $employee, ?string $reason = null, ?int $changedBy = null): void
    {
        DB::transaction(function () use ($employee, $reason, $changedBy) {
            $employee->assignmentHistories()
                ->whereNull('effective_to')
                ->update(['effective_to' => now()->toDateString()]);

            $employee->update([
                'employment_status' => Employee::STATUS_RESIGNED,
                'resigned_at' => now()->toDateString(),
                'is_new_hire' => false,
            ]);

            EmployeeAssignmentHistory::create([
                'employee_id' => $employee->id,
                'department_id' => $employee->department_id,
                'job_title_id' => $employee->job_title_id,
                'job_grade_id' => $employee->job_grade_id,
                'employment_status' => Employee::STATUS_RESIGNED,
                'effective_from' => now()->toDateString(),
                'change_reason' => $reason ?? 'Nghỉ việc',
                'changed_by' => $changedBy,
            ]);

            // Khoá truy cập tự động theo spec 3.1.1
            $employee->user?->update(['status' => User::STATUS_DISABLED]);
        });
    }

    /** Chuyển nhân viên thử việc sang chính thức. */
    public function makeOfficial(Employee $employee, ?int $changedBy = null): int
    {
        return $this->reassign(
            $employee,
            ['employment_status' => Employee::STATUS_OFFICIAL],
            'Chuyển chính thức',
            $changedBy,
        );
    }

    /**
     * Ghi bản ghi lịch sử đầu tiên cho nhân viên mới tạo,
     * để audit trail không bị hổng đoạn từ lúc vào làm.
     */
    public function recordInitialAssignment(Employee $employee, ?int $changedBy = null): EmployeeAssignmentHistory
    {
        return EmployeeAssignmentHistory::create([
            'employee_id' => $employee->id,
            'department_id' => $employee->department_id,
            'job_title_id' => $employee->job_title_id,
            'job_grade_id' => $employee->job_grade_id,
            'employment_status' => $employee->employment_status,
            'effective_from' => ($employee->joined_at ?? now())->toDateString(),
            'change_reason' => 'Khởi tạo hồ sơ',
            'changed_by' => $changedBy,
        ]);
    }
}
