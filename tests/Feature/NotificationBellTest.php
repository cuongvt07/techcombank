<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\Reports\SupportReport;
use App\Livewire\Admin\SupportManager;
use App\Livewire\User\EventFeed;
use App\Livewire\User\NotificationBell;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\LearningReminderService;
use App\Services\NotificationCenter;
use App\Services\Reports\QuizResultReportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chuông thông báo: đếm chưa đọc theo tab, đánh dấu đã đọc, lời nhắc học (cooldown 2 giờ),
 * thông báo tài khoản (phiếu hỗ trợ, cấp thêm lượt) ở cả site nhân viên lẫn quản trị.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Employee $employee;

    private Employee $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Tài khoản tạo 30 ngày trước: thông báo chung sau mốc này mới tính
        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'created_at' => now()->subDays(30)]);
        $this->user->assignRole(RoleName::EMPLOYEE->value);
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);

        $otherUser = User::factory()->create(['status' => User::STATUS_ACTIVE, 'created_at' => now()->subDays(30)]);
        $this->other = Employee::factory()->create(['user_id' => $otherUser->id]);

        // Quản trị viên KHÔNG có hồ sơ nhân viên — vẫn phải nhận được thông báo
        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole(RoleName::ADMIN->value);
    }

    private function notice(array $attributes = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Thông báo thử',
            'content' => 'Nội dung',
            'type' => Announcement::TYPE_GENERAL,
            'published_at' => now()->subHour(),
        ], $attributes));
    }

    private function counts(?User $user = null, string $site = NotificationCenter::SITE_USER): array
    {
        return app(NotificationCenter::class)->unreadCounts(($user ?? $this->user)->fresh(), $site);
    }

    private function unread(?Employee $employee = null): int
    {
        return Announcement::unreadCountFor(($employee ?? $this->employee)->fresh());
    }

    private function ticket(array $attrs = []): SupportRequest
    {
        static $seq = 0;
        $seq++;

        return SupportRequest::create(array_merge([
            'ticket_no' => 'YC-N' . $seq,
            'employee_id' => $this->employee->id,
            'topic' => 'Khác',
            'subject' => 'Không vào được khóa học',
            'content' => 'Chi tiết',
            'priority' => 'normal',
            'status' => SupportRequest::STATUS_NEW,
        ], $attrs));
    }

    // ---- Đếm chưa đọc ------------------------------------------------------------

    public function test_dem_chua_doc_gom_rieng_va_toan_cong_ty_bo_qua_khong_hop_le(): void
    {
        $this->notice(['title' => 'Riêng tôi', 'employee_id' => $this->employee->id]);
        $this->notice(['title' => 'Toàn công ty']);

        // Không được tính:
        $this->notice(['title' => 'Của người khác', 'employee_id' => $this->other->id]);
        $this->notice(['title' => 'Chưa phát hành', 'published_at' => null]);
        $this->notice(['title' => 'Hẹn giờ', 'published_at' => now()->addDay()]);
        $this->notice(['title' => 'Hết hạn', 'expires_at' => now()->subMinute()]);
        $this->notice(['title' => 'Chung cũ hơn tài khoản', 'published_at' => now()->subDays(60)]);

        $this->assertSame(2, $this->unread());
        // Thông báo riêng cũ hơn tài khoản vẫn tính (gửi đích danh)
        $this->notice(['title' => 'Riêng cũ', 'employee_id' => $this->employee->id, 'published_at' => now()->subDays(60)]);
        $this->assertSame(3, $this->unread());
        $this->assertSame(2, $this->unread($this->other));
    }

    public function test_tab_dem_dung_theo_loai(): void
    {
        $course = Course::factory()->create();
        // Học tập: lời nhắc riêng loại khóa học + thông báo cấp lượt
        $this->notice(['type' => Announcement::TYPE_COURSE, 'employee_id' => $this->employee->id, 'course_id' => $course->id]);
        app(NotificationCenter::class)->attemptsGranted(Quiz::factory()->create(['course_id' => $course->id]), $this->employee, 1);
        // Hỗ trợ: phiếu của tôi được tiếp nhận
        app(NotificationCenter::class)->supportAccepted($this->ticket());
        // Sự kiện: thông báo chung + sự kiện riêng không phải nhắc học
        $this->notice();
        $this->notice(['employee_id' => $this->employee->id]);
        $this->notice(['type' => Announcement::TYPE_COURSE]); // khóa học nhưng toàn công ty → Sự kiện

        $this->assertSame(['all' => 6, 'learning' => 2, 'support' => 1, 'events' => 3], $this->counts());

        $bell = Livewire::actingAs($this->user)->test(NotificationBell::class)
            ->assertSee('6 chưa đọc')
            ->call('setTab', 'support')
            ->assertSee('đã được tiếp nhận xử lý')
            ->assertDontSee('cấp thêm');

        // "Đánh dấu đã đọc tất cả" chỉ áp cho tab đang mở
        $bell->call('markAllRead');
        $this->assertSame(['all' => 5, 'learning' => 2, 'support' => 0, 'events' => 3], $this->counts());

        $bell->call('setTab', 'learning')->call('markAllRead');
        $this->assertSame(['all' => 3, 'learning' => 0, 'support' => 0, 'events' => 3], $this->counts());

        $bell->call('setTab', 'all')->call('markAllRead')->assertSee('Bạn đã xem hết');
        $this->assertSame(0, $this->counts()['all']);
    }

    public function test_chuong_bam_mot_cai_giam_mot_va_danh_dau_tat_ca(): void
    {
        $general = $this->notice(['title' => 'Hội nghị toàn hàng']);
        $this->notice(['title' => 'Phỏng vấn đánh giá', 'employee_id' => $this->employee->id]);
        $this->notice(['title' => 'Lịch nghỉ lễ']);

        $bell = Livewire::actingAs($this->user)->test(NotificationBell::class)
            ->assertSee('Hội nghị toàn hàng')
            ->assertSee('3 chưa đọc');

        $bell->call('open', 'a:' . $general->id)
            ->assertRedirect(route('learn.events', ['su-kien' => $general->id]));
        $this->assertSame(2, $this->unread());

        // Bấm lại cái đã đọc không làm hỏng số
        $bell->call('open', 'a:' . $general->id);
        $this->assertSame(2, $this->unread());

        $bell->call('markAllRead')->assertSee('Bạn đã xem hết');
        $this->assertSame(0, $this->unread());
        $this->assertSame(3, AnnouncementRead::where('employee_id', $this->employee->id)->count());
    }

    public function test_khong_mo_duoc_thong_bao_cua_nguoi_khac(): void
    {
        $foreign = $this->notice(['employee_id' => $this->other->id]);
        app(NotificationCenter::class)->supportAccepted($this->ticket(['employee_id' => $this->other->id]));
        $foreignNotification = $this->other->user->notifications()->sole();

        Livewire::actingAs($this->user)->test(NotificationBell::class)
            ->call('open', 'a:' . $foreign->id)
            ->assertNoRedirect()
            ->call('open', 'n:' . $foreignNotification->id)
            ->assertNoRedirect();

        $this->assertSame(0, AnnouncementRead::count());
        $this->assertNull($foreignNotification->fresh()->read_at);
    }

    public function test_badge_hien_9_cong_khi_qua_9(): void
    {
        foreach (range(1, 11) as $i) {
            $this->notice(['title' => "Thông báo {$i}"]);
        }

        Livewire::actingAs($this->user)->test(NotificationBell::class)
            ->assertSeeHtml('9+')
            ->assertSee('11 chưa đọc');
    }

    // ---- Lời nhắc học ---------------------------------------------------------------

    public function test_gui_nhac_hoc_tang_badge_dung_nhan_vien_va_dan_toi_khoa(): void
    {
        $course = Course::factory()->create(['title' => 'An toàn thông tin']);
        $enrollment = Enrollment::create([
            'course_id' => $course->id, 'employee_id' => $this->employee->id,
            'status' => Enrollment::STATUS_IN_PROGRESS, 'progress_percent' => 40,
            'assigned_at' => now(), 'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->assertSame(0, $this->unread());
        app(LearningReminderService::class)->remindEnrollments([$enrollment]);

        $this->assertSame(1, $this->counts()['learning']);
        $this->assertSame(0, $this->unread($this->other), 'Nhân viên khác không thấy lời nhắc');

        $reminder = Announcement::where('employee_id', $this->employee->id)->sole();
        $this->assertSame($course->id, $reminder->course_id);

        Livewire::actingAs($this->user)->test(NotificationBell::class)
            ->assertSee('Nhắc hoàn thành khóa học')
            ->call('open', 'a:' . $reminder->id)
            ->assertRedirect(route('learn.course', $course));

        $this->assertSame(0, $this->unread());
    }

    public function test_nhac_lam_lai_gan_khoa_va_chan_lap_trong_2_gio(): void
    {
        $course = Course::factory()->create();
        $quiz = Quiz::create(['course_id' => $course->id, 'title' => 'Kiểm tra cuối khóa', 'pass_score' => 80]);
        $service = app(LearningReminderService::class);

        $this->assertSame(['sent' => 1, 'skipped' => 0], $service->remindRetake($quiz, [$this->employee->id]));
        $this->assertSame(1, $this->counts()['learning']);
        $this->assertSame(0, $this->unread($this->other));
        $notice = Announcement::where('employee_id', $this->employee->id)->sole();
        $this->assertSame([$course->id, $quiz->id], [$notice->course_id, $notice->quiz_id]);

        // Cùng bài trong 2 giờ → bỏ qua; người khác vẫn nhắc được
        $this->assertSame(['sent' => 1, 'skipped' => 1], $service->remindRetake($quiz, [$this->employee->id, $this->other->id]));
        $this->travel(119)->minutes();
        $this->assertSame(['sent' => 0, 'skipped' => 1], $service->remindRetake($quiz, [$this->employee->id]));
        $this->travel(2)->minutes();
        $this->assertSame(['sent' => 1, 'skipped' => 0], $service->remindRetake($quiz, [$this->employee->id]));
    }

    public function test_nhac_hoc_cooldown_2_gio(): void
    {
        $enrollment = Enrollment::create([
            'course_id' => Course::factory()->create()->id, 'employee_id' => $this->employee->id,
            'status' => Enrollment::STATUS_IN_PROGRESS, 'assigned_at' => now(),
        ]);
        $service = app(LearningReminderService::class);

        $this->assertSame(2, LearningReminderService::COOLDOWN_HOURS);
        $service->remindEnrollments([$enrollment]);
        $this->travel(119)->minutes();
        $this->assertFalse($service->canRemind($enrollment->fresh()));
        $this->travel(2)->minutes();
        $this->assertTrue($service->canRemind($enrollment->fresh()));
    }

    // ---- Phiếu hỗ trợ ---------------------------------------------------------------

    public function test_admin_nhan_thong_bao_phan_cong_va_nhan_vien_nhan_thong_bao_tiep_nhan_giai_quyet(): void
    {
        $agent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $agent->givePermissionTo(['support.view', 'support.update']);
        $ticket = $this->ticket(['ticket_no' => 'YC-0099']);

        // Admin giao phiếu cho agent
        Livewire::actingAs($this->admin)->test(SupportManager::class)
            ->call('view', $ticket->id)
            ->set('assigned_to', $agent->id)
            ->call('assign');

        $this->assertSame(['all' => 1, 'learning' => 0, 'support' => 1, 'events' => 0], $this->counts($agent, NotificationCenter::SITE_ADMIN));
        $this->assertSame(0, $this->counts($this->admin, NotificationCenter::SITE_ADMIN)['all'], 'Người giao không tự báo mình');
        $this->assertSame(1, $this->counts()['support'], 'Nhân viên gửi phiếu được báo đã tiếp nhận');

        // Chuông quản trị: bấm → màn Phiếu hỗ trợ lọc đúng phiếu
        $key = 'n:' . $agent->notifications()->sole()->id;
        Livewire::actingAs($agent)->test(NotificationBell::class, ['site' => 'admin'])
            ->assertSee('Bạn được giao phụ trách phiếu YC-0099')
            ->call('open', $key)
            ->assertRedirect(route('admin.support', ['q' => 'YC-0099', 'statusFilter' => '']));
        $this->assertSame(0, $this->counts($agent, NotificationCenter::SITE_ADMIN)['all']);

        // Giải quyết → nhân viên nhận thêm thông báo
        Livewire::actingAs($agent)->test(SupportManager::class)
            ->call('view', $ticket->id)
            ->set('resolution', 'Đã cấp lại quyền truy cập khóa học.')
            ->call('resolve');

        $this->assertSame(2, $this->counts()['support']);
        Livewire::actingAs($this->user)->test(NotificationBell::class)
            ->call('setTab', 'support')
            ->assertSee('Phiếu YC-0099 đã được giải quyết');
    }

    public function test_tu_nhan_phieu_khong_bao_chinh_minh_nhung_bao_nhan_vien(): void
    {
        $ticket = $this->ticket();

        Livewire::actingAs($this->admin)->test(SupportManager::class)->call('take', $ticket->id);

        $this->assertSame(0, $this->admin->notifications()->count());
        $this->assertSame(1, $this->counts()['support']);
    }

    public function test_phan_cong_hang_loat_chi_bao_phieu_thuc_su_duoc_giao(): void
    {
        $agent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $agent->givePermissionTo(['support.update']);
        $holder = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $fresh = $this->ticket(['ticket_no' => 'YC-0101']);
        $held = $this->ticket(['ticket_no' => 'YC-0102', 'status' => SupportRequest::STATUS_IN_PROGRESS, 'assigned_to' => $holder->id]);

        Livewire::actingAs($this->admin)->test(SupportReport::class)
            ->set('selected', [(string) $fresh->id, (string) $held->id])
            ->set('assignTo', (string) $agent->id)
            ->call('bulkAssign')
            ->assertHasNoErrors();

        $titles = $agent->notifications()->get()->pluck('data.title')->all();
        $this->assertSame(['Bạn được giao phụ trách phiếu YC-0101'], $titles);
        $this->assertSame(0, $holder->notifications()->count());
    }

    // ---- Cấp thêm lượt ---------------------------------------------------------------

    public function test_cap_them_luot_bao_nhan_vien_tab_hoc_tap(): void
    {
        $course = Course::factory()->create();
        $quiz = Quiz::factory()->create(['course_id' => $course->id, 'title' => 'Nhận diện lừa đảo', 'pass_score' => 70, 'max_attempts' => 1]);
        QuizAttempt::create([
            'quiz_id' => $quiz->id, 'employee_id' => $this->employee->id, 'attempt_no' => 1,
            'status' => QuizAttempt::STATUS_GRADED, 'score' => 40, 'max_score' => 100, 'percentage' => 40,
            'is_passed' => false, 'started_at' => now()->subDay(), 'submitted_at' => now()->subDay(),
        ]);

        $res = app(QuizResultReportService::class)->grantExtraAttempts([[$this->employee->id, $quiz->id]], $this->admin->id);
        $this->assertSame(1, $res['granted']);

        $this->assertSame(1, $this->counts()['learning']);
        $notification = $this->user->notifications()->sole();
        $this->assertSame('Bạn được cấp thêm 1 lượt làm bài: Nhận diện lừa đảo', $notification->data['title']);

        Livewire::actingAs($this->user)->test(NotificationBell::class)
            ->call('open', 'n:' . $notification->id)
            ->assertRedirect(route('learn.course', $course, false));
    }

    // ---- Trang Sự kiện ----------------------------------------------------------------

    public function test_trang_su_kien_mo_tu_chuong_hoac_bam_vao_thi_danh_dau_da_doc(): void
    {
        $focused = $this->notice(['title' => 'Mở từ chuông']);
        $other = $this->notice(['title' => 'Bấm trên trang']);

        Livewire::actingAs($this->user)
            ->withQueryParams(['su-kien' => $focused->id])
            ->test(EventFeed::class)
            ->assertSee('Mở từ chuông');
        $this->assertSame(1, $this->unread());

        Livewire::actingAs($this->user)->test(EventFeed::class)
            ->call('markRead', $other->id)
            ->assertDispatched('notifications-updated');
        $this->assertSame(0, $this->unread());
    }

    public function test_tai_khoan_khong_co_ho_so_nhan_vien_van_dung_duoc_chuong(): void
    {
        $this->notice(['title' => 'Chỉ cho nhân viên']);

        Livewire::actingAs($this->admin)->test(NotificationBell::class)
            ->assertOk()
            ->assertDontSee('Chỉ cho nhân viên')
            ->assertSee('Bạn đã xem hết');
    }
}
