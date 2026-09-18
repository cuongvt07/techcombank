<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bổ sung dữ liệu mẫu quy mô lớn: phòng ban, chức danh, nhân viên, hợp đồng.
 *
 * Chạy sau DemoDataSeeder để giữ nguyên các bản ghi viết tay có nội dung thật
 * (bài giảng, câu hỏi kèm giải thích), chỉ thêm số lượng lên trên.
 *
 * Tên người sinh từ bảng họ/đệm/tên tiếng Việt thay vì faker: faker sinh tên
 * nước ngoài, nhìn không giống danh sách nhân sự ngân hàng Việt Nam.
 */
class DemoBulkSeeder extends Seeder
{
    /** Tổng số nhân viên sau khi chạy (tính cả nhân viên có sẵn). */
    private const TARGET_EMPLOYEES = 120;

    private const HO = [
        'Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Huỳnh', 'Phan', 'Vũ', 'Võ',
        'Đặng', 'Bùi', 'Đỗ', 'Hồ', 'Ngô', 'Dương', 'Lý', 'Đinh', 'Trịnh',
    ];

    private const DEM_NAM = ['Văn', 'Hữu', 'Đức', 'Quang', 'Minh', 'Thanh', 'Công', 'Xuân', 'Trung'];
    private const DEM_NU = ['Thị', 'Ngọc', 'Thu', 'Hồng', 'Kim', 'Mai', 'Phương', 'Thanh', 'Bích'];

    private const TEN_NAM = [
        'An', 'Bình', 'Cường', 'Dũng', 'Đạt', 'Giang', 'Hải', 'Hùng', 'Khoa',
        'Lâm', 'Long', 'Nam', 'Phong', 'Quân', 'Sơn', 'Tài', 'Thắng', 'Tuấn', 'Vinh',
    ];

    private const TEN_NU = [
        'Anh', 'Chi', 'Dung', 'Hà', 'Hằng', 'Hoa', 'Hương', 'Lan', 'Linh',
        'Mai', 'Nga', 'Ngân', 'Nhung', 'Oanh', 'Phương', 'Quỳnh', 'Thảo', 'Trang', 'Yến',
    ];

    public function run(): void
    {
        $departments = $this->ensureDepartments();
        $grades = JobGrade::orderBy('level')->get()->keyBy('code');
        $titles = $this->ensureJobTitles($departments);

        $this->createEmployees($departments, $titles, $grades);
        $this->createContracts();
    }

    /**
     * Bổ sung phòng ban cho đủ 12 đơn vị.
     *
     * @return \Illuminate\Support\Collection<int, Department>
     */
    private function ensureDepartments(): \Illuminate\Support\Collection
    {
        $hq = Department::where('code', 'HQ')->first()
            ?? Department::create(['code' => 'HQ', 'name' => 'Hội sở', 'sort_order' => 0]);

        $them = [
            ['RSK', 'Khối Quản trị rủi ro', 6],
            ['FIN', 'Khối Tài chính', 7],
            ['MKT', 'Khối Marketing', 8],
            ['RTL', 'Khối Khách hàng cá nhân', 9],
            ['SME', 'Khối Khách hàng doanh nghiệp', 10],
            ['LEG', 'Phòng Pháp chế', 11],
            ['CN-HN', 'Chi nhánh Hà Nội', 12],
            ['CN-HCM', 'Chi nhánh TP. Hồ Chí Minh', 13],
        ];

        foreach ($them as [$code, $name, $order]) {
            Department::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'parent_id' => $hq->id, 'sort_order' => $order],
            );
        }

        // Bỏ Hội sở khỏi danh sách gán nhân viên: đây là đơn vị cha, không phải
        // nơi nhân viên trực tiếp thuộc về
        return Department::where('code', '!=', 'HQ')->get();
    }

    /**
     * Mỗi phòng ban cần ít nhất một chức danh để gán nhân viên.
     *
     * @return \Illuminate\Support\Collection<int, JobTitle>
     */
    private function ensureJobTitles(\Illuminate\Support\Collection $departments): \Illuminate\Support\Collection
    {
        $theoPhong = [
            'RSK' => [['JT-RSK1', 'Chuyên viên Quản trị rủi ro'], ['JT-RSK2', 'Chuyên viên Phân tích rủi ro']],
            'FIN' => [['JT-FIN1', 'Chuyên viên Kế toán'], ['JT-FIN2', 'Chuyên viên Phân tích tài chính']],
            'MKT' => [['JT-MKT1', 'Chuyên viên Marketing'], ['JT-MKT2', 'Chuyên viên Truyền thông']],
            'RTL' => [['JT-RTL1', 'Chuyên viên Khách hàng cá nhân'], ['JT-RTL2', 'Chuyên viên Tư vấn sản phẩm']],
            'SME' => [['JT-SME1', 'Chuyên viên Khách hàng doanh nghiệp'], ['JT-SME2', 'Chuyên viên Thẩm định']],
            'LEG' => [['JT-LEG1', 'Chuyên viên Pháp chế']],
            'CN-HN' => [['JT-CNHN1', 'Giao dịch viên chi nhánh'], ['JT-CNHN2', 'Kiểm soát viên']],
            'CN-HCM' => [['JT-CNHCM1', 'Giao dịch viên chi nhánh'], ['JT-CNHCM2', 'Kiểm soát viên']],
            'OPS' => [['JT-OPS2', 'Kiểm soát viên vận hành']],
            'CRD' => [['JT-CRD2', 'Chuyên viên Tái thẩm định']],
            'IT' => [['JT-IT2', 'Chuyên viên An ninh thông tin'], ['JT-IT3', 'Lập trình viên']],
        ];

        foreach ($theoPhong as $deptCode => $rows) {
            $dept = $departments->firstWhere('code', $deptCode);

            if (! $dept) {
                continue;
            }

            foreach ($rows as [$code, $name]) {
                JobTitle::firstOrCreate(['code' => $code], [
                    'name' => $name,
                    'department_id' => $dept->id,
                ]);
            }
        }

        return JobTitle::all();
    }

    private function createEmployees(
        \Illuminate\Support\Collection $departments,
        \Illuminate\Support\Collection $titles,
        \Illuminate\Support\Collection $grades,
    ): void {
        $daCo = Employee::count();
        $canThem = max(0, self::TARGET_EMPLOYEES - $daCo);

        if ($canThem === 0) {
            return;
        }

        $emailDaDung = User::pluck('email')->flip();
        $titlesByDept = $titles->groupBy('department_id');

        // Tỉ lệ cấp bậc phản ánh cơ cấu thật: nhiều nhân viên, ít quản lý
        $gradeWeights = ['G1' => 60, 'G3' => 28, 'G5' => 10, 'G8' => 2];

        DB::transaction(function () use ($canThem, $departments, $titlesByDept, $grades, $gradeWeights, &$emailDaDung) {
            for ($i = 0; $i < $canThem; $i++) {
                $isNu = random_int(0, 1) === 1;
                $name = $this->randomName($isNu);

                $email = $this->uniqueEmail($name, $emailDaDung);
                $emailDaDung[$email] = true;

                $dept = $departments->random();
                $deptTitles = $titlesByDept->get($dept->id);

                // Phòng chưa có chức danh riêng thì lấy bất kỳ, để không bỏ sót nhân viên
                $title = $deptTitles?->random() ?? $titles->random();
                $grade = $grades->get($this->weightedPick($gradeWeights)) ?? $grades->first();

                $this->makeEmployee($name, $email, $dept, $title, $grade);
            }
        });
    }

    private function makeEmployee(string $name, string $email, Department $dept, JobTitle $title, JobGrade $grade): void
    {
        // Phân bố thâm niên: có người mới vào, có người lâu năm — để báo cáo và
        // bộ lọc "nhân viên mới" có dữ liệu thật để hiển thị
        $daysAgo = random_int(3, 2200);
        $joinedAt = now()->subDays($daysAgo);
        $isNewHire = $daysAgo <= 60;

        // Một số ít đã nghỉ việc, để màn hình nhân sự có đủ trạng thái
        $daNghi = random_int(1, 100) <= 4 && ! $isNewHire;

        $status = match (true) {
            $daNghi => Employee::STATUS_RESIGNED,
            $isNewHire => Employee::STATUS_PROBATION,
            default => Employee::STATUS_OFFICIAL,
        };

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'status' => $daNghi ? User::STATUS_DISABLED : User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $user->assignRole(RoleName::EMPLOYEE->value);

        Employee::create([
            'employee_code' => 'NV' . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'full_name' => $name,
            'email' => $email,
            'phone' => '09' . random_int(10000000, 99999999),
            'department_id' => $dept->id,
            'job_title_id' => $title->id,
            'job_grade_id' => $grade->id,
            'employment_status' => $status,
            'joined_at' => $joinedAt,
            'resigned_at' => $daNghi ? $joinedAt->copy()->addDays(random_int(200, 1500)) : null,
            'is_new_hire' => $isNewHire,
        ]);
    }

    /**
     * Hợp đồng cho nhân viên chưa có.
     *
     * Trộn nhiều trạng thái: đang hiệu lực, sắp hết hạn, đã hết hạn — để màn
     * hình hợp đồng và cảnh báo hạn có dữ liệu ở mọi nhánh.
     */
    private function createContracts(): void
    {
        $types = ContractType::all();

        if ($types->isEmpty()) {
            return;
        }

        $xacDinh = $types->firstWhere('code', 'HDXD') ?? $types->first();
        $khongXacDinh = $types->firstWhere('code', 'HDKXD') ?? $types->first();
        $thuViec = $types->firstWhere('code', 'HDTV') ?? $types->first();

        $chuaCo = Employee::whereDoesntHave('contracts')->get();
        $stt = Contract::count();

        DB::transaction(function () use ($chuaCo, $xacDinh, $khongXacDinh, $thuViec, &$stt) {
            foreach ($chuaCo as $employee) {
                $stt++;

                if ($employee->is_new_hire) {
                    // Nhân viên mới: hợp đồng thử việc 2 tháng
                    $from = $employee->joined_at?->copy() ?? now()->subDays(30);
                    $this->makeContract($employee, $thuViec, $stt, $from, $from->copy()->addMonths(2));

                    continue;
                }

                $namLam = (int) ($employee->joined_at?->diffInYears(now()) ?? 1);

                if ($namLam >= 3) {
                    // Lâu năm: hợp đồng không xác định thời hạn
                    $from = $employee->joined_at?->copy()->addYear() ?? now()->subYears(2);
                    $this->makeContract($employee, $khongXacDinh, $stt, $from, null);

                    continue;
                }

                // Còn lại: hợp đồng 12 tháng. Chọn thẳng ngày hết hạn rồi suy
                // ngược ngày bắt đầu — tính xuôi từ ngày ký khiến phần lớn hợp
                // đồng rơi vào cùng một vùng trạng thái.
                $to = match (random_int(1, 10)) {
                    1, 2 => now()->subDays(random_int(10, 300)),   // đã hết hạn
                    3, 4 => now()->addDays(random_int(1, 30)),     // sắp hết hạn
                    default => now()->addDays(random_int(45, 330)), // còn hiệu lực
                };

                $this->makeContract($employee, $xacDinh, $stt, $to->copy()->subMonths(12), $to);
            }
        });
    }

    private function makeContract(
        Employee $employee,
        ContractType $type,
        int $stt,
        \Illuminate\Support\Carbon $from,
        ?\Illuminate\Support\Carbon $to,
    ): void {
        $status = match (true) {
            $to === null => Contract::STATUS_ACTIVE,
            $to->isPast() => Contract::STATUS_EXPIRED,
            $to->diffInDays(now()) <= 30 => Contract::STATUS_EXPIRING,
            default => Contract::STATUS_ACTIVE,
        };

        Contract::create([
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'contract_no' => sprintf('HD-%d-%04d', $from->year, $stt),
            'signed_at' => $from->copy()->subDays(random_int(1, 10)),
            'effective_from' => $from,
            'effective_to' => $to,
            'status' => $status,
            'alert_before_days' => 30,
        ]);
    }

    private function randomName(bool $isNu): string
    {
        $ho = self::HO[array_rand(self::HO)];
        $dem = $isNu
            ? self::DEM_NU[array_rand(self::DEM_NU)]
            : self::DEM_NAM[array_rand(self::DEM_NAM)];
        $ten = $isNu
            ? self::TEN_NU[array_rand(self::TEN_NU)]
            : self::TEN_NAM[array_rand(self::TEN_NAM)];

        return "{$ho} {$dem} {$ten}";
    }

    /**
     * Email dạng ten.ho@techcombank.local, thêm số khi trùng.
     *
     * Trùng tên là chuyện thường với danh sách trăm người, nên phải xử lý thay
     * vì để insert vỡ vì ràng buộc unique.
     */
    private function uniqueEmail(string $name, \Illuminate\Support\Collection $daDung): string
    {
        $parts = preg_split('/\s+/u', $this->boDau($name)) ?: [];
        $ten = mb_strtolower(end($parts) ?: 'nv');
        $ho = mb_strtolower($parts[0] ?? 'x');
        $dem = isset($parts[1]) ? mb_strtolower(mb_substr($parts[1], 0, 1)) : '';

        $base = "{$ten}.{$dem}{$ho}";
        $email = "{$base}@techcombank.local";
        $n = 1;

        while ($daDung->has($email)) {
            $n++;
            $email = "{$base}{$n}@techcombank.local";
        }

        return $email;
    }

    private function boDau(string $text): string
    {
        $from = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ'
            . 'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴÈÉẸẺẼÊỀẾỆỂỄÌÍỊỈĨÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠÙÚỤỦŨƯỪỨỰỬỮỲÝỴỶỸĐ';
        $to = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyyd'
            . 'AAAAAAAAAAAAAAAAAEEEEEEEEEEEIIIIIOOOOOOOOOOOOOOOOOUUUUUUUUUUUYYYYYD';

        return strtr($text, array_combine(
            preg_split('//u', $from, -1, PREG_SPLIT_NO_EMPTY),
            preg_split('//u', $to, -1, PREG_SPLIT_NO_EMPTY),
        ));
    }

    /** Chọn ngẫu nhiên theo trọng số. */
    private function weightedPick(array $weights): string
    {
        $total = array_sum($weights);
        $roll = random_int(1, $total);

        foreach ($weights as $key => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }
}
