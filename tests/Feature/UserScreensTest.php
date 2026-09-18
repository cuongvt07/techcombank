<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\User\DocumentBrowser;
use App\Livewire\User\LearningProgress;
use App\Livewire\User\MyCourses;
use App\Livewire\User\Profile;
use App\Livewire\User\SupportRequestForm;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Department;
use App\Models\DeviceSession;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentAccessRule;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\SupportContact;
use App\Models\SupportRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Kiểm chứng các màn hình site người dùng (spec 4.1, 4.2, 4.5). */
class UserScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->department = Department::factory()->create();
        $this->user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'password' => 'matkhaucu123',
        ]);
        $this->user->assignRole(RoleName::EMPLOYEE->value);
        $this->employee = Employee::factory()->create([
            'user_id' => $this->user->id,
            'department_id' => $this->department->id,
        ]);
        $this->actingAs($this->user->refresh());
    }

    // ---- 4.2 Tiến độ học tập ------------------------------------------------

    public function test_trang_tien_do_hien_khoa_hoc_cua_minh(): void
    {
        $course = Course::factory()->create(['title' => 'Khóa Học Của Tôi']);
        $this->enroll($course);

        Livewire::test(LearningProgress::class)->assertSee('Khóa Học Của Tôi');
    }

    public function test_khong_thay_khoa_hoc_cua_nguoi_khac(): void
    {
        $other = Employee::factory()->create();
        $course = Course::factory()->create(['title' => 'Khóa Của Người Khác']);

        Enrollment::create([
            'course_id' => $course->id,
            'employee_id' => $other->id,
            'status' => Enrollment::STATUS_IN_PROGRESS,
            'assigned_at' => now(),
            'total_lessons' => 1,
        ]);

        Livewire::test(LearningProgress::class)->assertDontSee('Khóa Của Người Khác');
    }

    public function test_nhac_viec_hien_khoa_bat_buoc_sap_den_han(): void
    {
        $course = Course::factory()->create(['title' => 'Khóa Sắp Đến Hạn']);
        $this->enroll($course, [
            'is_mandatory' => true,
            'due_date' => now()->addDays(3)->toDateString(),
            'status' => Enrollment::STATUS_IN_PROGRESS,
        ]);

        Livewire::test(LearningProgress::class)
            ->assertSee('Khóa Sắp Đến Hạn')
            ->assertSee('Cần hoàn thành');
    }

    public function test_khoa_da_hoan_thanh_khong_nam_trong_nhac_viec(): void
    {
        $course = Course::factory()->create(['title' => 'Khóa Đã Xong Rồi']);
        $this->enroll($course, [
            'is_mandatory' => true,
            'due_date' => now()->subDays(3)->toDateString(),
            'status' => Enrollment::STATUS_COMPLETED,
            'progress_percent' => 100,
        ]);

        // Vẫn hiện trong danh sách khóa học, nhưng không nằm ở mục nhắc việc
        $component = Livewire::test(LearningProgress::class);
        $this->assertSame(0, $component->get('dueSoon')?->count() ?? 0);
    }

    public function test_hien_chung_nhan_da_cap(): void
    {
        $course = Course::factory()->withCertificate()->create(['title' => 'Khóa Có Chứng Nhận']);
        $enrollment = $this->enroll($course, ['status' => Enrollment::STATUS_COMPLETED]);

        Certificate::create([
            'certificate_no' => 'CERT-TEST-001',
            'enrollment_id' => $enrollment->id,
            'employee_id' => $this->employee->id,
            'course_id' => $course->id,
            'issued_at' => now(),
            'verification_code' => 'abc123',
        ]);

        Livewire::test(LearningProgress::class)->assertSee('CERT-TEST-001');
    }

    // ---- 4.1 Danh mục tài liệu ----------------------------------------------

    public function test_chi_thay_tai_lieu_duoc_cap_quyen(): void
    {
        $allowed = Document::factory()->create(['title' => 'Tài Liệu Được Xem']);
        $hidden = Document::factory()->create(['title' => 'Tài Liệu Bị Ẩn']);

        DocumentAccessRule::create([
            'document_id' => $allowed->id,
            'department_id' => $this->department->id,
            'can_view' => true,
        ]);

        Livewire::test(DocumentBrowser::class)
            ->assertSee('Tài Liệu Được Xem')
            ->assertDontSee('Tài Liệu Bị Ẩn');
    }

    public function test_mo_tai_lieu_ghi_log_truy_cap(): void
    {
        $document = Document::factory()->create();
        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $this->department->id,
            'can_view' => true,
        ]);

        Livewire::test(DocumentBrowser::class)->call('view', $document->id);

        $this->assertDatabaseHas('document_access_logs', [
            'document_id' => $document->id,
            'employee_id' => $this->employee->id,
            'action' => DocumentAccessLog::ACTION_VIEW,
        ]);
    }

    public function test_mo_tai_lieu_khong_co_quyen_bi_chan_va_ghi_log_tu_choi(): void
    {
        $document = Document::factory()->create();

        $component = Livewire::test(DocumentBrowser::class)->call('view', $document->id);

        $this->assertFalse($component->get('showViewer'));
        // Ghi cả lượt bị từ chối để phát hiện dò tìm tài liệu (spec 3.3.2)
        $this->assertDatabaseHas('document_access_logs', [
            'document_id' => $document->id,
            'employee_id' => $this->employee->id,
            'action' => DocumentAccessLog::ACTION_DENIED,
        ]);
    }

    public function test_quyen_tai_xuong_phan_anh_dung_co_cua_tai_lieu(): void
    {
        $document = Document::factory()->create(['allow_download' => false]);
        DocumentAccessRule::create([
            'document_id' => $document->id,
            'department_id' => $this->department->id,
            'can_view' => true,
            'can_download' => true,
        ]);

        $component = Livewire::test(DocumentBrowser::class)->call('view', $document->id);

        $this->assertFalse($component->get('permission')['can_download']);
    }

    // ---- 4.5 Hỗ trợ ---------------------------------------------------------

    public function test_gui_yeu_cau_ho_tro(): void
    {
        SupportContact::create([
            'topic' => 'Hợp đồng & chế độ',
            'contact_name' => 'Phòng Nhân sự',
            'is_active' => true,
        ]);

        Livewire::test(SupportRequestForm::class)
            ->set('topic', 'Hợp đồng & chế độ')
            ->set('subject', 'Hỏi về phụ cấp')
            ->set('content', 'Tôi muốn hỏi về chế độ phụ cấp đi lại.')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('support_requests', [
            'employee_id' => $this->employee->id,
            'subject' => 'Hỏi về phụ cấp',
            'status' => SupportRequest::STATUS_NEW,
        ]);
    }

    public function test_yeu_cau_ho_tro_bat_buoc_nhap_du_thong_tin(): void
    {
        Livewire::test(SupportRequestForm::class)
            ->call('submit')
            ->assertHasErrors(['topic', 'subject', 'content']);
    }

    public function test_ma_phieu_khong_trung_nhau(): void
    {
        SupportContact::create(['topic' => 'Kỹ thuật', 'contact_name' => 'IT', 'is_active' => true]);

        foreach (range(1, 3) as $i) {
            Livewire::test(SupportRequestForm::class)
                ->set('topic', 'Kỹ thuật')
                ->set('subject', 'Yêu cầu ' . $i)
                ->set('content', 'Nội dung ' . $i)
                ->call('submit');
        }

        $tickets = SupportRequest::pluck('ticket_no');
        $this->assertCount(3, $tickets);
        $this->assertSame(3, $tickets->unique()->count());
    }

    public function test_chi_thay_yeu_cau_ho_tro_cua_minh(): void
    {
        $other = Employee::factory()->create();

        SupportRequest::create([
            'ticket_no' => 'HT-OTHER-001',
            'employee_id' => $other->id,
            'topic' => 'Khác',
            'subject' => 'Yêu Cầu Của Người Khác',
            'content' => 'x',
            'status' => SupportRequest::STATUS_NEW,
        ]);

        Livewire::test(SupportRequestForm::class)->assertDontSee('Yêu Cầu Của Người Khác');
    }

    public function test_dau_moi_ho_tro_gom_chung_va_rieng_phong_ban(): void
    {
        SupportContact::create([
            'topic' => 'Đầu Mối Chung',
            'department_id' => null,
            'contact_name' => 'HR',
            'is_active' => true,
        ]);
        SupportContact::create([
            'topic' => 'Đầu Mối Phòng Tôi',
            'department_id' => $this->department->id,
            'contact_name' => 'Trưởng phòng',
            'is_active' => true,
        ]);
        SupportContact::create([
            'topic' => 'Đầu Mối Phòng Khác',
            'department_id' => Department::factory()->create()->id,
            'contact_name' => 'Khác',
            'is_active' => true,
        ]);

        Livewire::test(SupportRequestForm::class)
            ->assertSee('Đầu Mối Chung')
            ->assertSee('Đầu Mối Phòng Tôi')
            ->assertDontSee('Đầu Mối Phòng Khác');
    }

    // ---- Trang cá nhân ------------------------------------------------------

    public function test_doi_mat_khau_thanh_cong(): void
    {
        Livewire::test(Profile::class)
            ->set('currentPassword', 'matkhaucu123')
            ->set('newPassword', 'matkhaumoi456')
            ->set('newPasswordConfirmation', 'matkhaumoi456')
            ->call('changePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('matkhaumoi456', $this->user->refresh()->password));
    }

    public function test_sai_mat_khau_hien_tai_thi_khong_doi_duoc(): void
    {
        Livewire::test(Profile::class)
            ->set('currentPassword', 'saibet')
            ->set('newPassword', 'matkhaumoi456')
            ->set('newPasswordConfirmation', 'matkhaumoi456')
            ->call('changePassword')
            ->assertHasErrors('currentPassword');

        $this->assertTrue(Hash::check('matkhaucu123', $this->user->refresh()->password));
    }

    public function test_xac_nhan_mat_khau_khong_khop_thi_bao_loi(): void
    {
        Livewire::test(Profile::class)
            ->set('currentPassword', 'matkhaucu123')
            ->set('newPassword', 'matkhaumoi456')
            ->set('newPasswordConfirmation', 'khacnhau789')
            ->call('changePassword')
            ->assertHasErrors('newPasswordConfirmation');
    }

    public function test_doi_mat_khau_go_co_bat_buoc_doi(): void
    {
        $this->user->update(['must_change_password' => true]);

        Livewire::test(Profile::class)
            ->set('currentPassword', 'matkhaucu123')
            ->set('newPassword', 'matkhaumoi456')
            ->set('newPasswordConfirmation', 'matkhaumoi456')
            ->call('changePassword');

        $this->assertFalse($this->user->refresh()->must_change_password);
    }

    public function test_tu_thu_hoi_phien_thiet_bi_cua_minh(): void
    {
        $session = DeviceSession::create([
            'user_id' => $this->user->id,
            'session_id' => 'my-session',
            'is_active' => true,
            'last_activity_at' => now(),
        ]);

        Livewire::test(Profile::class)->call('revokeSession', $session->id);

        $this->assertFalse($session->refresh()->is_active);
    }

    public function test_khong_thu_hoi_duoc_phien_cua_nguoi_khac(): void
    {
        $other = User::factory()->create();
        $session = DeviceSession::create([
            'user_id' => $other->id,
            'session_id' => 'other-session',
            'is_active' => true,
            'last_activity_at' => now(),
        ]);

        Livewire::test(Profile::class)->call('revokeSession', $session->id);

        $this->assertTrue((bool) $session->refresh()->is_active);
    }

    private function enroll(Course $course, array $attributes = []): Enrollment
    {
        return Enrollment::create(array_merge([
            'course_id' => $course->id,
            'employee_id' => $this->employee->id,
            'status' => Enrollment::STATUS_NOT_STARTED,
            'assigned_at' => now(),
            'total_lessons' => 1,
        ], $attributes));
    }

    // ---- 4.1 Tổng quan tiến độ trên trang khóa học --------------------------

    public function test_tien_do_chung_tinh_trung_binh_chu_khong_dem_khoa_da_xong(): void
    {
        // Hai khóa đều đang dở ở 66% — chưa khóa nào hoàn thành
        foreach ([66, 66] as $percent) {
            $this->enroll(
                Course::factory()->create(['status' => Course::STATUS_PUBLISHED]),
                [
                    'status' => Enrollment::STATUS_IN_PROGRESS,
                    'progress_percent' => $percent,
                ]
            );
        }

        $summary = Livewire::actingAs($this->user)
            ->test(MyCourses::class)
            ->viewData('summary');

        // Đếm theo khóa hoàn thành sẽ ra 0% và xóa sạch công sức đang dở
        $this->assertSame(66, $summary['overall_percent']);
        $this->assertSame(0, $summary['completed']);
        $this->assertSame(2, $summary['total']);
    }

    public function test_tien_do_chung_bang_khong_khi_chua_duoc_giao_khoa_nao(): void
    {
        $summary = Livewire::actingAs($this->user)
            ->test(MyCourses::class)
            ->viewData('summary');

        $this->assertSame(0, $summary['overall_percent']);
        $this->assertSame(0, $summary['total']);
    }
}
