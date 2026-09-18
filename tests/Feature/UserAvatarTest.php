<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\User\Profile;
use App\Models\Employee;
use App\Models\User;
use App\Services\FileStorageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Ảnh đại diện nhân viên. */
class UserAvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(FileStorageService::DISK);

        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->user->assignRole(RoleName::EMPLOYEE->value);
        $this->employee = Employee::factory()->create([
            'user_id' => $this->user->id,
            'full_name' => 'Nguyễn Văn Minh',
        ]);
    }

    // ---- Chữ cái tự sinh ----------------------------------------------------

    public function test_lay_chu_dau_cua_ho_va_ten(): void
    {
        $this->assertSame('NM', $this->employee->initials());
    }

    public function test_ten_mot_chu_thi_lay_mot_chu_cai(): void
    {
        $employee = Employee::factory()->create(['full_name' => 'Minh']);

        $this->assertSame('M', $employee->initials());
    }

    public function test_ten_rong_thi_khong_vo(): void
    {
        $employee = Employee::factory()->make(['full_name' => '   ']);

        $this->assertSame('?', $employee->initials());
    }

    // ---- Tải ảnh lên --------------------------------------------------------

    public function test_upload_anh_dai_dien(): void
    {
        Livewire::actingAs($this->user)
            ->test(Profile::class)
            ->set('avatar', UploadedFile::fake()->image('toi.jpg', 200, 200))
            ->call('saveAvatar')
            ->assertHasNoErrors();

        $this->employee->refresh();

        $this->assertNotNull($this->employee->avatar_file_id);
        // Ảnh vào kho tài nguyên chung như mọi file khác
        $this->assertSame('Ảnh đại diện', $this->employee->avatarFile->folder->name);
        Storage::disk(FileStorageService::DISK)->assertExists($this->employee->avatarFile->path);
    }

    public function test_chan_file_khong_phai_anh(): void
    {
        Livewire::actingAs($this->user)
            ->test(Profile::class)
            ->set('avatar', UploadedFile::fake()->create('virus.pdf', 100, 'application/pdf'))
            ->call('saveAvatar')
            ->assertHasErrors(['avatar']);

        $this->assertNull($this->employee->refresh()->avatar_file_id);
    }

    public function test_chan_anh_qua_2mb(): void
    {
        Livewire::actingAs($this->user)
            ->test(Profile::class)
            ->set('avatar', UploadedFile::fake()->image('to.jpg')->size(3000))
            ->call('saveAvatar')
            ->assertHasErrors(['avatar']);
    }

    public function test_go_anh_thi_ve_avatar_chu_cai(): void
    {
        Livewire::actingAs($this->user)
            ->test(Profile::class)
            ->set('avatar', UploadedFile::fake()->image('toi.jpg'))
            ->call('saveAvatar')
            ->call('removeAvatar');

        $this->assertNull($this->employee->refresh()->avatar_file_id);
    }

    // ---- Phát ảnh -----------------------------------------------------------

    public function test_nguoi_da_dang_nhap_xem_duoc_anh(): void
    {
        Livewire::actingAs($this->user)
            ->test(Profile::class)
            ->set('avatar', UploadedFile::fake()->image('toi.jpg'))
            ->call('saveAvatar');

        $this->actingAs($this->user)
            ->get(route('learn.avatar', $this->employee))
            ->assertOk();
    }

    public function test_khach_chua_dang_nhap_khong_xem_duoc_anh(): void
    {
        // Ảnh nhân viên là dữ liệu nội bộ, không để lộ qua đường dẫn đoán được
        $this->get(route('learn.avatar', $this->employee))
            ->assertRedirect(route('login'));
    }

    public function test_chua_co_anh_thi_tra_404(): void
    {
        $this->actingAs($this->user)
            ->get(route('learn.avatar', $this->employee))
            ->assertNotFound();
    }

    public function test_avatar_hien_tren_trang_ca_nhan(): void
    {
        Livewire::actingAs($this->user)
            ->test(Profile::class)
            ->assertSee('NM');
    }
}
