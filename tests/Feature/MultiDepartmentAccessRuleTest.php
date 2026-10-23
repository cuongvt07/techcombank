<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\DocumentAccessManager;
use App\Livewire\Admin\DocumentLibrary;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAccessRule;
use App\Models\Employee;
use App\Models\User;
use App\Services\DocumentAccessService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Một quy tắc phân quyền tài liệu cho nhiều phòng ban (bảng nối
 * document_access_rule_department). Không chọn phòng ban = toàn công ty.
 */
class MultiDepartmentAccessRuleTest extends TestCase
{
    use RefreshDatabase;

    private Department $sales;
    private Department $salesNorth; // phòng con của sales
    private Department $credit;
    private Department $it;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id, 'department_id' => null]);
        $this->actingAs($admin->refresh());

        $this->sales = Department::factory()->create(['name' => 'Phòng Kinh doanh']);
        $this->salesNorth = Department::factory()->create(['name' => 'Kinh doanh miền Bắc', 'parent_id' => $this->sales->id]);
        $this->credit = Department::factory()->create(['name' => 'Phòng Tín dụng']);
        $this->it = Department::factory()->create(['name' => 'Phòng Công nghệ']);
    }

    private function document(string $title = 'Tài liệu thử'): Document
    {
        return Document::create([
            'title' => $title,
            'slug' => str($title)->slug() . '-' . uniqid(),
            'kind' => Document::KIND_FILE,
            'confidentiality' => Document::CONF_INTERNAL,
            'status' => Document::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function employeeIn(?Department $department): Employee
    {
        return Employee::factory()->create([
            'department_id' => $department?->id,
            'employment_status' => Employee::STATUS_OFFICIAL,
        ])->refresh();
    }

    /** @param  array<int, int>  $departmentIds */
    private function rule(Document $document, array $departmentIds, array $attributes = []): DocumentAccessRule
    {
        $rule = DocumentAccessRule::create($attributes + [
            'document_id' => $document->id,
            'can_view' => true,
            'effect' => DocumentAccessRule::EFFECT_ALLOW,
            'include_sub_departments' => true,
            'is_active' => true,
        ]);
        $rule->departments()->sync($departmentIds);

        return $rule;
    }

    private function canView(Employee $employee, Document $document): bool
    {
        return app(DocumentAccessService::class)->canView($employee, $document);
    }

    /** visibleDocumentsQuery và countViewers phải khớp canView từng cặp. */
    private function assertConsistent(): void
    {
        $service = app(DocumentAccessService::class);
        $documents = Document::all();
        $employees = Employee::with(['jobGrade', 'jobGrades'])->get();

        $expectedCounts = [];
        foreach ($documents as $document) {
            $expectedCounts[$document->id] = 0;
        }

        foreach ($employees as $employee) {
            $visible = $service->visibleDocumentsQuery($employee)->pluck('id')->sort()->values()->all();
            $expected = $documents->filter(fn ($d) => $service->canView($employee, $d))->pluck('id')->sort()->values()->all();

            $this->assertSame($expected, $visible, 'visibleDocumentsQuery lệch canView cho NV #' . $employee->id);

            foreach ($expected as $id) {
                $expectedCounts[$id]++;
            }
        }

        $this->assertSame($expectedCounts, $service->countViewers($documents, $employees));
    }

    public function test_quy_tac_nhieu_phong_ban_khop_nhan_vien_thuoc_mot_trong_so_do(): void
    {
        $document = $this->document();
        $this->rule($document, [$this->sales->id, $this->credit->id]);

        $this->assertTrue($this->canView($this->employeeIn($this->sales), $document));
        $this->assertTrue($this->canView($this->employeeIn($this->credit), $document));
        $this->assertFalse($this->canView($this->employeeIn($this->it), $document));
        $this->assertFalse($this->canView($this->employeeIn(null), $document));

        $this->assertConsistent();
    }

    public function test_khong_chon_phong_ban_la_toan_cong_ty(): void
    {
        $document = $this->document();
        $this->rule($document, []);

        $this->assertTrue($this->canView($this->employeeIn($this->it), $document));
        $this->assertTrue($this->canView($this->employeeIn($this->salesNorth), $document));
        $this->assertTrue($this->canView($this->employeeIn(null), $document));

        $this->assertConsistent();
    }

    public function test_phong_con_chi_khop_khi_bat_gom_phong_con(): void
    {
        $withSub = $this->document('Gồm phòng con');
        $this->rule($withSub, [$this->sales->id, $this->it->id], ['include_sub_departments' => true]);

        $withoutSub = $this->document('Không gồm phòng con');
        $this->rule($withoutSub, [$this->sales->id, $this->it->id], ['include_sub_departments' => false]);

        $north = $this->employeeIn($this->salesNorth);

        $this->assertTrue($this->canView($north, $withSub));
        $this->assertFalse($this->canView($north, $withoutSub));
        $this->assertTrue($this->canView($this->employeeIn($this->sales), $withoutSub));

        $this->assertConsistent();
    }

    public function test_chan_theo_nhieu_phong_ban_va_cot_cu_van_tuong_thich(): void
    {
        $document = $this->document();
        $this->rule($document, []);
        // Quy tắc chặn cho hai phòng ban, ưu tiên cao hơn
        $this->rule($document, [$this->credit->id, $this->salesNorth->id], [
            'effect' => DocumentAccessRule::EFFECT_DENY,
            'can_view' => false,
            'priority' => 10,
        ]);

        // Dữ liệu cũ còn giữ ở cột department_id (chưa chuyển sang bảng nối)
        $legacy = $this->document('Quy tắc cũ');
        DocumentAccessRule::create([
            'document_id' => $legacy->id,
            'department_id' => $this->sales->id,
            'include_sub_departments' => true,
            'can_view' => true,
            'is_active' => true,
        ]);

        $this->assertFalse($this->canView($this->employeeIn($this->credit), $document));
        $this->assertFalse($this->canView($this->employeeIn($this->salesNorth), $document));
        $this->assertTrue($this->canView($this->employeeIn($this->it), $document));
        $this->assertTrue($this->canView($this->employeeIn($this->salesNorth), $legacy));
        $this->assertFalse($this->canView($this->employeeIn($this->it), $legacy));

        $this->assertConsistent();
    }

    public function test_man_quy_tac_luu_va_sua_nhieu_phong_ban(): void
    {
        $document = $this->document();

        Livewire::test(DocumentAccessManager::class)
            ->call('create')
            ->set('scope', 'document')
            ->set('documentId', $document->id)
            ->set('departmentIds', [$this->sales->id, $this->credit->id])
            ->call('save')
            ->assertHasNoErrors();

        $rule = DocumentAccessRule::where('document_id', $document->id)->sole();
        $this->assertNull($rule->department_id);
        $this->assertEqualsCanonicalizing([$this->sales->id, $this->credit->id], $rule->departmentIds());

        Livewire::test(DocumentAccessManager::class)
            ->assertSee('Phòng Kinh doanh, Phòng Tín dụng')
            ->call('edit', $rule->id)
            ->assertSet('departmentIds', $rule->departmentIds())
            ->set('departmentIds', [$this->it->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$this->it->id], $rule->fresh()->departmentIds());

        // Bỏ hết phòng ban = toàn công ty
        Livewire::test(DocumentAccessManager::class)
            ->call('edit', $rule->id)
            ->set('departmentIds', [])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([], $rule->fresh()->departmentIds());

        Livewire::test(DocumentAccessManager::class)->assertSee('Toàn công ty');
    }

    public function test_thu_vien_tao_tai_lieu_cho_nhieu_phong_ban(): void
    {
        Storage::fake('public');

        Livewire::test(DocumentLibrary::class)
            ->call('create')
            ->set('title', 'Tài liệu nhiều phòng ban')
            ->set('upload', UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'))
            ->call('continueToPermissions')
            ->set('accessDepartmentIds', [$this->sales->id, $this->it->id])
            ->call('save')
            ->assertHasNoErrors();

        $document = Document::where('title', 'Tài liệu nhiều phòng ban')->firstOrFail();
        $rule = $document->accessRules()->sole();
        $this->assertEqualsCanonicalizing([$this->sales->id, $this->it->id], $rule->departmentIds());

        Livewire::test(DocumentLibrary::class)
            ->call('edit', $document->id)
            ->call('continueToPermissions')
            ->assertSet('accessDepartmentIds', $rule->departmentIds())
            ->set('accessDepartmentIds', [])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([], $document->accessRules()->sole()->departmentIds());
    }

    public function test_tu_choi_phong_ban_khong_ton_tai(): void
    {
        Livewire::test(DocumentAccessManager::class)
            ->call('create')
            ->set('scope', 'document')
            ->set('documentId', $this->document()->id)
            ->set('departmentIds', [999999])
            ->call('save')
            ->assertHasErrors(['departmentIds.0']);
    }
}
