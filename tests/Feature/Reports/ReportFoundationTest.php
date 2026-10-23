<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleName;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptGrant;
use App\Models\SecurityAlert;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\LearningReminderService;
use App\Services\SecurityMonitorService;
use App\Support\Reports\ReportPeriod;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phần nền dùng chung của các trang Báo cáo: kỳ theo giờ VN, SLA, mốc xử lý cảnh báo, lượt cấp thêm, gửi nhắc. */
class ReportFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- Kỳ báo cáo -----------------------------------------------------------

    public function test_ky_bao_cao_tinh_theo_ngay_viet_nam(): void
    {
        // 23:30 UTC ngày 20/10 = 06:30 sáng 21/10 giờ VN → "hôm nay" là 21/10
        Carbon::setTestNow('2026-10-20 23:30:00');

        $today = ReportPeriod::make('today');
        $this->assertSame('21/10/2026 – 21/10/2026', $today->label());
        // Đầu ngày 21/10 giờ VN = 17:00 UTC ngày 20/10
        $this->assertSame('2026-10-20 17:00:00', $today->from()->format('Y-m-d H:i:s'));

        $month = ReportPeriod::make('30d');
        $this->assertSame(30, $month->days());
        $this->assertSame('22/09/2026 – 21/10/2026', $month->label());
        $this->assertSame('23/08/2026 – 21/09/2026', $month->previous()->label());
    }

    public function test_ky_tuy_chon_bi_gioi_han_do_dai(): void
    {
        $period = ReportPeriod::make('custom', '2026-01-01', '2026-10-21', maxDays: 92);

        $this->assertSame(92, $period->days());
        $this->assertSame('21/10/2026', $period->endLocal->format('d/m/Y'));
    }

    public function test_ky_tuy_chon_dao_nguoc_ngay_va_ngay_sai(): void
    {
        $this->assertSame('01/10/2026 – 10/10/2026', ReportPeriod::make('custom', '2026-10-10', '2026-10-01')->label());
        // Ngày sai định dạng → về mặc định 30 ngày, không lỗi
        $this->assertSame(30, ReportPeriod::make('custom', 'abc', 'xyz')->days());
    }

    public function test_gom_theo_ngay_viet_nam_trong_sql(): void
    {
        $this->assertSame('DATE(DATE_ADD(completed_at, INTERVAL 7 HOUR))', ReportPeriod::localDate('completed_at'));
    }

    // ---- SLA phiếu hỗ trợ ------------------------------------------------------

    public function test_phieu_ho_tro_tu_dat_han_sla_theo_uu_tien(): void
    {
        Carbon::setTestNow('2026-10-21 08:00:00');
        $employee = Employee::factory()->create();

        $make = fn (string $priority) => SupportRequest::create([
            'ticket_no' => 'YC-' . $priority, 'employee_id' => $employee->id, 'topic' => 'Khác',
            'subject' => 'Thử', 'content' => 'Nội dung', 'priority' => $priority,
            'status' => SupportRequest::STATUS_NEW,
        ]);

        $this->assertSame('2026-10-21 16:00', $make('high')->sla_due_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-22 08:00', $make('normal')->sla_due_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-24 08:00', $make('low')->sla_due_at->format('Y-m-d H:i'));

        Carbon::setTestNow('2026-10-21 17:00:00');
        $this->assertSame(1, SupportRequest::overSla()->count());
        $this->assertTrue(SupportRequest::where('priority', 'high')->first()->isOverSla());
    }

    // ---- Cảnh báo bảo mật: mốc ghi nhận / đóng --------------------------------

    public function test_ghi_nhan_roi_dong_giu_rieng_hai_moc(): void
    {
        $service = app(SecurityMonitorService::class);
        $alert = SecurityAlert::create([
            'type' => SecurityAlert::TYPE_UNUSUAL_IP, 'severity' => 'medium',
            'title' => 'IP lạ', 'status' => SecurityAlert::STATUS_OPEN,
        ]);

        Carbon::setTestNow('2026-10-21 09:00:00');
        $service->acknowledge($alert);
        Carbon::setTestNow('2026-10-21 15:00:00');
        $service->resolve($alert->fresh());

        $alert->refresh();
        // Lần đóng không ghi đè mốc ghi nhận — MTTA và MTTR tách được
        $this->assertSame('09:00', $alert->acknowledged_at->format('H:i'));
        $this->assertSame('15:00', $alert->resolved_at->format('H:i'));
    }

    public function test_dong_thang_khi_chua_ghi_nhan(): void
    {
        $alert = SecurityAlert::create([
            'type' => SecurityAlert::TYPE_BRUTE_FORCE, 'severity' => 'high',
            'title' => 'Sai mật khẩu', 'status' => SecurityAlert::STATUS_OPEN,
        ]);

        app(SecurityMonitorService::class)->resolve($alert, falsePositive: true);

        $alert->refresh();
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertNotNull($alert->resolved_at);
    }

    // ---- Lượt làm bài cấp thêm -------------------------------------------------

    public function test_cap_them_luot_cho_lam_tiep(): void
    {
        $quiz = Quiz::factory()->create(['max_attempts' => 2]);
        $employee = Employee::factory()->create();

        foreach ([1, 2] as $no) {
            QuizAttempt::create([
                'quiz_id' => $quiz->id, 'employee_id' => $employee->id, 'attempt_no' => $no,
                'status' => QuizAttempt::STATUS_GRADED, 'started_at' => now(), 'is_passed' => false,
            ]);
        }

        $this->assertFalse($quiz->canAttempt($employee));

        QuizAttemptGrant::create(['quiz_id' => $quiz->id, 'employee_id' => $employee->id, 'extra_attempts' => 1]);

        $this->assertSame(3, $quiz->allowedAttemptsFor($employee));
        $this->assertTrue($quiz->fresh()->canAttempt($employee));
        // Lượt cấp cho người khác không ảnh hưởng
        $this->assertSame(2, $quiz->allowedAttemptsFor(Employee::factory()->create()));
    }

    // ---- Gửi nhắc học ----------------------------------------------------------

    public function test_gui_nhac_tao_thong_bao_rieng_va_chan_nhac_lai_trong_12_gio(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::ADMIN->value);
        $employee = Employee::factory()->create();
        $course = Course::factory()->create(['title' => 'An toàn thông tin']);

        $enrollment = Enrollment::create([
            'course_id' => $course->id, 'employee_id' => $employee->id,
            'status' => Enrollment::STATUS_IN_PROGRESS, 'progress_percent' => 40,
            'assigned_at' => now(), 'due_date' => now()->subDays(3)->toDateString(),
        ]);

        $service = app(LearningReminderService::class);

        $this->assertSame(['sent' => 1, 'skipped' => 0], $service->remindEnrollments([$enrollment], $admin->id));

        $notice = Announcement::where('employee_id', $employee->id)->sole();
        $this->assertStringContainsString('An toàn thông tin', $notice->title);
        $this->assertStringContainsString('quá hạn', $notice->content);
        $this->assertNotNull($notice->published_at);
        // Gắn khóa để chuông thông báo dẫn thẳng tới khóa đó
        $this->assertSame($course->id, $notice->course_id);
        $this->assertNotNull($enrollment->fresh()->last_reminded_at);

        // Nhắc lại ngay → bỏ qua (chống spam)
        $this->assertSame(['sent' => 0, 'skipped' => 1], $service->remindEnrollments([$enrollment->fresh()], $admin->id));

        // Lượt đã hoàn thành thì không nhắc
        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED, 'last_reminded_at' => null]);
        $this->assertFalse($service->canRemind($enrollment->fresh()));
    }
}
