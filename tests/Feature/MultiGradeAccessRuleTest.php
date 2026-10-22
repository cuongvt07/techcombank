<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Admin\DocumentAccessManager;
use App\Livewire\Admin\EmployeeManager;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAccessRule;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\User;
use App\Services\DocumentAccessService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cấp bậc chọn nhiều chỉ ở phân quyền tài liệu; hồ sơ nhân sự chỉ một cấp bậc.
 */
class MultiGradeAccessRuleTest extends TestCase
{
    use RefreshDatabase;

    private JobGrade $staff;
    private JobGrade $senior;
    private JobGrade $manager;
    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(RoleName::ADMIN->value);
        Employee::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin->refresh());

        $this->staff = JobGrade::create(['code' => 'T1', 'name' => 'Nhân viên', 'level' => 1]);
        $this->senior = JobGrade::create(['code' => 'T3', 'name' => 'Chuyên viên chính', 'level' => 3]);
        $this->manager = JobGrade::create(['code' => 'T5', 'name' => 'Quản lý', 'level' => 5]);

        $this->document = Document::create([
            'title' => 'Tài liệu thử',
            'slug' => 'tai-lieu-thu',
            'kind' => Document::KIND_FILE,
            'confidentiality' => Document::CONF_INTERNAL,
            'status' => Document::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function employeeWithGrade(JobGrade $grade): Employee
    {
        $employee = Employee::factory()->create([
            'job_grade_id' => $grade->id,
            'employment_status' => Employee::STATUS_OFFICIAL,
        ]);
        $employee->jobGrades()->sync([$grade->id]);

        return $employee->refresh();
    }

    private function canView(Employee $employee): bool
    {
        return app(DocumentAccessService::class)->canView($employee, $this->document);
    }

    // ---- Phân quyền tài liệu: chọn nhiều cấp bậc ----------------------------

    public function test_tao_quy_tac_voi_nhieu_cap_bac(): void
    {
        Livewire::test(DocumentAccessManager::class)
            ->call('create')
            ->set('scope', 'document')
            ->set('documentId', $this->document->id)
            ->set('jobGradeIds', [$this->staff->id, $this->senior->id])
            ->call('save')
            ->assertHasNoErrors();

        $rule = DocumentAccessRule::where('document_id', $this->document->id)->first();

        $this->assertEqualsCanonicalizing(
            [$this->staff->id, $this->senior->id],
            $rule->jobGrades->pluck('id')->all()
        );
        $this->assertNull($rule->job_grade_id, 'Cấp bậc phải nằm ở bảng nối, cột cũ để trống');
    }

    public function test_nhan_vien_thuoc_mot_trong_cac_cap_bac_thi_xem_duoc(): void
    {
        $rule = DocumentAccessRule::create([
            'document_id' => $this->document->id,
            'effect' => DocumentAccessRule::EFFECT_ALLOW,
            'can_view' => true,
            'is_active' => true,
        ]);
        $rule->jobGrades()->sync([$this->staff->id, $this->senior->id]);

        $this->assertTrue($this->canView($this->employeeWithGrade($this->staff)));
        $this->assertTrue($this->canView($this->employeeWithGrade($this->senior)));
        $this->assertFalse(
            $this->canView($this->employeeWithGrade($this->manager)),
            'Cấp bậc không nằm trong quy tắc thì không được xem'
        );
    }

    public function test_tai_lieu_hien_trong_danh_sach_theo_nhieu_cap_bac(): void
    {
        // Đường truy vấn danh sách khác đường kiểm tra từng tài liệu, phải đúng cả hai
        $rule = DocumentAccessRule::create([
            'document_id' => $this->document->id,
            'effect' => DocumentAccessRule::EFFECT_ALLOW,
            'can_view' => true,
            'is_active' => true,
        ]);
        $rule->jobGrades()->sync([$this->staff->id, $this->senior->id]);

        $service = app(DocumentAccessService::class);
        $ids = fn (Employee $e) => $service->visibleDocumentsQuery($e)->pluck('id')->all();

        $this->assertContains($this->document->id, $ids($this->employeeWithGrade($this->senior)));
        $this->assertNotContains($this->document->id, $ids($this->employeeWithGrade($this->manager)));
    }

    public function test_khong_chon_cap_bac_nao_thi_moi_cap_bac_deu_xem_duoc(): void
    {
        DocumentAccessRule::create([
            'document_id' => $this->document->id,
            'effect' => DocumentAccessRule::EFFECT_ALLOW,
            'can_view' => true,
            'is_active' => true,
        ]);

        $this->assertTrue($this->canView($this->employeeWithGrade($this->manager)));
    }

    public function test_sua_quy_tac_nap_lai_du_cac_cap_bac(): void
    {
        $rule = DocumentAccessRule::create([
            'document_id' => $this->document->id,
            'effect' => DocumentAccessRule::EFFECT_ALLOW,
            'can_view' => true,
            'is_active' => true,
        ]);
        $rule->jobGrades()->sync([$this->staff->id, $this->manager->id]);

        $loaded = Livewire::test(DocumentAccessManager::class)
            ->call('edit', $rule->id)
            ->get('jobGradeIds');

        $this->assertEqualsCanonicalizing([$this->staff->id, $this->manager->id], $loaded);
    }

    // ---- Hồ sơ nhân sự: chỉ một cấp bậc -------------------------------------

    public function test_ho_so_nhan_su_chi_luu_mot_cap_bac(): void
    {
        $department = Department::factory()->create();

        Livewire::test(EmployeeManager::class)
            ->call('create')
            ->set('full_name', 'Nguyễn Một Cấp')
            ->set('department_id', $department->id)
            ->set('job_grade_id', $this->senior->id)
            ->set('employment_status', Employee::STATUS_OFFICIAL)
            ->call('save')
            ->assertHasNoErrors();

        $employee = Employee::where('full_name', 'Nguyễn Một Cấp')->first();

        $this->assertSame($this->senior->id, $employee->job_grade_id);
        // Bảng nối khớp đúng một cấp bậc để phân quyền tài liệu đọc đúng
        $this->assertSame([$this->senior->id], $employee->jobGrades->pluck('id')->all());
    }
}
