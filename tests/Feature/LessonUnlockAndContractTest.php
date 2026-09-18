<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiểm chứng điều kiện mở khoá bài học tuần tự (spec 3.2.5)
 * và cảnh báo hợp đồng sắp hết hạn (spec 3.1.3).
 */
class LessonUnlockAndContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_hoc_tuan_tu_thi_bai_sau_bi_khoa_cho_den_khi_xong_bai_truoc(): void
    {
        $course = Course::factory()->create(['sequential' => true]);
        $first = Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 0]);
        $second = Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);
        $enrollment = $this->enroll($course);

        $this->assertTrue($first->isUnlockedFor($enrollment), 'Bài đầu tiên luôn mở');
        $this->assertFalse($second->isUnlockedFor($enrollment), 'Bài sau phải bị khoá');

        app(ProgressService::class)->completeLesson($enrollment, $first);

        $this->assertTrue($second->isUnlockedFor($enrollment), 'Xong bài trước thì bài sau mở');
    }

    public function test_hoc_tu_do_thi_moi_bai_deu_mo(): void
    {
        $course = Course::factory()->freeOrder()->create();
        Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 0]);
        $second = Lesson::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);
        $enrollment = $this->enroll($course);

        $this->assertTrue($second->isUnlockedFor($enrollment));
    }

    public function test_hop_dong_sap_het_han_lot_vao_danh_sach_canh_bao(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['code' => 'HD1', 'name' => 'Xác định thời hạn']);

        // Còn 10 ngày, ngưỡng cảnh báo mặc định 30 ngày => phải được cảnh báo
        $expiring = Contract::create([
            'contract_no' => 'HD-001',
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'effective_from' => now()->subYear(),
            'effective_to' => now()->addDays(10),
            'status' => Contract::STATUS_ACTIVE,
        ]);

        // Còn 200 ngày => chưa cảnh báo
        Contract::create([
            'contract_no' => 'HD-002',
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'effective_from' => now()->subMonth(),
            'effective_to' => now()->addDays(200),
            'status' => Contract::STATUS_ACTIVE,
        ]);

        // Không xác định thời hạn => không bao giờ cảnh báo
        Contract::create([
            'contract_no' => 'HD-003',
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'effective_from' => now()->subYear(),
            'effective_to' => null,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        $ids = Contract::needsExpiryAlert()->pluck('id')->all();

        $this->assertSame([$expiring->id], $ids);
    }

    public function test_nguong_canh_bao_rieng_cua_hop_dong_de_len_mac_dinh(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['code' => 'HD2', 'name' => 'Thử việc']);

        // Còn 50 ngày, vượt ngưỡng mặc định 30 nhưng hợp đồng tự đặt ngưỡng 60 => phải cảnh báo
        $contract = Contract::create([
            'contract_no' => 'HD-010',
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'effective_from' => now()->subMonths(2),
            'effective_to' => now()->addDays(50),
            'alert_before_days' => 60,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        $this->assertSame([$contract->id], Contract::needsExpiryAlert()->pluck('id')->all());
    }

    public function test_nhan_dien_hop_dong_da_het_han(): void
    {
        $employee = Employee::factory()->create();
        $type = ContractType::create(['code' => 'HD3', 'name' => 'Ngắn hạn']);

        $expired = Contract::create([
            'contract_no' => 'HD-020',
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'effective_from' => now()->subYear(),
            'effective_to' => now()->subDay(),
            'status' => Contract::STATUS_ACTIVE,
        ]);

        $this->assertTrue($expired->isExpired());
        $this->assertSame([$expired->id], Contract::expired()->pluck('id')->all());
    }

    private function enroll(Course $course): Enrollment
    {
        $employee = Employee::factory()->create();

        return Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => $course->requiredLessonCount(),
        ]);
    }
}
