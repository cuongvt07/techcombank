<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Course;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAccessRule;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\JobGrade;
use App\Models\JobTitle;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\Announcement;
use App\Models\Setting;
use App\Models\SupportContact;
use App\Models\User;
use App\Services\CourseAssignmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Dữ liệu mẫu để chạy thử toàn hệ thống: cây tổ chức, tài khoản các vai trò,
 * một khoá học có đủ 3 dạng bài (văn bản, video, trắc nghiệm) và rule phân quyền.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $departments = $this->createDepartments();
        $grades = $this->createGrades();
        $titles = $this->createJobTitles($departments);

        $this->createSettings();
        // Kho tài nguyên: dựng sẵn bộ thư mục mặc định
        app(\App\Services\FileStorageService::class)->ensureSystemFolders();
        $this->createContractTypes();
        $this->createAdminUsers($departments, $titles, $grades);

        $employees = $this->createEmployees($departments, $titles, $grades);
        $this->createContracts($employees);

        $course = $this->createCourse($departments['operations']);
        $this->createDocuments($departments, $course);
        $this->createSupportContacts($departments);
        $this->createAnnouncements($employees);

        // Gán khoá học theo điều kiện đã cấu hình
        $assignment = app(CourseAssignmentService::class);

        foreach ($employees as $employee) {
            $assignment->syncForEmployee($employee);
        }
    }

    /** @return array<string, Department> */
    private function createDepartments(): array
    {
        $hq = Department::create(['code' => 'HQ', 'name' => 'Hội sở', 'sort_order' => 0]);

        return [
            'hq' => $hq,
            'hr' => Department::create(['code' => 'HR', 'name' => 'Phòng Nhân sự', 'parent_id' => $hq->id, 'sort_order' => 1]),
            'training' => Department::create(['code' => 'TRN', 'name' => 'Phòng Đào tạo', 'parent_id' => $hq->id, 'sort_order' => 2]),
            'operations' => Department::create(['code' => 'OPS', 'name' => 'Khối Vận hành', 'parent_id' => $hq->id, 'sort_order' => 3]),
            'credit' => Department::create(['code' => 'CRD', 'name' => 'Khối Tín dụng', 'parent_id' => $hq->id, 'sort_order' => 4]),
            'it' => Department::create(['code' => 'IT', 'name' => 'Khối Công nghệ', 'parent_id' => $hq->id, 'sort_order' => 5]),
        ];
    }

    /** @return array<string, JobGrade> */
    private function createGrades(): array
    {
        return [
            'staff' => JobGrade::create(['code' => 'G1', 'name' => 'Nhân viên', 'level' => 1]),
            'senior' => JobGrade::create(['code' => 'G3', 'name' => 'Chuyên viên chính', 'level' => 3]),
            'manager' => JobGrade::create(['code' => 'G5', 'name' => 'Quản lý', 'level' => 5]),
            'director' => JobGrade::create(['code' => 'G8', 'name' => 'Giám đốc khối', 'level' => 8]),
        ];
    }

    /** @return array<string, JobTitle> */
    private function createJobTitles(array $departments): array
    {
        return [
            'hr_specialist' => JobTitle::create(['code' => 'JT-HR', 'name' => 'Chuyên viên Nhân sự', 'department_id' => $departments['hr']->id]),
            'trainer' => JobTitle::create(['code' => 'JT-TRN', 'name' => 'Chuyên viên Đào tạo', 'department_id' => $departments['training']->id]),
            'teller' => JobTitle::create(['code' => 'JT-OPS', 'name' => 'Giao dịch viên', 'department_id' => $departments['operations']->id]),
            'credit_officer' => JobTitle::create(['code' => 'JT-CRD', 'name' => 'Chuyên viên Tín dụng', 'department_id' => $departments['credit']->id]),
            'engineer' => JobTitle::create(['code' => 'JT-IT', 'name' => 'Kỹ sư hệ thống', 'department_id' => $departments['it']->id]),
        ];
    }

    private function createSettings(): void
    {
        Setting::set('app.display_name', 'LMS Techcombank', 'string', 'general');
        Setting::set('contract.alert_before_days', '30', 'integer', 'contract');
        Setting::set('security.max_concurrent_devices', '2', 'integer', 'security');
        Setting::set('security.watermark_enabled', '1', 'boolean', 'security');

        // Nội dung màn chào mừng nhân viên mới (spec 4.4)
        Setting::set('company.name', 'Techcombank', 'string', 'company');
        Setting::set(
            'company.intro',
            'Ngân hàng TMCP Kỹ thương Việt Nam (Techcombank) thành lập năm 1993, '
                . 'hoạt động trên toàn quốc với mạng lưới chi nhánh và phòng giao dịch rộng khắp. '
                . 'Rất vui được đồng hành cùng bạn trong chặng đường sắp tới.',
            'string',
            'company'
        );
        Setting::set(
            'company.values',
            "Khách hàng là trọng tâm — mọi quyết định xuất phát từ lợi ích và trải nghiệm của khách hàng.
"
                . "Đổi mới và sáng tạo — chủ động cải tiến quy trình, ứng dụng công nghệ vào vận hành.
"
                . "Hợp tác vì mục tiêu chung — phối hợp giữa các khối, chia sẻ thông tin minh bạch.
"
                . "Phát triển bản thân — học hỏi liên tục, sẵn sàng nhận phản hồi để tiến bộ.",
            'string',
            'company'
        );
        Setting::set(
            'company.onboarding_process',
            "1. Tuần đầu — Nhận tài khoản hệ thống, thẻ nhân viên và thiết bị làm việc từ bộ phận IT.
"
                . "2. Tuần đầu — Hoàn thành các khóa đào tạo bắt buộc trong mục Khóa học của tôi.
"
                . "3. Tuần thứ hai — Gặp quản lý trực tiếp để thống nhất mục tiêu 90 ngày đầu.
"
                . "4. Tháng thứ hai — Tham gia buổi giới thiệu văn hóa doanh nghiệp do Phòng Nhân sự tổ chức.
"
                . "5. Cuối kỳ thử việc — Buổi đánh giá kết quả cùng quản lý và đại diện Phòng Nhân sự.

"
                . "Có vướng mắc ở bước nào, liên hệ đầu mối tương ứng ở cột bên phải.",
            'string',
            'company'
        );
    }

    private function createContractTypes(): void
    {
        ContractType::create(['code' => 'HDXD', 'name' => 'Xác định thời hạn', 'default_duration_months' => 12]);
        ContractType::create(['code' => 'HDKXD', 'name' => 'Không xác định thời hạn', 'default_duration_months' => null]);
        ContractType::create(['code' => 'HDTV', 'name' => 'Thử việc', 'default_duration_months' => 2]);
    }

    /** Hợp đồng mẫu, cố ý có một bản sắp hết hạn để kiểm chứng luồng cảnh báo (spec 3.1.3). */
    private function createContracts(array $employees): void
    {
        $fixedTerm = ContractType::where('code', 'HDXD')->first();
        $openEnded = ContractType::where('code', 'HDKXD')->first();
        $probation = ContractType::where('code', 'HDTV')->first();

        foreach ($employees as $index => $employee) {
            $isNew = $employee->is_new_hire;

            Contract::create([
                'contract_no' => sprintf('HD-2026-%04d', $index + 1),
                'employee_id' => $employee->id,
                'contract_type_id' => $isNew ? $probation->id : ($index % 2 === 0 ? $fixedTerm->id : $openEnded->id),
                'signed_at' => $employee->joined_at,
                'effective_from' => $employee->joined_at ?? now()->subYear(),
                // Nhân viên đầu tiên có hợp đồng còn 20 ngày => lọt vào danh sách cảnh báo
                'effective_to' => match (true) {
                    $index === 0 => now()->addDays(20),
                    $isNew => now()->addMonths(2),
                    $index % 2 === 0 => now()->addMonths(8),
                    default => null,
                },
                'status' => Contract::STATUS_ACTIVE,
            ]);
        }
    }

    private function createAdminUsers(array $departments, array $titles, array $grades): void
    {
        // Hai tài khoản quản trị: một ở phòng Đào tạo, một ở phòng Nhân sự.
        // Cùng vai trò ADMIN nên quyền hạn như nhau — khác nhau ở phòng ban
        // để dữ liệu demo phản ánh đúng sơ đồ tổ chức.
        $accounts = [
            ['admin@techcombank.local', 'Nguyễn Quản Trị', RoleName::ADMIN, 'training', 'trainer', 'director'],
            ['hr@techcombank.local', 'Lê Thu Hà', RoleName::ADMIN, 'hr', 'hr_specialist', 'manager'],
        ];

        foreach ($accounts as [$email, $name, $role, $dept, $title, $grade]) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ]);

            $user->assignRole($role->value);

            Employee::create([
                'employee_code' => 'NV' . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                'user_id' => $user->id,
                'full_name' => $name,
                'email' => $email,
                'department_id' => $departments[$dept]->id,
                'job_title_id' => $titles[$title]->id,
                'job_grade_id' => $grades[$grade]->id,
                'employment_status' => Employee::STATUS_OFFICIAL,
                'joined_at' => now()->subYears(2),
                'is_new_hire' => false,
            ]);
        }
    }

    /** @return Employee[] */
    private function createEmployees(array $departments, array $titles, array $grades): array
    {
        $rows = [
            ['Nguyễn Văn Minh', 'minh.nv@techcombank.local', 'operations', 'teller', 'staff', false],
            ['Phạm Quốc Huy', 'huy.pq@techcombank.local', 'credit', 'credit_officer', 'senior', false],
            ['Trần Gia Bảo', 'bao.tg@techcombank.local', 'it', 'engineer', 'senior', false],
            ['Đỗ Minh Anh', 'anh.dm@techcombank.local', 'operations', 'teller', 'staff', true],
            ['Vũ Hải Yến', 'yen.vh@techcombank.local', 'credit', 'credit_officer', 'staff', true],
        ];

        $employees = [];

        foreach ($rows as [$name, $email, $dept, $title, $grade, $isNew]) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ]);

            $user->assignRole(RoleName::EMPLOYEE->value);

            $employees[] = Employee::create([
                'employee_code' => 'NV' . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                'user_id' => $user->id,
                'full_name' => $name,
                'email' => $email,
                'department_id' => $departments[$dept]->id,
                'job_title_id' => $titles[$title]->id,
                'job_grade_id' => $grades[$grade]->id,
                'employment_status' => $isNew ? Employee::STATUS_PROBATION : Employee::STATUS_OFFICIAL,
                'joined_at' => $isNew ? now()->subDays(5) : now()->subYear(),
                'is_new_hire' => $isNew,
            ]);
        }

        return $employees;
    }

    private function createCourse(Department $owner): Course
    {
        $course = Course::create([
            'code' => 'C-ATTT',
            'title' => 'An toàn thông tin cho nhân viên ngân hàng',
            'slug' => 'an-toan-thong-tin',
            'description' => 'Khóa học bắt buộc về nhận diện rủi ro an toàn thông tin, bảo mật dữ liệu khách hàng và quy trình xử lý sự cố.',
            'owner_department_id' => $owner->id,
            'sequential' => true,
            'status' => Course::STATUS_PUBLISHED,
            'published_at' => now(),
            'duration_days' => 30,
            'issue_certificate' => true,
        ]);

        Lesson::create([
            'course_id' => $course->id,
            'title' => 'Tổng quan rủi ro an toàn thông tin',
            'content_type' => Lesson::TYPE_TEXT,
            'content_html' => '<h3>Ba nhóm rủi ro an toàn thông tin</h3>
<p>Trong hoạt động ngân hàng, rủi ro an toàn thông tin được chia thành ba nhóm chính:</p>
<ol>
<li><strong>Lộ lọt dữ liệu khách hàng</strong>: thông tin cá nhân, số tài khoản, lịch sử giao dịch bị tiết lộ ra ngoài. Nguyên nhân thường gặp là gửi nhầm email, dùng USB cá nhân để sao chép dữ liệu, hoặc trao đổi thông tin khách hàng qua ứng dụng nhắn tin không được phê duyệt.</li>
<li><strong>Tấn công lừa đảo qua email (phishing)</strong>: kẻ tấn công giả danh lãnh đạo, đối tác hoặc bộ phận IT để yêu cầu nhân viên cung cấp mật khẩu, bấm vào liên kết lạ hoặc chuyển tiền gấp. Dấu hiệu nhận biết: địa chỉ người gửi sai lệch một vài ký tự, nội dung tạo áp lực thời gian, tệp đính kèm không rõ nguồn gốc.</li>
<li><strong>Truy cập trái phép hệ thống</strong>: dùng chung tài khoản, đặt mật khẩu yếu, không khóa màn hình khi rời chỗ, hoặc để người ngoài mượn thiết bị làm việc.</li>
</ol>
<h3>Nguyên tắc xử lý khi phát hiện sự cố</h3>
<p>Báo ngay cho bộ phận An ninh thông tin trong vòng 1 giờ kể từ khi phát hiện. Không tự ý xóa bằng chứng, không chuyển tiếp email nghi ngờ cho đồng nghiệp. Giữ nguyên hiện trạng thiết bị cho đến khi có hướng dẫn.</p>',
            'sort_order' => 0,
            'estimated_minutes' => 15,
        ]);

        // Video nhúng từ nguồn ngoài, gắn vào bài học qua document
        $videoDoc = Document::create([
            'title' => 'Video: Nhận diện email lừa đảo',
            'slug' => 'video-nhan-dien-email-lua-dao',
            'kind' => Document::KIND_VIDEO,
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'video_provider' => 'youtube',
            'video_embed_id' => 'dQw4w9WgXcQ',
            'duration_seconds' => 720,
            'confidentiality' => Document::CONF_INTERNAL,
            'status' => Document::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Lesson::create([
            'course_id' => $course->id,
            'title' => 'Video: Nhận diện email lừa đảo',
            'content_type' => Lesson::TYPE_VIDEO,
            'document_id' => $videoDoc->id,
            'sort_order' => 1,
            'estimated_minutes' => 12,
            'min_watch_percent' => 80,
        ]);

        $quiz = $this->createQuiz($course);

        Lesson::create([
            'course_id' => $course->id,
            'title' => 'Bài kiểm tra cuối khóa',
            'content_type' => Lesson::TYPE_QUIZ,
            'quiz_id' => $quiz->id,
            'sort_order' => 2,
            'estimated_minutes' => 20,
        ]);

        // Onboarding: khoá tự gán cho nhân viên mới (spec 4.4)
        $onboarding = Course::create([
            'code' => 'C-ONB',
            'title' => 'Onboarding nhân viên mới',
            'slug' => 'onboarding-nhan-vien-moi',
            'description' => 'Giới thiệu công ty, quy trình nội bộ và đầu mối liên hệ theo từng phòng ban.',
            'sequential' => false,
            'is_onboarding' => true,
            'status' => Course::STATUS_PUBLISHED,
            'published_at' => now(),
            'duration_days' => 14,
        ]);

        Lesson::create([
            'course_id' => $onboarding->id,
            'title' => 'Giới thiệu về Techcombank',
            'content_type' => Lesson::TYPE_TEXT,
            'content_html' => '<h3>Giới thiệu chung</h3>
<p>Techcombank là ngân hàng thương mại cổ phần được thành lập năm 1993, hoạt động trên toàn quốc với mạng lưới chi nhánh và phòng giao dịch rộng khắp.</p>
<h3>Giá trị cốt lõi</h3>
<ul>
<li><strong>Khách hàng là trọng tâm</strong>: mọi quyết định xuất phát từ lợi ích và trải nghiệm của khách hàng.</li>
<li><strong>Đổi mới và sáng tạo</strong>: chủ động cải tiến quy trình, ứng dụng công nghệ vào vận hành.</li>
<li><strong>Hợp tác vì mục tiêu chung</strong>: phối hợp giữa các khối, chia sẻ thông tin minh bạch.</li>
<li><strong>Phát triển bản thân</strong>: học hỏi liên tục, nhận phản hồi để tiến bộ.</li>
</ul>
<h3>Quy tắc ứng xử với khách hàng</h3>
<p>Chào hỏi trong vòng 30 giây kể từ khi khách bước vào quầy. Lắng nghe hết vấn đề trước khi đưa giải pháp. Không hứa điều nằm ngoài thẩm quyền. Với khiếu nại, ghi nhận đầy đủ và thông báo mốc thời gian phản hồi cụ thể cho khách.</p>',
            'sort_order' => 0,
            'estimated_minutes' => 10,
        ]);

        return $course;
    }

    private function createQuiz(Course $course): Quiz
    {
        $quiz = Quiz::create([
            'title' => 'Kiểm tra An toàn thông tin',
            'course_id' => $course->id,
            'duration_minutes' => 20,
            'pass_score' => 70,
            'max_attempts' => 3,
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'status' => Quiz::STATUS_PUBLISHED,
        ]);

        // Mỗi câu kèm giải thích: đây là phần trợ lý AI dùng để giảng lại kiến
        // thức cho người ôn tập (spec 4.3), không phải để đọc đáp án hộ.
        $bank = [
            [
                'Dấu hiệu nào sau đây thường gặp ở email lừa đảo (phishing)?',
                'Email lừa đảo thường tạo áp lực thời gian và yêu cầu cung cấp thông tin nhạy cảm ngay lập tức. '
                    . 'Ngân hàng và bộ phận IT không bao giờ hỏi mật khẩu qua email. '
                    . 'Ba dấu hiệu cần nhớ: địa chỉ người gửi sai lệch vài ký tự, nội dung hối thúc gấp, '
                    . 'và tệp đính kèm hoặc liên kết không rõ nguồn gốc. Khi nghi ngờ, báo bộ phận An ninh '
                    . 'thông tin thay vì tự bấm thử.',
                ['Yêu cầu cung cấp mật khẩu gấp', true],
                ['Email từ đồng nghiệp đã xác minh', false],
                ['Thông báo lịch họp nội bộ', false],
            ],
            [
                'Khi phát hiện rò rỉ dữ liệu khách hàng, việc đầu tiên cần làm là gì?',
                'Nguyên tắc là báo trước, xử lý sau. Phải thông báo cho bộ phận An ninh thông tin trong vòng '
                    . '1 giờ kể từ khi phát hiện. Tự khắc phục trước khi báo sẽ làm mất dấu vết phục vụ điều tra, '
                    . 'còn chia sẻ rộng cho đồng nghiệp có thể khiến dữ liệu lộ thêm. Giữ nguyên hiện trạng '
                    . 'thiết bị cho tới khi có hướng dẫn chính thức.',
                ['Báo cáo ngay cho bộ phận an ninh thông tin', true],
                ['Tự khắc phục rồi báo sau', false],
                ['Chia sẻ với đồng nghiệp để cùng xử lý', false],
            ],
            [
                'Mật khẩu đạt yêu cầu an toàn cần có đặc điểm nào?',
                'Mật khẩu an toàn cần đủ độ dài và kết hợp chữ hoa, chữ thường, số và ký tự đặc biệt. '
                    . 'Thông tin dễ đoán như ngày sinh, số điện thoại hay tên người thân đều nằm trong danh sách '
                    . 'kẻ tấn công thử đầu tiên. Đặc biệt không dùng chung một mật khẩu cho nhiều hệ thống: '
                    . 'một nơi bị lộ thì toàn bộ các nơi còn lại mất an toàn theo.',
                ['Đủ độ dài, kết hợp nhiều loại ký tự', true],
                ['Dễ nhớ như ngày sinh', false],
                ['Dùng chung cho nhiều hệ thống', false],
            ],
        ];

        foreach ($bank as $index => $row) {
            $content = array_shift($row);
            $explanation = array_shift($row);

            $question = Question::create([
                'quiz_id' => $quiz->id,
                'content' => $content,
                'explanation' => $explanation,
                'type' => Question::TYPE_SINGLE,
                'score' => 1,
                'sort_order' => $index,
            ]);

            foreach ($row as $optionIndex => [$optionContent, $isCorrect]) {
                QuestionOption::create([
                    'question_id' => $question->id,
                    'content' => $optionContent,
                    'is_correct' => $isCorrect,
                    'sort_order' => $optionIndex,
                ]);
            }
        }

        return $quiz;
    }

    private function createDocuments(array $departments, Course $course): void
    {
        $category = DocumentCategory::create(['code' => 'POLICY', 'name' => 'Chính sách & quy trình']);

        $documents = [
            ['Quy trình xử lý sự cố an toàn thông tin', Document::CONF_CONFIDENTIAL, false],
            ['Sổ tay nhân viên mới', Document::CONF_INTERNAL, true],
            ['Chính sách bảo mật dữ liệu khách hàng', Document::CONF_RESTRICTED, false],
        ];

        foreach ($documents as [$title, $confidentiality, $allowDownload]) {
            $document = Document::create([
                'title' => $title,
                'slug' => Str::slug($title),
                'kind' => Document::KIND_FILE,
                'document_category_id' => $category->id,
                'owner_department_id' => $departments['training']->id,
                'confidentiality' => $confidentiality,
                'allow_download' => $allowDownload,
                'enable_watermark' => true,
                'status' => Document::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            // Toàn bộ nhân viên đang làm việc được xem; tải xuống theo cờ của tài liệu
            DocumentAccessRule::create([
                'document_id' => $document->id,
                'can_view' => true,
                'can_download' => $allowDownload,
                'priority' => 0,
            ]);
        }

        // Gắn file PDF thật cho tài liệu đầu tiên để xem thử trình đọc trong trang.
        // Không có file thì bài học dạng tài liệu chỉ là cái vỏ.
        $this->attachDemoPdf();

        // Tài liệu mật chỉ mở cho cấp quản lý trở lên (level >= 5)
        $restricted = Document::where('confidentiality', Document::CONF_RESTRICTED)->first();

        if ($restricted) {
            DocumentAccessRule::where('document_id', $restricted->id)->delete();

            DocumentAccessRule::create([
                'document_id' => $restricted->id,
                'min_grade_level' => 5,
                'can_view' => true,
                'can_download' => false,
                'priority' => 10,
            ]);
        }
    }

    private function createSupportContacts(array $departments): void
    {
        SupportContact::create([
            'topic' => 'Hợp đồng & chế độ',
            'department_id' => null,
            'contact_name' => 'Phòng Nhân sự',
            'email' => 'hr@techcombank.local',
            'sort_order' => 0,
        ]);

        SupportContact::create([
            'topic' => 'Nội dung bài giảng',
            'department_id' => null,
            'contact_name' => 'Phòng Đào tạo',
            'email' => 'daotao@techcombank.local',
            'sort_order' => 1,
        ]);

        SupportContact::create([
            'topic' => 'Sự cố kỹ thuật',
            'department_id' => $departments['it']->id,
            'contact_name' => 'Khối Công nghệ',
            'email' => 'it@techcombank.local',
            'sort_order' => 2,
        ]);
    }

    /**
     * Sự kiện mẫu: vài cái toàn công ty, vài cái gán riêng để thấy rõ thứ tự
     * ưu tiên bên site người dùng.
     *
     * @param  Employee[]  $employees
     */
    private function createAnnouncements(array $employees): void
    {
        $adminId = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->value('id');
        $first = $employees[0] ?? null;
        $second = $employees[1] ?? null;

        $events = [
            // --- Toàn công ty ---
            [
                'title' => 'Hội nghị tổng kết quý III',
                'content' => "Toàn thể cán bộ nhân viên tham dự hội nghị tổng kết hoạt động quý III và triển khai kế hoạch quý IV.

Đề nghị các phòng ban chuẩn bị báo cáo trước ngày họp.",
                'type' => Announcement::TYPE_GENERAL,
                'employee_id' => null,
                'starts_at' => now()->addDays(6)->setTime(8, 30),
                'ends_at' => now()->addDays(6)->setTime(11, 30),
                'location' => 'Hội trường tầng 12 — Trụ sở chính',
            ],
            [
                'title' => 'Ban hành quy trình mở tài khoản phiên bản 2026',
                'content' => 'Quy trình mở tài khoản khách hàng cá nhân đã được cập nhật. Nhân viên giao dịch vui lòng đọc kỹ tài liệu trong mục Tài liệu nội bộ trước khi áp dụng.',
                'type' => Announcement::TYPE_DOCUMENT,
                'employee_id' => null,
                'starts_at' => null,
                'ends_at' => null,
                'location' => null,
            ],
            [
                'title' => 'Đang diễn ra: Tuần lễ an toàn thông tin',
                'content' => 'Chuỗi hoạt động nâng cao nhận thức an toàn thông tin dành cho toàn hệ thống. Có phần thi trắc nghiệm nhận quà cuối tuần.',
                'type' => Announcement::TYPE_SYSTEM,
                'employee_id' => null,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(3),
                'location' => 'Trực tuyến — Microsoft Teams',
            ],

            // --- Riêng cá nhân ---
            [
                'title' => 'Phỏng vấn đánh giá cuối kỳ thử việc',
                'content' => "Bạn được mời tham dự buổi đánh giá kết quả thử việc cùng quản lý trực tiếp và đại diện Phòng Nhân sự.

Vui lòng chuẩn bị bản tự đánh giá theo mẫu đã gửi qua email.",
                'type' => Announcement::TYPE_GENERAL,
                'employee_id' => $first?->id,
                'starts_at' => now()->addDays(2)->setTime(14, 0),
                'ends_at' => now()->addDays(2)->setTime(15, 0),
                'location' => 'Phòng họp Sông Hồng — Tầng 5',
            ],
            [
                'title' => 'Nhắc hoàn thành khóa đào tạo bắt buộc',
                'content' => 'Khóa "An toàn thông tin cho nhân viên ngân hàng" của bạn sắp đến hạn. Vui lòng hoàn thành các bài giảng còn lại.',
                'type' => Announcement::TYPE_COURSE,
                'employee_id' => $second?->id,
                'starts_at' => null,
                'ends_at' => null,
                'location' => null,
            ],
        ];

        foreach ($events as $event) {
            Announcement::create($event + [
                'published_at' => now()->subDays(1),
                'created_by' => $adminId,
            ]);
        }
    }

    /**
     * Gắn file PDF mẫu vào tài liệu "Quy trình xử lý sự cố" và tạo một bài học
     * dạng tài liệu trong khóa An toàn thông tin.
     *
     * File đi qua FileStorageService như mọi file khác nên nằm đúng trong kho
     * tài nguyên, không phải đường tắt riêng cho dữ liệu demo.
     */
    private function attachDemoPdf(): void
    {
        $source = storage_path('app/quy-trinh-su-co.pdf');

        if (! is_file($source)) {
            return;
        }

        $document = Document::where('title', 'Quy trình xử lý sự cố an toàn thông tin')->first();

        if (! $document) {
            return;
        }

        $storage = app(\App\Services\FileStorageService::class);

        // storeMergedFile() DI CHUYỂN file nguồn. Chép ra bản tạm trước để chạy
        // lại seeder nhiều lần vẫn được, không mất file mẫu.
        $temp = storage_path('app/' . \Illuminate\Support\Str::uuid() . '.pdf');
        copy($source, $temp);

        $storedFile = $storage->storeMergedFile(
            $temp,
            'quy-trinh-xu-ly-su-co.pdf',
            $storage->systemFolderFor(\App\Services\FileStorageService::PURPOSE_DOCUMENT)?->id,
        );

        $version = \App\Models\DocumentVersion::create([
            'document_id' => $document->id,
            'version_no' => 1,
            'stored_file_id' => $storedFile->id,
            'change_note' => 'Bản đầu tiên',
            'original_filename' => $storedFile->name,
            'mime_type' => $storedFile->mime_type,
            'size_bytes' => $storedFile->size_bytes,
            'checksum' => $storedFile->checksum,
        ]);

        $document->update(['current_version_id' => $version->id]);

        // Bài học dạng tài liệu trong khóa An toàn thông tin
        $course = Course::where('title', 'like', '%An toàn thông tin%')->first();

        if ($course) {
            Lesson::create([
                'course_id' => $course->id,
                'title' => 'Tài liệu: Quy trình xử lý sự cố',
                'summary' => 'Đọc kỹ quy trình bốn bước trước khi làm bài kiểm tra.',
                'content_type' => Lesson::TYPE_DOCUMENT,
                'document_id' => $document->id,
                'sort_order' => 3,
                'is_required' => true,
                'estimated_minutes' => 10,
            ]);

            // Tổng số bài đổi thì tiến độ đã ghi phải tính lại cho khớp
            foreach ($course->enrollments as $enrollment) {
                app(\App\Services\ProgressService::class)->recalculate($enrollment);
            }
        }
    }
}
