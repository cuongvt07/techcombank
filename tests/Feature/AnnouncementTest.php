<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\Employee;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * "Sự kiện & thông báo" đã bỏ ở cả hai site (fb6). Bảng announcements và model
 * còn giữ cho lời nhắc học gửi riêng nhân viên — kiểm phần còn dùng được.
 */
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

    private function makeNotice(array $attributes = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Thông báo thử',
            'content' => 'Nội dung',
            'type' => Announcement::TYPE_GENERAL,
            'published_at' => now()->subHour(),
        ], $attributes));
    }

    // ---- Màn hình đã bỏ ----------------------------------------------------------

    public function test_khong_con_route_su_kien_o_hai_site(): void
    {
        $this->assertFalse(Route::has('learn.events'));
        $this->assertFalse(Route::has('admin.announcements'));

        $this->actingAs($this->user)->get('/hoc-tap/su-kien')->assertNotFound();
        $this->actingAs($this->admin)->get('/quan-tri/su-kien')->assertNotFound();
    }

    public function test_khong_con_quyen_su_kien_trong_danh_muc(): void
    {
        $this->assertEmpty(array_filter(
            PermissionCatalog::all(),
            fn ($p) => str_starts_with($p, 'announcements.')
        ));
        $this->assertArrayNotHasKey('admin.announcements', PermissionCatalog::ROUTE_MODULES);
    }

    public function test_nhan_vien_dang_nhap_vao_thang_khoa_hoc_cua_toi(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertRedirect(route('learn.courses'));
    }

    public function test_menu_hai_site_khong_con_muc_su_kien(): void
    {
        $this->actingAs($this->user)
            ->get(route('learn.courses'))
            ->assertOk()
            ->assertDontSee('/hoc-tap/su-kien', false);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Sự kiện &amp; thông báo', false)
            ->assertDontSee('/quan-tri/su-kien', false);
    }

    // ---- Model còn dùng cho lời nhắc học ------------------------------------------

    public function test_loi_nhac_khoa_hoc_dan_toi_khoa_con_lai_ve_khoa_hoc_cua_toi(): void
    {
        $course = Course::factory()->create();

        $reminder = $this->makeNotice([
            'type' => Announcement::TYPE_COURSE,
            'employee_id' => $this->employee->id,
            'course_id' => $course->id,
        ]);
        $this->assertTrue($reminder->isLearningReminder());
        $this->assertSame(route('learn.course', $course), $reminder->fresh()->targetUrl());

        $general = $this->makeNotice();
        $this->assertFalse($general->isLearningReminder());
        $this->assertSame(route('learn.courses'), $general->targetUrl());
    }

    public function test_pham_vi_nhan_vien_chi_gom_cua_minh_va_toan_cong_ty(): void
    {
        $other = Employee::factory()->create();

        $this->makeNotice(['title' => 'Của người khác', 'employee_id' => $other->id]);
        $this->makeNotice(['title' => 'Của tôi', 'employee_id' => $this->employee->id]);
        $this->makeNotice(['title' => 'Toàn công ty']);
        $this->makeNotice(['title' => 'Còn nháp', 'published_at' => null]);
        $this->makeNotice(['title' => 'Hết hiệu lực', 'expires_at' => now()->subDay()]);

        $titles = Announcement::query()->visible()->forEmployee($this->employee)->pluck('title');

        $this->assertContains('Của tôi', $titles);
        $this->assertContains('Toàn công ty', $titles);
        $this->assertNotContains('Của người khác', $titles);
        $this->assertNotContains('Còn nháp', $titles);
        $this->assertNotContains('Hết hiệu lực', $titles);
    }
}
