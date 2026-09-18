<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAssignmentRule;
use App\Models\Employee;
use App\Models\Enrollment;
use Illuminate\Support\Facades\DB;

/**
 * Gán khoá học cho nhân viên (spec 3.2.4) theo hai đường:
 *  - Tự động từ course_assignment_rules khi nhân sự thay đổi vị trí (spec 3.3.1)
 *  - Gán thủ công bởi admin
 *
 * Nguyên tắc: chỉ THÊM enrollment, không tự xoá khi nhân viên rời phòng ban —
 * lịch sử học tập phải giữ nguyên (spec 3.2.4), việc gỡ là hành động có chủ đích của admin.
 */
class CourseAssignmentService
{
    public function __construct(private readonly ProgressService $progress)
    {
    }

    /**
     * Đồng bộ toàn bộ khoá học mà một nhân viên phải học theo điều kiện hiện tại.
     * Gọi khi tạo nhân viên mới và mỗi lần đổi phòng ban/chức danh/cấp bậc.
     *
     * @return int Số enrollment được tạo mới
     */
    public function syncForEmployee(Employee $employee): int
    {
        if (! $employee->isActive()) {
            return 0;
        }

        $created = 0;

        $rules = CourseAssignmentRule::query()
            ->active()
            ->with(['course', 'department'])
            ->whereHas('course', fn ($q) => $q->where('status', Course::STATUS_PUBLISHED))
            ->get();

        foreach ($rules as $rule) {
            if (! $rule->matches($employee)) {
                continue;
            }

            if ($this->assignFromRule($employee, $rule)) {
                $created++;
            }
        }

        $created += $this->assignOnboardingCourses($employee);

        return $created;
    }

    /**
     * Áp một rule cho toàn bộ nhân viên khớp điều kiện.
     * Dùng khi admin vừa tạo/sửa rule và muốn áp ngay cho người đang làm việc.
     *
     * @return int Số enrollment được tạo mới
     */
    public function applyRule(CourseAssignmentRule $rule): int
    {
        // Nạp lại từ DB: rule vừa create() chưa có giá trị mặc định của cột (is_active)
        // và chưa load quan hệ course, nên kiểm tra trên instance thô sẽ sai.
        $rule->refresh()->loadMissing('course', 'department');

        if (! $rule->is_active || ! $rule->course?->isPublished()) {
            return 0;
        }

        $created = 0;

        Employee::query()
            ->active()
            ->with(['department', 'jobGrade'])
            ->chunkById(200, function ($employees) use ($rule, &$created) {
                foreach ($employees as $employee) {
                    if ($rule->matches($employee) && $this->assignFromRule($employee, $rule)) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    /** Gán thủ công một khoá cho một nhân viên (spec 3.2.4). */
    public function assignManually(
        Employee $employee,
        Course $course,
        bool $mandatory = true,
        ?int $dueDays = null,
        ?int $assignedBy = null,
    ): Enrollment {
        return $this->createEnrollment($employee, $course, [
            'is_mandatory' => $mandatory,
            'due_date' => $dueDays ? now()->addDays($dueDays)->toDateString() : null,
            'assigned_by' => $assignedBy,
        ]);
    }

    /**
     * Lộ trình onboarding tự gán cho nhân viên mới (spec 4.4).
     * Chạy cả trong syncForEmployee nên nhân viên mới được gán ngay khi tạo tài khoản.
     */
    public function assignOnboardingCourses(Employee $employee): int
    {
        if (! $employee->is_new_hire) {
            return 0;
        }

        $created = 0;

        Course::query()->published()->onboarding()->each(function (Course $course) use ($employee, &$created) {
            $existed = Enrollment::where('course_id', $course->id)
                ->where('employee_id', $employee->id)
                ->exists();

            if (! $existed) {
                $this->createEnrollment($employee, $course, [
                    'is_mandatory' => true,
                    'due_date' => $course->duration_days
                        ? now()->addDays($course->duration_days)->toDateString()
                        : null,
                ]);
                $created++;
            }
        });

        return $created;
    }

    /** Gỡ gán một khoá — chỉ đánh dấu huỷ để giữ lại lịch sử đã học. */
    public function unassign(Enrollment $enrollment): void
    {
        $enrollment->update(['status' => Enrollment::STATUS_CANCELLED]);
    }

    private function assignFromRule(Employee $employee, CourseAssignmentRule $rule): bool
    {
        $existed = Enrollment::where('course_id', $rule->course_id)
            ->where('employee_id', $employee->id)
            ->exists();

        if ($existed) {
            return false;
        }

        $this->createEnrollment($employee, $rule->course, [
            'course_assignment_rule_id' => $rule->id,
            'is_mandatory' => $rule->is_mandatory,
            'due_date' => $rule->due_days ? now()->addDays($rule->due_days)->toDateString() : null,
        ]);

        return true;
    }

    /**
     * Tạo enrollment và khởi tạo sẵn tổng số bài học,
     * để màn hình danh sách hiển thị được "0/N bài" mà không phải truy vấn thêm.
     */
    private function createEnrollment(Employee $employee, Course $course, array $attributes = []): Enrollment
    {
        return DB::transaction(function () use ($employee, $course, $attributes) {
            $enrollment = Enrollment::firstOrCreate(
                ['course_id' => $course->id, 'employee_id' => $employee->id],
                array_merge([
                    'is_mandatory' => true,
                    'status' => Enrollment::STATUS_NOT_STARTED,
                    'assigned_at' => now(),
                    'total_lessons' => $course->requiredLessonCount(),
                ], $attributes)
            );

            // Enrollment đã bị huỷ trước đó thì kích hoạt lại thay vì tạo bản ghi trùng
            if ($enrollment->wasRecentlyCreated === false && $enrollment->status === Enrollment::STATUS_CANCELLED) {
                $enrollment->update(['status' => Enrollment::STATUS_NOT_STARTED]);
            }

            return $enrollment;
        });
    }
}
