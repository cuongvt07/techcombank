<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\EmployeeManager;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Chọn số dòng mỗi trang cho data grid (trait HasPerPage, fb7 mục 6). */
class PerPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin->refresh());
    }

    public function test_mac_dinh_15_dong_va_hien_o_chon_moi_trang(): void
    {
        Employee::factory()->count(30)->create();

        Livewire::test(EmployeeManager::class)
            ->assertSet('perPage', 15)
            ->assertViewHas('employees', fn ($paginator) => $paginator->perPage() === 15 && $paginator->count() === 15)
            ->assertSee('Mỗi trang')
            ->assertSee('Hiển thị')
            ->assertSeeHtml('wire:model.live="perPage"');
    }

    public function test_doi_so_dong_moi_trang_va_ve_trang_dau(): void
    {
        Employee::factory()->count(60)->create();

        Livewire::test(EmployeeManager::class)
            ->call('gotoPage', 3)
            ->set('perPage', '50')
            ->assertSet('perPage', 50)
            ->assertSet('paginators.page', 1)
            ->assertViewHas('employees', fn ($paginator) => $paginator->perPage() === 50 && $paginator->count() === 50);
    }

    public function test_gia_tri_ngoai_danh_sach_quay_ve_mac_dinh(): void
    {
        Employee::factory()->count(20)->create();

        Livewire::test(EmployeeManager::class)
            ->set('perPage', '100000')
            ->assertSet('perPage', 15)
            ->assertViewHas('employees', fn ($paginator) => $paginator->perPage() === 15)
            ->set('perPage', 'abc')
            ->assertSet('perPage', 15);
    }

    public function test_gia_tri_tren_url_duoc_nhan_neu_hop_le(): void
    {
        Employee::factory()->count(25)->create();

        Livewire::withQueryParams(['so-dong' => '20'])
            ->test(EmployeeManager::class)
            ->assertSet('perPage', 20)
            ->assertViewHas('employees', fn ($paginator) => $paginator->perPage() === 20);

        Livewire::withQueryParams(['so-dong' => '7'])
            ->test(EmployeeManager::class)
            ->assertSet('perPage', 15);
    }

    public function test_dat_lai_bo_loc_giu_nguyen_so_dong_moi_trang(): void
    {
        Livewire::test(EmployeeManager::class)
            ->set('perPage', 50)
            ->set('search', 'abc')
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('perPage', 50);
    }

    public function test_it_du_lieu_khong_hien_o_chon_moi_trang(): void
    {
        // Chỉ có hồ sơ của admin: 1 dòng, ít hơn lựa chọn nhỏ nhất (15) — chọn số dòng vô nghĩa
        Livewire::test(EmployeeManager::class)
            ->assertDontSee('Mỗi trang')
            ->assertSee('Hiển thị');
    }
}
