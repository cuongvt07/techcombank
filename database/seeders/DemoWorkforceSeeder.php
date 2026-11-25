<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Nâng quy mô nhân sự demo lên ~1.050 người (bảng 1.2 góp ý: 800–1.200 nhân sự).
 *
 * Chạy sau DemoBulkSeeder, trước DemoReportActivitySeeder (seeder đó sinh ghi danh
 * cho mọi nhân viên). Insert theo lô (users, model_has_roles, employees,
 * employee_job_grade) thay vì Model::create từng người: 900+ người qua Eloquent +
 * bcrypt từng mật khẩu mất vài phút, insert lô chỉ vài giây. Mật khẩu băm một lần
 * rồi dùng chung ('password') — chỉ là dữ liệu minh họa.
 *
 * Chạy lại được: chỉ thêm phần còn thiếu so với TARGET, không đụng nhân viên có sẵn.
 */
class DemoWorkforceSeeder extends Seeder
{
    /** Tổng số nhân viên (chưa xóa) sau khi chạy. */
    private const TARGET = 1050;

    private const SEED = 20261125;

    private const CHUNK = 500;

    /** Tỷ trọng nhân sự theo mã đơn vị: chi nhánh và khối kinh doanh đông người nhất. */
    private const DEPT_WEIGHTS = [
        'CN-HN' => 16, 'CN-HCM' => 16, 'RTL' => 13, 'OPS' => 11, 'SME' => 9, 'CRD' => 9,
        'IT' => 7, 'RSK' => 5, 'FIN' => 4, 'MKT' => 3, 'HR' => 3, 'TRN' => 2, 'LEG' => 2,
    ];

    private const HO = [
        'Nguyễn', 'Nguyễn', 'Nguyễn', 'Trần', 'Trần', 'Lê', 'Lê', 'Phạm', 'Hoàng', 'Huỳnh', 'Phan',
        'Vũ', 'Võ', 'Đặng', 'Bùi', 'Đỗ', 'Hồ', 'Ngô', 'Dương', 'Lý', 'Đinh', 'Trịnh', 'Mai', 'Cao', 'Tạ',
    ];

    private const DEM_NAM = ['Văn', 'Hữu', 'Đức', 'Quang', 'Minh', 'Thanh', 'Công', 'Xuân', 'Trung', 'Gia', 'Hoàng', 'Tuấn'];

    private const DEM_NU = ['Thị', 'Ngọc', 'Thu', 'Hồng', 'Kim', 'Mai', 'Phương', 'Thanh', 'Bích', 'Khánh', 'Diệu', 'Thùy'];

    private const TEN_NAM = [
        'An', 'Bình', 'Cường', 'Dũng', 'Đạt', 'Giang', 'Hải', 'Hùng', 'Khoa', 'Khang', 'Lâm', 'Long',
        'Nam', 'Nghĩa', 'Phong', 'Phúc', 'Quân', 'Sơn', 'Tài', 'Thắng', 'Thành', 'Trung', 'Tuấn', 'Việt', 'Vinh',
    ];

    private const TEN_NU = [
        'Anh', 'Chi', 'Dung', 'Giang', 'Hà', 'Hằng', 'Hạnh', 'Hoa', 'Hương', 'Huyền', 'Lan', 'Linh', 'Ly',
        'Mai', 'My', 'Nga', 'Ngân', 'Nhung', 'Oanh', 'Phương', 'Quỳnh', 'Tâm', 'Thảo', 'Trang', 'Vân', 'Yến',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Bỏ qua DemoWorkforceSeeder: không chạy dữ liệu demo trên production.');

            return;
        }

        $need = self::TARGET - DB::table('employees')->whereNull('deleted_at')->count();
        if ($need <= 0) {
            $this->command?->info('DemoWorkforceSeeder: đã đủ ' . self::TARGET . ' nhân viên, bỏ qua.');

            return;
        }

        mt_srand(self::SEED);

        $departments = DB::table('departments')->whereNull('deleted_at')->whereNotNull('parent_id')
            ->orderBy('id')->get(['id', 'code']);
        if ($departments->isEmpty()) {
            $this->command?->warn('DemoWorkforceSeeder: chưa có phòng ban, chạy DemoDataSeeder/DemoBulkSeeder trước.');

            return;
        }

        $deptWeights = $departments->mapWithKeys(fn ($d) => [$d->id => self::DEPT_WEIGHTS[$d->code] ?? 3])->all();
        $titlesByDept = DB::table('job_titles')->whereNull('deleted_at')->get(['id', 'department_id'])->groupBy('department_id');
        $allTitles = $titlesByDept->flatten(1)->pluck('id')->all();
        $grades = DB::table('job_grades')->whereNull('deleted_at')->pluck('id', 'code');
        $gradeWeights = array_intersect_key(['G1' => 62, 'G3' => 27, 'G5' => 9, 'G8' => 2], $grades->all());

        $usedEmails = DB::table('users')->pluck('email')->flip()->all();
        $password = Hash::make('password');
        $now = CarbonImmutable::now('UTC');
        $nowText = $now->format('Y-m-d H:i:s');

        // 1. Tài khoản đăng nhập
        $people = [];
        for ($i = 0; $i < $need; $i++) {
            $female = mt_rand(0, 1) === 1;
            $name = $this->randomName($female);
            $email = $this->uniqueEmail($name, $usedEmails);
            $usedEmails[$email] = true;

            // Thâm niên: rải 7 năm, ~4% vào trong 60 ngày gần đây (thử việc)
            $daysAgo = mt_rand(1, 100) <= 4 ? mt_rand(3, 60) : mt_rand(61, 2500);
            $resigned = $daysAgo > 60 && mt_rand(1, 100) <= 3;

            $deptId = (int) $this->weightedPick($deptWeights);
            $titles = $titlesByDept->get($deptId);

            $people[] = [
                'name' => $name,
                'email' => $email,
                'female' => $female,
                'department_id' => $deptId,
                'job_title_id' => $titles ? $titles[mt_rand(0, $titles->count() - 1)]->id : ($allTitles ? $allTitles[mt_rand(0, count($allTitles) - 1)] : null),
                'job_grade_id' => $gradeWeights ? $grades[$this->weightedPick($gradeWeights)] : null,
                'joined_at' => $now->setTimezone('Asia/Ho_Chi_Minh')->subDays($daysAgo)->toDateString(),
                'status' => $resigned ? 'resigned' : ($daysAgo <= 60 ? 'probation' : 'official'),
                'resigned_at' => $resigned ? $now->subDays(mt_rand(5, min(400, $daysAgo - 30)))->toDateString() : null,
                'is_new_hire' => $daysAgo <= 60,
            ];
        }

        DB::transaction(function () use ($people, $password, $nowText) {
            foreach (array_chunk($people, self::CHUNK) as $chunk) {
                DB::table('users')->insert(array_map(fn ($p) => [
                    'name' => $p['name'],
                    'email' => $p['email'],
                    'email_verified_at' => $nowText,
                    'password' => $password,
                    'status' => $p['status'] === 'resigned' ? User::STATUS_DISABLED : User::STATUS_ACTIVE,
                    'created_at' => $nowText,
                    'updated_at' => $nowText,
                ], $chunk));
            }

            $userIds = DB::table('users')->whereIn('email', array_column($people, 'email'))->pluck('id', 'email');

            // 2. Vai trò nhân viên (bảng của spatie/permission)
            $roleId = DB::table('roles')->where('name', RoleName::EMPLOYEE->value)->value('id');
            if ($roleId) {
                $morph = (new User)->getMorphClass();
                foreach (array_chunk($people, self::CHUNK) as $chunk) {
                    DB::table('model_has_roles')->insertOrIgnore(array_map(fn ($p) => [
                        'role_id' => $roleId,
                        'model_type' => $morph,
                        'model_id' => $userIds[$p['email']],
                    ], $chunk));
                }
            }

            // 3. Hồ sơ nhân sự: mã NV theo id tài khoản như DemoBulkSeeder
            foreach (array_chunk($people, self::CHUNK) as $chunk) {
                DB::table('employees')->insert(array_map(fn ($p) => [
                    'employee_code' => 'NV' . str_pad((string) $userIds[$p['email']], 6, '0', STR_PAD_LEFT),
                    'user_id' => $userIds[$p['email']],
                    'full_name' => $p['name'],
                    'email' => $p['email'],
                    'phone' => '09' . mt_rand(10000000, 99999999),
                    'gender' => $p['female'] ? 'female' : 'male',
                    'department_id' => $p['department_id'],
                    'job_title_id' => $p['job_title_id'],
                    'job_grade_id' => $p['job_grade_id'],
                    'employment_status' => $p['status'],
                    'joined_at' => $p['joined_at'],
                    'resigned_at' => $p['resigned_at'],
                    'is_new_hire' => $p['is_new_hire'],
                    'created_at' => $nowText,
                    'updated_at' => $nowText,
                ], $chunk));
            }

            // 4. Bảng nối cấp bậc (nhân viên có thể nhiều cấp bậc; ở đây mỗi người một)
            $employees = DB::table('employees')->whereIn('user_id', $userIds->values())->whereNotNull('job_grade_id')
                ->get(['id', 'job_grade_id']);
            foreach ($employees->chunk(self::CHUNK) as $chunk) {
                DB::table('employee_job_grade')->insertOrIgnore($chunk->map(fn ($e) => [
                    'employee_id' => $e->id, 'job_grade_id' => $e->job_grade_id,
                ])->values()->all());
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('DemoWorkforceSeeder: thêm ' . count($people) . ' nhân viên (tổng ' . self::TARGET . ').');
    }

    private function randomName(bool $female): string
    {
        $pick = fn (array $list) => $list[mt_rand(0, count($list) - 1)];

        return $pick(self::HO) . ' ' . $pick($female ? self::DEM_NU : self::DEM_NAM) . ' ' . $pick($female ? self::TEN_NU : self::TEN_NAM);
    }

    /** Email dạng ten.dho@techcombank.local, thêm số khi trùng. */
    private function uniqueEmail(string $name, array $used): string
    {
        $parts = preg_split('/\s+/u', $this->stripAccents($name)) ?: [];
        $base = mb_strtolower(end($parts) ?: 'nv') . '.' . (isset($parts[1]) ? mb_strtolower(mb_substr($parts[1], 0, 1)) : '')
            . mb_strtolower($parts[0] ?? 'x');
        $email = "{$base}@techcombank.local";
        for ($n = 2; isset($used[$email]); $n++) {
            $email = "{$base}{$n}@techcombank.local";
        }

        return $email;
    }

    private function stripAccents(string $text): string
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

    private function weightedPick(array $weights): int|string
    {
        $roll = mt_rand(1, (int) array_sum($weights));
        foreach ($weights as $key => $w) {
            $roll -= $w;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }
}
