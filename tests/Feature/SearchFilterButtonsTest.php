<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\AccountManager;
use App\Livewire\Admin\EmployeeManager;
use App\Livewire\Admin\SecurityCenter;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Nút "Tìm kiếm" / "Đặt lại" dùng chung ở các thanh lọc (trait HasSearchFilters). */
class SearchFilterButtonsTest extends TestCase
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

    public function test_dat_lai_xoa_moi_bo_loc(): void
    {
        Livewire::test(AccountManager::class)
            ->set('search', 'abc')
            ->set('statusFilter', 'locked')
            ->set('roleFilter', RoleName::EMPLOYEE->value)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('statusFilter', '')
            ->assertSet('roleFilter', '');
    }

    public function test_dat_lai_giu_nguyen_tab_dang_mo(): void
    {
        Livewire::test(SecurityCenter::class)
            ->call('setTab', 'logins')
            ->set('search', '10.0.0.1')
            ->call('resetFilters')
            ->assertSet('tab', 'logins')
            ->assertSet('search', '');
    }

    public function test_tim_kiem_ve_trang_dau_va_loc_dung(): void
    {
        Employee::factory()->count(20)->create();
        Employee::factory()->create(['full_name' => 'Người Cần Tìm Kiếm']);

        Livewire::test(EmployeeManager::class)
            ->call('gotoPage', 2)
            ->set('search', 'Cần Tìm Kiếm')
            ->call('applySearch')
            ->assertSet('paginators.page', 1)
            ->assertSee('Người Cần Tìm Kiếm');
    }

    public function test_thanh_loc_co_nut_tim_kiem_va_dat_lai(): void
    {
        Livewire::test(EmployeeManager::class)
            ->assertSeeHtml('wire:click="applySearch"')
            ->assertSeeHtml('wire:click="resetFilters"')
            // Ô tìm kiếm không còn gửi request mỗi lần gõ phím
            ->assertDontSeeHtml('wire:model.live.debounce.400ms="search"');
    }
}
