<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\SupportManager;
use App\Livewire\User\SupportRequestForm;
use App\Models\Employee;
use App\Models\SupportRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Admin xử lý phiếu hỗ trợ nhân viên gửi (spec 4.5). */
class SupportManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employeeUser;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $this->admin->id]);

        $this->employeeUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->employeeUser->assignRole(RoleName::EMPLOYEE->value);
        $this->employee = Employee::factory()->create(['user_id' => $this->employeeUser->id]);
    }

    private function ticket(array $attrs = []): SupportRequest
    {
        return SupportRequest::create(array_merge([
            'ticket_no' => 'HT-' . random_int(100000, 999999),
            'employee_id' => $this->employee->id,
            'topic' => 'Hợp đồng',
            'subject' => 'Hỏi về hợp đồng',
            'content' => 'Nội dung thắc mắc.',
            'priority' => 'normal',
            'status' => SupportRequest::STATUS_NEW,
        ], $attrs));
    }

    public function test_vong_khep_kin_nhan_vien_gui_admin_thay(): void
    {
        // Đây là lỗ hổng gốc: nhân viên gửi được nhưng admin không có màn nào xem
        Livewire::actingAs($this->employeeUser)
            ->test(SupportRequestForm::class)
            ->set('topic', 'Khác')
            ->set('subject', 'Không vào được khóa học')
            ->set('content', 'Bấm vào khóa mà báo lỗi.')
            ->call('submit');

        $subjects = Livewire::actingAs($this->admin)
            ->test(SupportManager::class)
            ->viewData('requests')
            ->pluck('subject');

        $this->assertContains('Không vào được khóa học', $subjects);
    }

    public function test_mac_dinh_chi_hien_phieu_con_mo(): void
    {
        $this->ticket(['subject' => 'Phiếu mới']);
        $this->ticket(['subject' => 'Phiếu đã đóng', 'status' => SupportRequest::STATUS_CLOSED]);

        $subjects = Livewire::actingAs($this->admin)
            ->test(SupportManager::class)
            ->viewData('requests')
            ->pluck('subject');

        $this->assertContains('Phiếu mới', $subjects);
        $this->assertNotContains('Phiếu đã đóng', $subjects);
    }

    public function test_phieu_gap_xep_truoc(): void
    {
        $this->ticket(['subject' => 'Bình thường', 'priority' => 'normal']);
        $this->ticket(['subject' => 'Gấp', 'priority' => 'high']);

        $first = Livewire::actingAs($this->admin)
            ->test(SupportManager::class)
            ->viewData('requests')
            ->first();

        $this->assertSame('Gấp', $first->subject);
    }

    public function test_nhan_xu_ly_gan_cho_minh_va_chuyen_trang_thai(): void
    {
        $ticket = $this->ticket();

        Livewire::actingAs($this->admin)
            ->test(SupportManager::class)
            ->call('take', $ticket->id);

        $ticket->refresh();
        $this->assertSame(SupportRequest::STATUS_IN_PROGRESS, $ticket->status);
        $this->assertSame($this->admin->id, $ticket->assigned_to);
    }

    public function test_giai_quyet_bat_buoc_co_noi_dung_phan_hoi(): void
    {
        $ticket = $this->ticket();

        Livewire::actingAs($this->admin)
            ->test(SupportManager::class)
            ->call('view', $ticket->id)
            ->set('resolution', '')
            ->call('resolve')
            ->assertHasErrors(['resolution']);

        $this->assertSame(SupportRequest::STATUS_NEW, $ticket->refresh()->status);
    }

    public function test_giai_quyet_ghi_phan_hoi_va_thoi_diem(): void
    {
        $ticket = $this->ticket();

        Livewire::actingAs($this->admin)
            ->test(SupportManager::class)
            ->call('view', $ticket->id)
            ->set('resolution', 'Đã kiểm tra, bạn thử đăng nhập lại.')
            ->call('resolve')
            ->assertHasNoErrors();

        $ticket->refresh();
        $this->assertSame(SupportRequest::STATUS_RESOLVED, $ticket->status);
        $this->assertSame('Đã kiểm tra, bạn thử đăng nhập lại.', $ticket->resolution);
        $this->assertNotNull($ticket->resolved_at);
    }

    public function test_nhan_vien_doc_duoc_phan_hoi_cua_admin(): void
    {
        $ticket = $this->ticket(['subject' => 'Hỏi nghỉ phép']);

        Livewire::actingAs($this->admin)
            ->test(SupportManager::class)
            ->call('view', $ticket->id)
            ->set('resolution', 'NOIDUNGPHANHOICUAADMIN')
            ->call('resolve');

        Livewire::actingAs($this->employeeUser)
            ->test(SupportRequestForm::class)
            ->assertSee('NOIDUNGPHANHOICUAADMIN');
    }

    public function test_mo_lai_phieu_da_giai_quyet(): void
    {
        $ticket = $this->ticket([
            'status' => SupportRequest::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(SupportManager::class)
            ->call('reopen', $ticket->id);

        $ticket->refresh();
        $this->assertSame(SupportRequest::STATUS_IN_PROGRESS, $ticket->status);
        $this->assertNull($ticket->resolved_at);
    }

    public function test_dong_phieu(): void
    {
        $ticket = $this->ticket();

        Livewire::actingAs($this->admin)->test(SupportManager::class)->call('close', $ticket->id);

        $this->assertSame(SupportRequest::STATUS_CLOSED, $ticket->refresh()->status);
    }

    public function test_tim_theo_ten_nhan_vien(): void
    {
        $this->employee->update(['full_name' => 'Nguyễn Tìm Thấy']);
        $this->ticket(['subject' => 'Của người cần tìm']);

        $other = Employee::factory()->create(['full_name' => 'Người Khác Hẳn']);
        $this->ticket(['subject' => 'Của người khác', 'employee_id' => $other->id]);

        $subjects = Livewire::actingAs($this->admin)
            ->test(SupportManager::class)
            ->set('search', 'Tìm Thấy')
            ->viewData('requests')
            ->pluck('subject');

        $this->assertContains('Của người cần tìm', $subjects);
        $this->assertNotContains('Của người khác', $subjects);
    }

    public function test_nhan_vien_khong_vao_duoc_man_hinh_quan_tri_phieu(): void
    {
        $this->actingAs($this->employeeUser)
            ->get(route('admin.support'))
            ->assertRedirect(route('learn.events'));
    }

    public function test_admin_vao_duoc_man_hinh(): void
    {
        $this->actingAs($this->admin)->get(route('admin.support'))->assertOk();
    }

    public function test_menu_quan_tri_hien_muc_phieu_ho_tro(): void
    {
        // Trước đây mục này bị ẩn vì route chưa tồn tại
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertSee('Phiếu hỗ trợ');
    }
}
