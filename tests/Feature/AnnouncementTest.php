<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\AnnouncementManager;
use App\Livewire\User\EventFeed;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Sự kiện & thông báo: cấu hình bên admin, hiển thị bên site người dùng. */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $this->admin->id]);

        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->user->assignRole(RoleName::EMPLOYEE->value);
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    }

    private function makeEvent(array $attributes = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Sự kiện thử',
            'content' => 'Nội dung',
            'type' => Announcement::TYPE_GENERAL,
            'published_at' => now()->subHour(),
        ], $attributes));
    }

    // ---- Admin: cấu hình -----------------------------------------------------

    public function test_admin_tao_duoc_su_kien_toan_cong_ty(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AnnouncementManager::class)
            ->call('create')
            ->set('title', 'Hội nghị toàn hàng')
            ->set('content', 'Mời toàn thể nhân viên tham dự.')
            ->set('scope', 'all')
            ->set('starts_at', now()->addDays(3)->format('Y-m-d\TH:i'))
            ->set('location', 'Hội trường tầng 12')
            ->call('save')
            ->assertHasNoErrors();

        $event = Announcement::where('title', 'Hội nghị toàn hàng')->first();

        $this->assertNotNull($event);
        $this->assertNull($event->employee_id, 'Sự kiện toàn công ty không được gán cho ai');
        $this->assertSame('Hội trường tầng 12', $event->location);
    }

    public function test_admin_gan_duoc_su_kien_cho_mot_nhan_vien(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AnnouncementManager::class)
            ->call('create')
            ->set('title', 'Phỏng vấn đánh giá')
            ->set('content', 'Mời bạn dự buổi đánh giá.')
            ->set('scope', 'personal')
            ->set('employee_id', $this->employee->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            $this->employee->id,
            Announcement::where('title', 'Phỏng vấn đánh giá')->first()->employee_id
        );
    }

    public function test_gan_rieng_ma_khong_chon_nhan_vien_thi_bao_loi(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AnnouncementManager::class)
            ->call('create')
            ->set('title', 'Thiếu người nhận')
            ->set('content', 'Nội dung')
            ->set('scope', 'personal')
            ->set('employee_id', null)
            ->call('save')
            ->assertHasErrors(['employee_id']);
    }

    public function test_chuyen_ve_toan_cong_ty_thi_xoa_nhan_vien_da_chon(): void
    {
        // Chọn nhân viên rồi đổi ý sang toàn công ty: không được lưu sót employee_id
        Livewire::actingAs($this->admin)
            ->test(AnnouncementManager::class)
            ->call('create')
            ->set('scope', 'personal')
            ->set('employee_id', $this->employee->id)
            ->set('scope', 'all')
            ->assertSet('employee_id', null);
    }

    public function test_thoi_gian_ket_thuc_phai_sau_thoi_gian_bat_dau(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AnnouncementManager::class)
            ->call('create')
            ->set('title', 'Sai thời gian')
            ->set('content', 'Nội dung')
            ->set('starts_at', now()->addDays(3)->format('Y-m-d\TH:i'))
            ->set('ends_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasErrors(['ends_at']);
    }

    public function test_phat_hanh_va_go_su_kien(): void
    {
        $event = $this->makeEvent(['published_at' => null]);

        Livewire::actingAs($this->admin)
            ->test(AnnouncementManager::class)
            ->call('publish', $event->id);

        $this->assertNotNull($event->refresh()->published_at);

        Livewire::actingAs($this->admin)
            ->test(AnnouncementManager::class)
            ->call('unpublish', $event->id);

        $this->assertNull($event->refresh()->published_at);
    }

    // ---- Site người dùng: hiển thị ------------------------------------------

    public function test_su_kien_rieng_luon_nam_tren_dau(): void
    {
        // Sự kiện toàn công ty diễn ra sớm hơn — nếu chỉ sắp theo thời gian thì
        // nó sẽ lên đầu, che mất việc nhân viên cần làm
        $this->makeEvent([
            'title' => 'Toàn công ty diễn ra sớm',
            'starts_at' => now()->addDay(),
        ]);

        $this->makeEvent([
            'title' => 'Riêng tôi diễn ra muộn',
            'employee_id' => $this->employee->id,
            'starts_at' => now()->addDays(10),
        ]);

        $events = Livewire::actingAs($this->user)
            ->test(EventFeed::class)
            ->viewData('events');

        $this->assertSame('Riêng tôi diễn ra muộn', $events->first()->title);
    }

    public function test_khong_thay_su_kien_gan_cho_nguoi_khac(): void
    {
        $other = Employee::factory()->create();

        $this->makeEvent(['title' => 'Của người khác', 'employee_id' => $other->id]);
        $this->makeEvent(['title' => 'Của tôi', 'employee_id' => $this->employee->id]);
        $this->makeEvent(['title' => 'Toàn công ty']);

        $titles = Livewire::actingAs($this->user)
            ->test(EventFeed::class)
            ->viewData('events')
            ->pluck('title');

        $this->assertContains('Của tôi', $titles);
        $this->assertContains('Toàn công ty', $titles);
        $this->assertNotContains('Của người khác', $titles);
    }

    public function test_su_kien_chua_phat_hanh_khong_hien_ra(): void
    {
        $this->makeEvent(['title' => 'Còn nháp', 'published_at' => null]);
        $this->makeEvent(['title' => 'Đã phát hành']);

        $titles = Livewire::actingAs($this->user)
            ->test(EventFeed::class)
            ->viewData('events')
            ->pluck('title');

        $this->assertNotContains('Còn nháp', $titles);
        $this->assertContains('Đã phát hành', $titles);
    }

    public function test_su_kien_het_hieu_luc_khong_hien_ra(): void
    {
        $this->makeEvent([
            'title' => 'Đã hết hiệu lực',
            'published_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
        ]);

        $titles = Livewire::actingAs($this->user)
            ->test(EventFeed::class)
            ->viewData('events')
            ->pluck('title');

        $this->assertNotContains('Đã hết hiệu lực', $titles);
    }

    public function test_su_kien_dang_dien_ra_xep_tren_su_kien_khong_co_moc_thoi_gian(): void
    {
        // Sự kiện đang chạy là thứ nhân viên cần biết ngay; nếu gộp chung với
        // "đã bắt đầu" thì nó bị đẩy xuống dưới cả thông báo không có ngày giờ
        $this->makeEvent(['title' => 'Thông báo chung', 'starts_at' => null]);
        $this->makeEvent([
            'title' => 'Đang diễn ra',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(2),
        ]);

        $events = Livewire::actingAs($this->user)
            ->test(EventFeed::class)
            ->viewData('events');

        $this->assertSame('Đang diễn ra', $events->first()->title);
    }

    public function test_thu_tu_day_du_cua_danh_sach_su_kien(): void
    {
        $this->makeEvent(['title' => '5. Đã kết thúc', 'starts_at' => now()->subDays(9)]);
        $this->makeEvent(['title' => '4. Không có ngày giờ', 'starts_at' => null]);
        $this->makeEvent(['title' => '3. Sắp diễn ra', 'starts_at' => now()->addDays(5)]);
        $this->makeEvent([
            'title' => '2. Đang diễn ra',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
        ]);
        $this->makeEvent([
            'title' => '1. Riêng tôi',
            'employee_id' => $this->employee->id,
            'starts_at' => now()->addDays(20),
        ]);

        $titles = Livewire::actingAs($this->user)
            ->test(EventFeed::class)
            ->viewData('events')
            ->pluck('title')
            ->all();

        $this->assertSame([
            '1. Riêng tôi',
            '2. Đang diễn ra',
            '3. Sắp diễn ra',
            '4. Không có ngày giờ',
            '5. Đã kết thúc',
        ], $titles);
    }

    public function test_su_kien_sap_dien_ra_xep_truoc_su_kien_da_qua(): void
    {
        $this->makeEvent(['title' => 'Đã qua', 'starts_at' => now()->subDays(5)]);
        $this->makeEvent(['title' => 'Sắp diễn ra', 'starts_at' => now()->addDays(2)]);

        $events = Livewire::actingAs($this->user)
            ->test(EventFeed::class)
            ->viewData('events');

        $this->assertSame('Sắp diễn ra', $events->first()->title);
    }

    public function test_loc_chi_xem_su_kien_rieng(): void
    {
        $this->makeEvent(['title' => 'Toàn công ty']);
        $this->makeEvent(['title' => 'Của tôi', 'employee_id' => $this->employee->id]);

        $titles = Livewire::actingAs($this->user)
            ->test(EventFeed::class)
            ->call('setFilter', 'personal')
            ->viewData('events')
            ->pluck('title');

        $this->assertContains('Của tôi', $titles);
        $this->assertNotContains('Toàn công ty', $titles);
    }

    public function test_dem_dung_so_su_kien_rieng(): void
    {
        $other = Employee::factory()->create();

        $this->makeEvent(['employee_id' => $this->employee->id]);
        $this->makeEvent(['employee_id' => $this->employee->id]);
        $this->makeEvent(['employee_id' => $other->id]);
        $this->makeEvent();

        $count = Livewire::actingAs($this->user)
            ->test(EventFeed::class)
            ->viewData('personalCount');

        $this->assertSame(2, $count);
    }

    // ---- Điều hướng ---------------------------------------------------------

    public function test_nhan_vien_dang_nhap_vao_thang_trang_su_kien(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertRedirect(route('learn.events'));
    }

    public function test_nhan_vien_vao_duoc_trang_su_kien(): void
    {
        $this->actingAs($this->user)
            ->get(route('learn.events'))
            ->assertOk();
    }

    public function test_nhan_vien_khong_vao_duoc_man_hinh_quan_ly_su_kien(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.announcements'))
            ->assertRedirect(route('learn.events'));
    }

    public function test_admin_vao_duoc_man_hinh_quan_ly_su_kien(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.announcements'))
            ->assertOk();
    }
}
