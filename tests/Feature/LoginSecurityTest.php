<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Auth\Login;
use App\Models\DeviceSession;
use App\Models\Employee;
use App\Models\LoginHistory;
use App\Models\SecurityAlert;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Kiểm chứng luồng đăng nhập có ghi vết bảo mật (spec 3.1.1 + 3.3.2). */
class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dang_nhap_thanh_cong_ghi_lich_su_va_phien_thiet_bi(): void
    {
        $user = $this->makeEmployee('nhanvien@test.local', 'matkhau123');

        Livewire::test(Login::class)
            ->set('email', 'nhanvien@test.local')
            ->set('password', 'matkhau123')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'result' => 'success',
        ]);

        // Phiên thiết bị phải được ghi để áp giới hạn máy đồng thời
        $this->assertSame(1, DeviceSession::where('user_id', $user->id)->where('is_active', true)->count());

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_login_ip);
    }

    public function test_sai_mat_khau_ghi_lich_su_that_bai(): void
    {
        $user = $this->makeEmployee('sai@test.local', 'matkhaudung');

        Livewire::test(Login::class)
            ->set('email', 'sai@test.local')
            ->set('password', 'matkhausai')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'result' => 'failed',
        ]);
    }

    public function test_tai_khoan_bi_khoa_khong_dang_nhap_duoc_du_dung_mat_khau(): void
    {
        $user = $this->makeEmployee('bikhoa@test.local', 'matkhau123');
        $user->update(['status' => User::STATUS_LOCKED]);

        Livewire::test(Login::class)
            ->set('email', 'bikhoa@test.local')
            ->set('password', 'matkhau123')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'result' => 'blocked',
        ]);
    }

    public function test_nhan_vien_nghi_viec_bi_vo_hieu_khong_dang_nhap_duoc(): void
    {
        $user = $this->makeEmployee('nghiviec@test.local', 'matkhau123');
        $user->update(['status' => User::STATUS_DISABLED]);

        Livewire::test(Login::class)
            ->set('email', 'nghiviec@test.local')
            ->set('password', 'matkhau123')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_dang_nhap_tu_ip_la_sinh_canh_bao(): void
    {
        $user = $this->makeEmployee('iplạ@test.local', 'matkhau123');

        // Có lịch sử đăng nhập cũ từ IP khác
        LoginHistory::create([
            'user_id' => $user->id,
            'result' => 'success',
            'ip_address' => '10.9.9.9',
            'logged_in_at' => now()->subDays(3),
        ]);

        Livewire::test(Login::class)
            ->set('email', 'iplạ@test.local')
            ->set('password', 'matkhau123')
            ->call('login');

        $this->assertDatabaseHas('security_alerts', [
            'user_id' => $user->id,
            'type' => SecurityAlert::TYPE_UNUSUAL_IP,
        ]);
    }

    public function test_lan_dang_nhap_dau_tien_khong_sinh_canh_bao_ip(): void
    {
        $user = $this->makeEmployee('landau@test.local', 'matkhau123');

        Livewire::test(Login::class)
            ->set('email', 'landau@test.local')
            ->set('password', 'matkhau123')
            ->call('login');

        $this->assertSame(0, SecurityAlert::where('user_id', $user->id)
            ->where('type', SecurityAlert::TYPE_UNUSUAL_IP)
            ->count());
    }

    public function test_chan_dang_nhap_sau_nhieu_lan_that_bai(): void
    {
        $this->makeEmployee('brute@test.local', 'matkhaudung');

        // 5 lần thất bại rồi lần thứ 6 bị chặn bởi rate limiter
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->set('email', 'brute@test.local')
                ->set('password', 'sai' . $i)
                ->call('login');
        }

        $component = Livewire::test(Login::class)
            ->set('email', 'brute@test.local')
            ->set('password', 'matkhaudung')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('quá nhiều lần', implode(' ', $component->errors()->get('email')));
    }

    private function makeEmployee(string $email, string $password): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => $password,
            'status' => User::STATUS_ACTIVE,
        ]);

        $user->assignRole(RoleName::EMPLOYEE->value);
        Employee::factory()->create(['user_id' => $user->id]);

        return $user->refresh();
    }
}
