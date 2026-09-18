<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseAssignmentRule;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\JobGrade;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Bộ khóa học mẫu đầy đủ nội dung, dùng được ngay không cần soạn thêm.
 *
 * Mỗi khóa có đủ ba dạng bài (đọc, video, kiểm tra) với nội dung thật chứ không
 * phải chữ lấp chỗ trống — để trợ lý AI có dữ liệu trả lời và người nghiệm thu
 * đọc được nội dung có nghĩa.
 *
 * Video dùng link YouTube công khai về chủ đề tương ứng. Link có thể chết theo
 * thời gian; lúc đó vào Quản trị > Bộ tài liệu ban hành để thay link khác.
 */
class DemoCourseSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->value('id');
        $departments = Department::all()->keyBy('code');
        $grades = JobGrade::all()->keyBy('code');
        $category = DocumentCategory::first();

        foreach ($this->courseDefinitions() as $definition) {
            if (Course::where('slug', $definition['slug'])->exists()) {
                continue;
            }

            $this->buildCourse($definition, $adminId, $departments, $grades, $category);
        }
    }

    private function buildCourse(
        array $def,
        ?int $adminId,
        $departments,
        $grades,
        ?DocumentCategory $category,
    ): void {
        $owner = $departments->get($def['owner'] ?? 'TRN') ?? $departments->first();

        $course = Course::create([
            'code' => $def['code'],
            'title' => $def['title'],
            'slug' => $def['slug'],
            'description' => $def['description'],
            'owner_department_id' => $owner?->id,
            'is_onboarding' => $def['onboarding'] ?? false,
            'sequential' => $def['sequential'] ?? true,
            'issue_certificate' => $def['certificate'] ?? true,
            'duration_days' => $def['duration_days'] ?? 30,
            'status' => Course::STATUS_PUBLISHED,
            'published_at' => now()->subDays(random_int(10, 120)),
            'created_by' => $adminId,
        ]);

        $order = 0;

        foreach ($def['lessons'] as $lesson) {
            $order++;

            match ($lesson['type']) {
                'text' => $this->makeTextLesson($course, $lesson, $order),
                'video' => $this->makeVideoLesson($course, $lesson, $order, $category),
                'quiz' => $this->makeQuizLesson($course, $lesson, $order, $adminId),
            };
        }

        $this->makeAssignmentRule($course, $def, $departments, $grades);
    }

    private function makeTextLesson(Course $course, array $lesson, int $order): void
    {
        Lesson::create([
            'course_id' => $course->id,
            'title' => $lesson['title'],
            'summary' => $lesson['summary'] ?? null,
            'content_type' => Lesson::TYPE_TEXT,
            'content_html' => $lesson['content'],
            'sort_order' => $order,
            'estimated_minutes' => $lesson['minutes'] ?? 15,
            'is_required' => true,
        ]);
    }

    private function makeVideoLesson(Course $course, array $lesson, int $order, ?DocumentCategory $category): void
    {
        $document = Document::create([
            'title' => $lesson['title'],
            'slug' => Str::slug($lesson['title']) . '-' . Str::random(6),
            'description' => $lesson['summary'] ?? null,
            'document_category_id' => $category?->id,
            'kind' => Document::KIND_VIDEO,
            'video_url' => 'https://www.youtube.com/watch?v=' . $lesson['youtube'],
            'video_provider' => 'youtube',
            'video_embed_id' => $lesson['youtube'],
            'duration_seconds' => ($lesson['minutes'] ?? 10) * 60,
            'confidentiality' => Document::CONF_INTERNAL,
            'status' => Document::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Lesson::create([
            'course_id' => $course->id,
            'title' => $lesson['title'],
            'summary' => $lesson['summary'] ?? null,
            'content_type' => Lesson::TYPE_VIDEO,
            'document_id' => $document->id,
            'sort_order' => $order,
            'estimated_minutes' => $lesson['minutes'] ?? 10,
            'min_watch_percent' => 80,
            'is_required' => true,
        ]);
    }

    private function makeQuizLesson(Course $course, array $lesson, int $order, ?int $adminId): void
    {
        $quiz = Quiz::create([
            'title' => $lesson['title'],
            'course_id' => $course->id,
            'description' => $lesson['summary'] ?? null,
            'duration_minutes' => $lesson['minutes'] ?? 20,
            'pass_score' => $lesson['pass_score'] ?? 70,
            'max_attempts' => 3,
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'status' => Quiz::STATUS_PUBLISHED,
            'created_by' => $adminId,
        ]);

        foreach ($lesson['questions'] as $index => $q) {
            $question = Question::create([
                'quiz_id' => $quiz->id,
                'content' => $q['content'],
                // Giải thích là nội dung trợ lý AI dùng để giảng lại cho người
                // ôn tập — bỏ trống thì AI không có gì để hỗ trợ
                'explanation' => $q['explanation'],
                'type' => Question::TYPE_SINGLE,
                'score' => 1,
                'sort_order' => $index,
                'is_active' => true,
            ]);

            foreach ($q['options'] as $optionIndex => [$content, $isCorrect]) {
                QuestionOption::create([
                    'question_id' => $question->id,
                    'content' => $content,
                    'is_correct' => $isCorrect,
                    'sort_order' => $optionIndex,
                ]);
            }
        }

        Lesson::create([
            'course_id' => $course->id,
            'title' => $lesson['title'],
            'content_type' => Lesson::TYPE_QUIZ,
            'quiz_id' => $quiz->id,
            'sort_order' => $order,
            'estimated_minutes' => $lesson['minutes'] ?? 20,
            'is_required' => true,
        ]);
    }

    /**
     * Rule gán khóa tự động.
     *
     * Có rule thì nhân viên mới vào hoặc chuyển phòng sẽ tự nhận khóa, không
     * phải gán tay từng người.
     */
    private function makeAssignmentRule(Course $course, array $def, $departments, $grades): void
    {
        if (empty($def['assign'])) {
            return;
        }

        $assign = $def['assign'];

        CourseAssignmentRule::create([
            'course_id' => $course->id,
            'name' => 'Gán tự động: ' . $course->title,
            'department_id' => isset($assign['dept']) ? $departments->get($assign['dept'])?->id : null,
            'job_grade_id' => isset($assign['grade']) ? $grades->get($assign['grade'])?->id : null,
            // Khóa onboarding nhắm nhân viên đang thử việc
            'employment_status' => ($assign['new_hire'] ?? false)
                ? \App\Models\Employee::STATUS_PROBATION
                : null,
            'include_sub_departments' => true,
            'is_mandatory' => $assign['mandatory'] ?? true,
            'due_days' => $assign['due_days'] ?? 30,
            'is_active' => true,
        ]);
    }

    /**
     * Định nghĩa các khóa học.
     *
     * Nội dung soạn theo nghiệp vụ ngân hàng thật để dùng được ngay khi demo
     * hoặc nghiệm thu.
     */
    private function courseDefinitions(): array
    {
        return [
            $this->khoaPhongChongRuaTien(),
            $this->khoaDichVuKhachHang(),
            $this->khoaTinDung(),
            $this->khoaSanPhamThe(),
            $this->khoaKyNangMem(),
            $this->khoaPhapLyNganHang(),
            $this->khoaChuyenDoiSo(),
            $this->khoaOnboardingVanHoa(),
        ];
    }

    private function khoaPhongChongRuaTien(): array
    {
        return [
            'code' => 'AML-2026',
            'title' => 'Phòng chống rửa tiền (AML)',
            'slug' => 'phong-chong-rua-tien',
            'description' => 'Khóa bắt buộc theo quy định của Ngân hàng Nhà nước: nhận diện giao dịch đáng ngờ, '
                . 'quy trình báo cáo và trách nhiệm của nhân viên giao dịch.',
            'owner' => 'RSK',
            'duration_days' => 21,
            'assign' => ['mandatory' => true, 'due_days' => 21],
            'lessons' => [
                [
                    'type' => 'text',
                    'title' => 'Rửa tiền là gì và vì sao ngân hàng phải phòng chống',
                    'summary' => 'Khái niệm, ba giai đoạn của hành vi rửa tiền và hậu quả với ngân hàng.',
                    'minutes' => 20,
                    'content' => '<h3>Định nghĩa</h3>
<p>Rửa tiền là hành vi hợp pháp hóa nguồn gốc tài sản có được từ hoạt động phạm tội, làm cho khoản tiền đó trông như thu nhập hợp pháp.</p>
<h3>Ba giai đoạn</h3>
<ol>
<li><strong>Sắp xếp (Placement)</strong>: đưa tiền mặt bất hợp pháp vào hệ thống tài chính. Ví dụ: chia nhỏ khoản tiền lớn thành nhiều lần nộp dưới ngưỡng báo cáo, nộp tiền qua nhiều chi nhánh khác nhau trong cùng ngày.</li>
<li><strong>Phân tán (Layering)</strong>: chuyển tiền qua nhiều tài khoản, nhiều quốc gia, mua bán tài sản để xóa dấu vết nguồn gốc. Dấu hiệu: tài khoản nhận tiền rồi chuyển đi ngay trong ngày, giao dịch không có mục đích kinh tế rõ ràng.</li>
<li><strong>Hòa nhập (Integration)</strong>: đưa tiền trở lại nền kinh tế dưới dạng thu nhập hợp pháp như đầu tư bất động sản, góp vốn doanh nghiệp.</li>
</ol>
<h3>Hậu quả với ngân hàng</h3>
<p>Ngân hàng để lọt giao dịch rửa tiền có thể bị phạt tiền rất nặng, bị hạn chế hoạt động, mất quan hệ đại lý với ngân hàng nước ngoài. Cá nhân nhân viên biết mà không báo cáo có thể bị truy cứu trách nhiệm hình sự.</p>',
                ],
                [
                    'type' => 'text',
                    'title' => 'Nhận diện giao dịch đáng ngờ',
                    'summary' => 'Các dấu hiệu cụ thể cần chú ý khi tiếp nhận giao dịch.',
                    'minutes' => 25,
                    'content' => '<h3>Dấu hiệu về hành vi khách hàng</h3>
<ul>
<li>Khách hàng tỏ ra bối rối, né tránh khi được hỏi về nguồn gốc tiền hoặc mục đích giao dịch.</li>
<li>Khách hàng yêu cầu thực hiện giao dịch sát dưới ngưỡng phải báo cáo (ví dụ nộp 290 triệu thay vì 300 triệu).</li>
<li>Khách hàng đi cùng người khác và người này thực sự chỉ đạo giao dịch.</li>
<li>Khách hàng quan tâm bất thường tới quy định báo cáo giao dịch của ngân hàng.</li>
</ul>
<h3>Dấu hiệu về giao dịch</h3>
<ul>
<li>Nộp tiền mặt nhiều lần trong ngày, mỗi lần dưới ngưỡng, tổng cộng vượt ngưỡng.</li>
<li>Tài khoản mới mở nhận ngay khoản tiền lớn rồi chuyển đi hết trong thời gian ngắn.</li>
<li>Giao dịch không phù hợp với hồ sơ khách hàng: sinh viên nhận chuyển khoản hàng tỷ đồng, hộ kinh doanh nhỏ có dòng tiền quốc tế lớn.</li>
<li>Chuyển tiền tới quốc gia có rủi ro cao mà không có quan hệ thương mại rõ ràng.</li>
</ul>
<h3>Việc cần làm khi phát hiện</h3>
<p>Không từ chối giao dịch ngay và không nói với khách rằng giao dịch bị nghi ngờ. Hoàn tất giao dịch nếu đủ điều kiện pháp lý, sau đó lập báo cáo giao dịch đáng ngờ gửi bộ phận Tuân thủ trong vòng 24 giờ. Tiết lộ việc báo cáo cho khách hàng là hành vi bị cấm.</p>',
                ],
                [
                    'type' => 'video',
                    'title' => 'Video: Quy trình định danh khách hàng (KYC)',
                    'summary' => 'Các bước xác minh danh tính và cập nhật hồ sơ khách hàng.',
                    'minutes' => 12,
                    'youtube' => 'aDDWv1Tz3uQ',
                ],
                [
                    'type' => 'quiz',
                    'title' => 'Kiểm tra cuối khóa AML',
                    'summary' => 'Bài kiểm tra bắt buộc, cần đạt 80 điểm.',
                    'minutes' => 20,
                    'pass_score' => 80,
                    'questions' => [
                        [
                            'content' => 'Khách hàng nộp tiền mặt 5 lần trong một ngày, mỗi lần 250 triệu đồng tại các phòng giao dịch khác nhau. Đây là dấu hiệu của giai đoạn nào?',
                            'explanation' => 'Đây là giai đoạn Sắp xếp (Placement) — đưa tiền mặt bất hợp pháp vào hệ thống tài chính. '
                                . 'Hành vi chia nhỏ khoản tiền lớn thành nhiều lần nộp dưới ngưỡng báo cáo, thực hiện tại nhiều điểm khác nhau, '
                                . 'nhằm tránh bị phát hiện. Giai đoạn Phân tán là khi tiền đã vào hệ thống rồi mới luân chuyển; giai đoạn Hòa nhập là khi tiền quay lại nền kinh tế dưới dạng hợp pháp.',
                            'options' => [
                                ['Giai đoạn Sắp xếp (Placement)', true],
                                ['Giai đoạn Phân tán (Layering)', false],
                                ['Giai đoạn Hòa nhập (Integration)', false],
                            ],
                        ],
                        [
                            'content' => 'Phát hiện giao dịch đáng ngờ, nhân viên giao dịch phải làm gì trước tiên?',
                            'explanation' => 'Hoàn tất giao dịch nếu đủ điều kiện pháp lý, rồi lập báo cáo gửi bộ phận Tuân thủ trong 24 giờ. '
                                . 'Từ chối ngay hoặc báo cho khách biết đều sai: tiết lộ việc báo cáo cho khách hàng là hành vi bị pháp luật cấm, '
                                . 'và từ chối đột ngột có thể khiến đối tượng cảnh giác, xóa dấu vết trước khi cơ quan chức năng vào cuộc.',
                            'options' => [
                                ['Hoàn tất giao dịch rồi báo cáo bộ phận Tuân thủ trong 24 giờ', true],
                                ['Từ chối giao dịch ngay và giải thích lý do cho khách hàng', false],
                                ['Gọi điện hỏi ý kiến đồng nghiệp rồi quyết định', false],
                            ],
                        ],
                        [
                            'content' => 'Trường hợp nào sau đây KHÔNG phải dấu hiệu đáng ngờ?',
                            'explanation' => 'Doanh nghiệp xuất khẩu nhận ngoại tệ định kỳ từ đối tác nước ngoài đã có hợp đồng là hoạt động bình thường, '
                                . 'phù hợp với hồ sơ khách hàng. Ba yếu tố khiến giao dịch trở nên đáng ngờ là: không phù hợp hồ sơ khách hàng, '
                                . 'không có mục đích kinh tế rõ ràng, và có dấu hiệu né tránh quy định báo cáo.',
                            'options' => [
                                ['Doanh nghiệp xuất khẩu nhận ngoại tệ định kỳ theo hợp đồng đã đăng ký', true],
                                ['Sinh viên nhận chuyển khoản 3 tỷ đồng rồi rút hết trong ngày', false],
                                ['Khách hàng liên tục hỏi về ngưỡng tiền phải báo cáo', false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function khoaDichVuKhachHang(): array
    {
        return [
            'code' => 'CS-2026',
            'title' => 'Chuẩn mực dịch vụ khách hàng',
            'slug' => 'chuan-muc-dich-vu-khach-hang',
            'description' => 'Quy tắc ứng xử tại quầy, xử lý khiếu nại và kỹ năng giữ chân khách hàng.',
            'owner' => 'RTL',
            'assign' => ['dept' => 'OPS', 'mandatory' => true, 'due_days' => 30],
            'lessons' => [
                [
                    'type' => 'text',
                    'title' => 'Chuẩn mực phục vụ tại quầy',
                    'summary' => 'Quy tắc 5 bước tiếp đón và các lỗi thường gặp.',
                    'minutes' => 18,
                    'content' => '<h3>Năm bước tiếp đón chuẩn</h3>
<ol>
<li><strong>Chào đón trong 30 giây</strong>: nhìn vào mắt khách, mỉm cười, chào theo mẫu "Em chào anh/chị, em có thể giúp gì ạ?". Khách đang đợi mà chưa tới lượt vẫn cần một ánh mắt ghi nhận.</li>
<li><strong>Lắng nghe hết vấn đề</strong>: để khách nói xong mới hỏi lại. Ngắt lời giữa chừng khiến khách phải kể lại từ đầu và cảm thấy không được tôn trọng.</li>
<li><strong>Xác nhận lại nhu cầu</strong>: tóm tắt bằng lời của mình để chắc chắn hiểu đúng. Ví dụ "Vậy anh muốn chuyển 50 triệu sang tài khoản Vietcombank, đúng không ạ?".</li>
<li><strong>Thực hiện và giải thích</strong>: vừa thao tác vừa cho khách biết đang làm gì, mất bao lâu. Im lặng thao tác khiến khách lo lắng.</li>
<li><strong>Kết thúc và mời quay lại</strong>: xác nhận giao dịch thành công, hỏi khách còn cần gì thêm.</li>
</ol>
<h3>Lỗi thường gặp</h3>
<ul>
<li>Hứa điều nằm ngoài thẩm quyền, ví dụ hứa duyệt khoản vay khi chưa qua thẩm định.</li>
<li>Đổ lỗi cho hệ thống hoặc bộ phận khác trước mặt khách.</li>
<li>Dùng thuật ngữ nghiệp vụ mà khách không hiểu.</li>
<li>Xử lý việc riêng hoặc trò chuyện với đồng nghiệp khi đang có khách chờ.</li>
</ul>',
                ],
                [
                    'type' => 'text',
                    'title' => 'Xử lý khiếu nại',
                    'summary' => 'Quy trình tiếp nhận và mốc thời gian phản hồi bắt buộc.',
                    'minutes' => 20,
                    'content' => '<h3>Nguyên tắc</h3>
<p>Khách khiếu nại là khách còn muốn tiếp tục dùng dịch vụ. Khách bỏ đi không nói gì mới là mất khách thật sự.</p>
<h3>Bốn bước xử lý</h3>
<ol>
<li><strong>Ghi nhận đầy đủ</strong>: để khách trình bày hết, ghi lại chi tiết vào hệ thống. Không phản bác khi khách đang bức xúc.</li>
<li><strong>Thừa nhận cảm xúc</strong>: "Em hiểu việc này gây bất tiện cho anh/chị". Đây không phải nhận lỗi, mà là ghi nhận trải nghiệm của khách.</li>
<li><strong>Đưa mốc thời gian cụ thể</strong>: nói rõ khi nào sẽ có phản hồi, ví dụ "Trong vòng 2 ngày làm việc em sẽ gọi lại cho anh/chị". Không nói chung chung "sẽ kiểm tra rồi báo lại".</li>
<li><strong>Theo sát đến khi xong</strong>: dù việc xử lý thuộc bộ phận khác, người tiếp nhận vẫn là đầu mối với khách.</li>
</ol>
<h3>Mốc thời gian bắt buộc</h3>
<ul>
<li>Khiếu nại giao dịch thẻ: phản hồi trong 5 ngày làm việc.</li>
<li>Khiếu nại chuyển tiền nhầm: xác minh trong 3 ngày làm việc.</li>
<li>Khiếu nại về phí: giải đáp ngay tại quầy nếu thuộc biểu phí công bố.</li>
</ul>',
                ],
                [
                    'type' => 'video',
                    'title' => 'Video: Kỹ năng giao tiếp với khách hàng khó tính',
                    'summary' => 'Tình huống thực tế và cách xử lý.',
                    'minutes' => 14,
                    'youtube' => 'TDvHl2Bs8Dg',
                ],
                [
                    'type' => 'quiz',
                    'title' => 'Kiểm tra chuẩn mực dịch vụ',
                    'minutes' => 15,
                    'questions' => [
                        [
                            'content' => 'Khách hàng khiếu nại về một giao dịch thẻ bị trừ tiền hai lần. Bước đầu tiên nên làm gì?',
                            'explanation' => 'Ghi nhận đầy đủ thông tin khiếu nại vào hệ thống trước. Giải thích ngay khi chưa kiểm tra có thể '
                                . 'đưa thông tin sai; hứa hoàn tiền ngay là vượt thẩm quyền vì chưa biết nguyên nhân. '
                                . 'Sau khi ghi nhận, cần nêu mốc thời gian cụ thể — khiếu nại giao dịch thẻ phải phản hồi trong 5 ngày làm việc.',
                            'options' => [
                                ['Ghi nhận đầy đủ thông tin khiếu nại rồi nêu mốc thời gian phản hồi', true],
                                ['Giải thích ngay rằng hệ thống không thể trừ tiền hai lần', false],
                                ['Hứa hoàn tiền cho khách trong ngày hôm nay', false],
                            ],
                        ],
                        [
                            'content' => 'Câu nói nào phù hợp khi khách hàng đang bức xúc?',
                            'explanation' => 'Thừa nhận cảm xúc của khách ("Em hiểu việc này gây bất tiện...") giúp hạ nhiệt mà không đồng nghĩa '
                                . 'với nhận lỗi. Đổ lỗi cho hệ thống hay bộ phận khác làm khách mất niềm tin vào cả ngân hàng. '
                                . 'Yêu cầu khách bình tĩnh thường khiến họ bức xúc hơn vì cảm thấy bị coi là người gây rối.',
                            'options' => [
                                ['"Em hiểu việc này gây bất tiện cho anh/chị"', true],
                                ['"Anh/chị bình tĩnh lại đã rồi em mới xử lý được"', false],
                                ['"Cái này do hệ thống lỗi chứ không phải em làm sai"', false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function khoaTinDung(): array
    {
        return [
            'code' => 'CRD-2026',
            'title' => 'Thẩm định tín dụng khách hàng cá nhân',
            'slug' => 'tham-dinh-tin-dung-ca-nhan',
            'description' => 'Quy trình thẩm định hồ sơ vay, đánh giá khả năng trả nợ và nhận diện hồ sơ rủi ro.',
            'owner' => 'CRD',
            'assign' => ['dept' => 'CRD', 'mandatory' => true, 'due_days' => 45],
            'lessons' => [
                [
                    'type' => 'text',
                    'title' => 'Nguyên tắc 5C trong thẩm định tín dụng',
                    'summary' => 'Năm yếu tố cốt lõi khi đánh giá một hồ sơ vay.',
                    'minutes' => 25,
                    'content' => '<h3>Năm yếu tố 5C</h3>
<ol>
<li><strong>Character (Uy tín)</strong>: lịch sử trả nợ của khách qua CIC, thái độ hợp tác khi cung cấp hồ sơ. Khách có nợ xấu nhóm 3 trở lên trong 12 tháng gần nhất thuộc diện từ chối.</li>
<li><strong>Capacity (Khả năng trả nợ)</strong>: thu nhập ổn định so với nghĩa vụ trả nợ hàng tháng. Tỉ lệ nghĩa vụ nợ trên thu nhập (DTI) không nên vượt 50% với khách hàng thu nhập trung bình.</li>
<li><strong>Capital (Vốn tự có)</strong>: phần khách tự bỏ ra trong phương án. Vốn tự có càng cao thì khách càng có động lực trả nợ.</li>
<li><strong>Collateral (Tài sản bảo đảm)</strong>: giá trị và tính thanh khoản của tài sản. Bất động sản ở vị trí khó bán dù định giá cao vẫn là rủi ro.</li>
<li><strong>Conditions (Điều kiện)</strong>: bối cảnh ngành nghề, kinh tế vĩ mô ảnh hưởng tới nguồn thu của khách.</li>
</ol>
<h3>Thứ tự ưu tiên</h3>
<p>Capacity là yếu tố quan trọng nhất. Tài sản bảo đảm chỉ là phương án dự phòng — ngân hàng cho vay để thu lãi, không phải để bán tài sản.</p>',
                ],
                [
                    'type' => 'text',
                    'title' => 'Nhận diện hồ sơ có dấu hiệu rủi ro',
                    'summary' => 'Các dấu hiệu cảnh báo cần thẩm định kỹ hơn.',
                    'minutes' => 20,
                    'content' => '<h3>Dấu hiệu về hồ sơ</h3>
<ul>
<li>Sao kê lương có dòng tiền vào đều đặn nhưng rút hết ngay sau đó — dấu hiệu lương ảo.</li>
<li>Hợp đồng lao động mới ký sát ngày nộp hồ sơ vay.</li>
<li>Giấy tờ có dấu hiệu chỉnh sửa: font chữ không đồng nhất, con dấu mờ khác thường.</li>
<li>Khách hàng không nắm được thông tin cơ bản về nơi mình làm việc.</li>
</ul>
<h3>Dấu hiệu về phương án vay</h3>
<ul>
<li>Mục đích vay không rõ ràng hoặc thay đổi qua các lần trao đổi.</li>
<li>Số tiền vay vượt xa nhu cầu thực tế của phương án.</li>
<li>Khách hàng thúc ép giải ngân gấp mà không có lý do hợp lý.</li>
<li>Người trả nợ thực tế không phải người đứng tên vay.</li>
</ul>
<h3>Cách xử lý</h3>
<p>Phát hiện dấu hiệu rủi ro không có nghĩa từ chối ngay. Cần xác minh thêm: gọi kiểm tra nơi làm việc, đối chiếu sao kê với hệ thống, yêu cầu bổ sung chứng từ. Ghi rõ kết quả xác minh vào tờ trình để người phê duyệt có đủ căn cứ.</p>',
                ],
                [
                    'type' => 'video',
                    'title' => 'Video: Đọc và phân tích báo cáo tín dụng CIC',
                    'summary' => 'Hướng dẫn đọc các nhóm nợ và lịch sử tín dụng.',
                    'minutes' => 16,
                    'youtube' => 'x7X9w_GIm1s',
                ],
                [
                    'type' => 'quiz',
                    'title' => 'Kiểm tra nghiệp vụ thẩm định',
                    'minutes' => 25,
                    'pass_score' => 75,
                    'questions' => [
                        [
                            'content' => 'Trong nguyên tắc 5C, yếu tố nào quan trọng nhất khi quyết định cho vay?',
                            'explanation' => 'Capacity (khả năng trả nợ) là yếu tố quan trọng nhất. Ngân hàng cho vay để thu lãi từ dòng tiền của '
                                . 'khách hàng, không phải để phát mại tài sản. Tài sản bảo đảm (Collateral) chỉ là phương án dự phòng — '
                                . 'một khoản vay phải xử lý tài sản đã là khoản vay thất bại, kéo theo chi phí pháp lý và thời gian.',
                            'options' => [
                                ['Capacity — khả năng trả nợ từ thu nhập', true],
                                ['Collateral — giá trị tài sản bảo đảm', false],
                                ['Capital — vốn tự có của khách hàng', false],
                            ],
                        ],
                        [
                            'content' => 'Sao kê lương của khách cho thấy tiền vào đều đặn 30 triệu mỗi tháng nhưng rút hết trong ngày. Đây là dấu hiệu gì?',
                            'explanation' => 'Đây là dấu hiệu lương ảo — tiền được chuyển vào để tạo sao kê đẹp rồi rút ra ngay. '
                                . 'Người có thu nhập thật thường để lại một phần cho chi tiêu trong tháng. '
                                . 'Cần xác minh thêm bằng cách gọi kiểm tra nơi làm việc và đối chiếu với hợp đồng lao động, bảo hiểm xã hội.',
                            'options' => [
                                ['Dấu hiệu lương ảo, cần xác minh thêm', true],
                                ['Bình thường, khách có thói quen dùng tiền mặt', false],
                                ['Dấu hiệu khách có thu nhập cao nên chi tiêu nhiều', false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function khoaSanPhamThe(): array
    {
        return [
            'code' => 'CARD-2026',
            'title' => 'Sản phẩm thẻ và thanh toán',
            'slug' => 'san-pham-the-va-thanh-toan',
            'description' => 'Danh mục sản phẩm thẻ, biểu phí, quy trình phát hành và xử lý tranh chấp giao dịch.',
            'owner' => 'RTL',
            'sequential' => false,
            'assign' => ['dept' => 'RTL', 'mandatory' => false, 'due_days' => 60],
            'lessons' => [
                [
                    'type' => 'text',
                    'title' => 'Phân loại thẻ và đối tượng khách hàng',
                    'summary' => 'Thẻ ghi nợ, thẻ tín dụng, thẻ trả trước và cách tư vấn đúng nhu cầu.',
                    'minutes' => 15,
                    'content' => '<h3>Ba nhóm thẻ chính</h3>
<ul>
<li><strong>Thẻ ghi nợ (Debit)</strong>: chi tiêu trong số dư tài khoản. Phù hợp khách hàng muốn kiểm soát chi tiêu, không muốn phát sinh nợ. Phí thường niên thấp.</li>
<li><strong>Thẻ tín dụng (Credit)</strong>: chi tiêu trước, trả sau trong hạn mức được cấp. Phù hợp khách có thu nhập ổn định, cần dòng tiền linh hoạt. Miễn lãi tối đa 45 ngày nếu thanh toán đủ dư nợ đúng hạn.</li>
<li><strong>Thẻ trả trước (Prepaid)</strong>: nạp tiền trước rồi dùng. Phù hợp làm quà tặng, quản lý chi tiêu cho người chưa đủ điều kiện mở tài khoản.</li>
</ul>
<h3>Lỗi tư vấn thường gặp</h3>
<p>Tư vấn thẻ tín dụng cho khách chỉ cần rút tiền mặt là sai: phí rút tiền mặt từ thẻ tín dụng rất cao và tính lãi ngay từ ngày rút, không có thời gian miễn lãi. Khách này nên dùng thẻ ghi nợ.</p>',
                ],
                [
                    'type' => 'text',
                    'title' => 'Xử lý tranh chấp giao dịch thẻ',
                    'summary' => 'Quy trình chargeback và mốc thời gian.',
                    'minutes' => 18,
                    'content' => '<h3>Khi nào khách được khiếu nại</h3>
<ul>
<li>Giao dịch khách không thực hiện (nghi gian lận).</li>
<li>Bị trừ tiền nhiều lần cho một giao dịch.</li>
<li>Đã hủy dịch vụ nhưng vẫn bị thu tiền.</li>
<li>Hàng hóa không được giao đúng cam kết với giao dịch trực tuyến.</li>
</ul>
<h3>Mốc thời gian</h3>
<p>Khách phải khiếu nại trong vòng 60 ngày kể từ ngày giao dịch. Ngân hàng phản hồi kết quả trong 5 ngày làm việc với giao dịch nội địa, 30 ngày với giao dịch quốc tế do phải làm việc với tổ chức thẻ.</p>
<h3>Việc cần làm ngay</h3>
<p>Nghi ngờ thẻ bị lộ thông tin: khóa thẻ ngay trước khi lập hồ sơ khiếu nại. Chậm khóa thẻ có thể phát sinh thêm giao dịch gian lận, phần này thường không được bồi hoàn.</p>',
                ],
                [
                    'type' => 'video',
                    'title' => 'Video: Bảo mật thông tin thẻ cho khách hàng',
                    'summary' => 'Các hình thức lừa đảo thẻ phổ biến và cách phòng tránh.',
                    'minutes' => 11,
                    'youtube' => 'inWWhr5tnEA',
                ],
                [
                    'type' => 'quiz',
                    'title' => 'Kiểm tra sản phẩm thẻ',
                    'minutes' => 15,
                    'questions' => [
                        [
                            'content' => 'Khách hàng chỉ có nhu cầu rút tiền mặt thường xuyên. Nên tư vấn loại thẻ nào?',
                            'explanation' => 'Thẻ ghi nợ là phù hợp. Rút tiền mặt từ thẻ tín dụng chịu phí rất cao và bị tính lãi ngay từ ngày rút, '
                                . 'không có thời gian miễn lãi 45 ngày như khi chi tiêu mua sắm. '
                                . 'Tư vấn sai loại thẻ khiến khách chịu chi phí không đáng có và dễ dẫn tới khiếu nại về sau.',
                            'options' => [
                                ['Thẻ ghi nợ (Debit)', true],
                                ['Thẻ tín dụng (Credit) vì hạn mức cao hơn', false],
                                ['Thẻ trả trước (Prepaid)', false],
                            ],
                        ],
                        [
                            'content' => 'Khách báo thẻ bị trừ tiền cho giao dịch không thực hiện. Việc cần làm NGAY là gì?',
                            'explanation' => 'Khóa thẻ ngay trước khi lập hồ sơ khiếu nại. Nếu thẻ đã lộ thông tin mà chậm khóa, '
                                . 'kẻ gian có thể thực hiện thêm giao dịch — phần phát sinh sau khi khách đã báo thường không được bồi hoàn. '
                                . 'Lập hồ sơ khiếu nại là bước tiếp theo, không phải bước đầu tiên.',
                            'options' => [
                                ['Khóa thẻ ngay, sau đó lập hồ sơ khiếu nại', true],
                                ['Lập hồ sơ khiếu nại rồi chờ kết quả mới khóa thẻ', false],
                                ['Hướng dẫn khách tự khóa thẻ trên ứng dụng rồi về', false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function khoaKyNangMem(): array
    {
        return [
            'code' => 'SOFT-2026',
            'title' => 'Kỹ năng làm việc hiệu quả',
            'slug' => 'ky-nang-lam-viec-hieu-qua',
            'description' => 'Quản lý thời gian, giao tiếp trong nhóm và trình bày ý tưởng.',
            'owner' => 'TRN',
            'sequential' => false,
            'certificate' => false,
            'assign' => ['mandatory' => false, 'due_days' => 90],
            'lessons' => [
                [
                    'type' => 'text',
                    'title' => 'Quản lý thời gian theo mức ưu tiên',
                    'summary' => 'Ma trận khẩn cấp - quan trọng và cách áp dụng.',
                    'minutes' => 15,
                    'content' => '<h3>Ma trận bốn ô</h3>
<ul>
<li><strong>Khẩn cấp + Quan trọng</strong>: khủng hoảng, hạn chót sát nút. Làm ngay nhưng cần giảm dần bằng cách lập kế hoạch tốt hơn.</li>
<li><strong>Quan trọng, không khẩn cấp</strong>: lập kế hoạch, học tập, xây dựng quan hệ. Đây là ô quyết định hiệu quả dài hạn — cần chủ động dành thời gian.</li>
<li><strong>Khẩn cấp, không quan trọng</strong>: một số cuộc gọi, email cần trả lời ngay nhưng ít giá trị. Nên ủy quyền hoặc xử lý gọn.</li>
<li><strong>Không khẩn cấp, không quan trọng</strong>: việc gây xao nhãng. Cần loại bỏ.</li>
</ul>
<h3>Áp dụng thực tế</h3>
<p>Đầu ngày dành 10 phút liệt kê việc và xếp vào bốn ô. Phần lớn người làm việc kém hiệu quả vì dành hết thời gian cho ô "khẩn cấp không quan trọng" — trả lời tin nhắn, họp không cần thiết — rồi không còn thời gian cho việc thực sự tạo giá trị.</p>',
                ],
                [
                    'type' => 'video',
                    'title' => 'Video: Kỹ năng thuyết trình và trình bày',
                    'summary' => 'Cấu trúc bài trình bày và cách xử lý hồi hộp.',
                    'minutes' => 18,
                    'youtube' => 'AykYRO5d_lI',
                ],
                [
                    'type' => 'quiz',
                    'title' => 'Kiểm tra kỹ năng làm việc',
                    'minutes' => 10,
                    'pass_score' => 60,
                    'questions' => [
                        [
                            'content' => 'Nhóm việc nào quyết định hiệu quả công việc dài hạn?',
                            'explanation' => 'Nhóm "Quan trọng nhưng không khẩn cấp" — lập kế hoạch, học tập, xây dựng quan hệ. '
                                . 'Đây là nhóm dễ bị bỏ qua nhất vì không có áp lực thời gian, nhưng chính nó tạo ra giá trị dài hạn '
                                . 'và làm giảm số lượng khủng hoảng khẩn cấp trong tương lai.',
                            'options' => [
                                ['Quan trọng nhưng không khẩn cấp', true],
                                ['Khẩn cấp và quan trọng', false],
                                ['Khẩn cấp nhưng không quan trọng', false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function khoaPhapLyNganHang(): array
    {
        return [
            'code' => 'LEG-2026',
            'title' => 'Pháp lý trong hoạt động ngân hàng',
            'slug' => 'phap-ly-hoat-dong-ngan-hang',
            'description' => 'Quy định pháp luật liên quan tới hoạt động huy động, cho vay và bảo mật thông tin khách hàng.',
            'owner' => 'LEG',
            'assign' => ['grade' => 'G5', 'mandatory' => true, 'due_days' => 60],
            'lessons' => [
                [
                    'type' => 'text',
                    'title' => 'Bảo mật thông tin khách hàng theo quy định pháp luật',
                    'summary' => 'Nghĩa vụ bảo mật và các trường hợp được phép cung cấp thông tin.',
                    'minutes' => 22,
                    'content' => '<h3>Nguyên tắc chung</h3>
<p>Thông tin khách hàng là bí mật ngân hàng. Nhân viên không được cung cấp cho bên thứ ba, kể cả người thân của khách hàng, trừ các trường hợp pháp luật cho phép.</p>
<h3>Trường hợp được cung cấp</h3>
<ul>
<li>Có yêu cầu bằng văn bản của cơ quan có thẩm quyền: tòa án, viện kiểm sát, cơ quan điều tra, cơ quan thuế theo đúng trình tự luật định.</li>
<li>Khách hàng có văn bản đồng ý, nêu rõ phạm vi thông tin và bên nhận.</li>
<li>Cung cấp cho tổ chức tín dụng khác theo quy định về thông tin tín dụng.</li>
</ul>
<h3>Hành vi bị cấm</h3>
<ul>
<li>Tra cứu thông tin khách hàng không phục vụ công việc được giao — kể cả chỉ để xem, không tiết lộ.</li>
<li>Chụp màn hình thông tin khách gửi qua ứng dụng nhắn tin cá nhân.</li>
<li>Trao đổi thông tin khách hàng ở nơi công cộng, kể cả trong thang máy hay căng tin.</li>
<li>Cung cấp số dư tài khoản qua điện thoại khi chưa xác thực đúng quy trình.</li>
</ul>
<h3>Chế tài</h3>
<p>Vi phạm có thể bị xử lý kỷ luật đến mức sa thải, bồi thường thiệt hại, và trong trường hợp nghiêm trọng có thể bị truy cứu trách nhiệm hình sự.</p>',
                ],
                [
                    'type' => 'quiz',
                    'title' => 'Kiểm tra pháp lý ngân hàng',
                    'minutes' => 20,
                    'pass_score' => 80,
                    'questions' => [
                        [
                            'content' => 'Vợ của khách hàng gọi điện hỏi số dư tài khoản của chồng. Nhân viên nên làm gì?',
                            'explanation' => 'Từ chối cung cấp. Thông tin khách hàng là bí mật ngân hàng, người thân không thuộc các trường hợp '
                                . 'được phép nhận thông tin. Chỉ cung cấp khi có văn bản đồng ý của chính khách hàng nêu rõ phạm vi, '
                                . 'hoặc có yêu cầu hợp lệ của cơ quan có thẩm quyền. Cung cấp cho người thân là vi phạm, dù thiện chí.',
                            'options' => [
                                ['Từ chối, giải thích rằng đây là thông tin bảo mật', true],
                                ['Cung cấp vì là vợ hợp pháp, có giấy đăng ký kết hôn', false],
                                ['Cung cấp nếu vợ đọc đúng số chứng minh của chồng', false],
                            ],
                        ],
                        [
                            'content' => 'Hành vi nào sau đây vi phạm quy định bảo mật, dù không tiết lộ cho ai?',
                            'explanation' => 'Tra cứu thông tin khách hàng không phục vụ công việc được giao là vi phạm, kể cả chỉ xem vì tò mò. '
                                . 'Hệ thống ghi nhật ký mọi lượt truy cập; tra cứu bất thường sẽ bị phát hiện khi rà soát. '
                                . 'Quyền truy cập được cấp để làm việc, không phải để sử dụng tùy ý.',
                            'options' => [
                                ['Tra cứu tài khoản người quen vì tò mò', true],
                                ['Tra cứu tài khoản khách đang giao dịch tại quầy', false],
                                ['Tra cứu hồ sơ theo yêu cầu của trưởng phòng để xử lý khiếu nại', false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function khoaChuyenDoiSo(): array
    {
        return [
            'code' => 'DIG-2026',
            'title' => 'Chuyển đổi số trong ngân hàng',
            'slug' => 'chuyen-doi-so-ngan-hang',
            'description' => 'Xu hướng ngân hàng số, sản phẩm số của ngân hàng và cách hỗ trợ khách hàng sử dụng.',
            'owner' => 'IT',
            'sequential' => false,
            'assign' => ['mandatory' => false, 'due_days' => 90],
            'lessons' => [
                [
                    'type' => 'text',
                    'title' => 'Hỗ trợ khách hàng dùng ngân hàng số',
                    'summary' => 'Các vướng mắc thường gặp và cách hướng dẫn.',
                    'minutes' => 16,
                    'content' => '<h3>Vướng mắc thường gặp</h3>
<ul>
<li><strong>Quên mật khẩu ứng dụng</strong>: hướng dẫn khách dùng chức năng quên mật khẩu với xác thực qua OTP. Không bao giờ hỏi mật khẩu của khách.</li>
<li><strong>Không nhận được OTP</strong>: kiểm tra số điện thoại đăng ký còn đúng không, sóng điện thoại, hộp thư rác. Phần lớn do khách đổi số mà chưa cập nhật.</li>
<li><strong>Chuyển tiền nhầm tài khoản</strong>: hướng dẫn khách lập yêu cầu tra soát ngay. Ngân hàng liên hệ chủ tài khoản nhận để đề nghị hoàn trả — không thể tự động thu hồi.</li>
<li><strong>Thiết bị mới không đăng nhập được</strong>: cần kích hoạt lại thiết bị qua xác thực, đây là cơ chế bảo vệ chứ không phải lỗi.</li>
</ul>
<h3>Nguyên tắc tuyệt đối</h3>
<p>Nhân viên ngân hàng không bao giờ hỏi mật khẩu, mã OTP hay mã PIN của khách hàng trong bất kỳ tình huống nào. Cần nhắc khách nguyên tắc này để họ nhận ra kẻ mạo danh.</p>',
                ],
                [
                    'type' => 'video',
                    'title' => 'Video: Xu hướng ngân hàng số',
                    'summary' => 'Bức tranh chung về chuyển đổi số ngành tài chính.',
                    'minutes' => 15,
                    'youtube' => 'wHCSCNnHhLI',
                ],
                [
                    'type' => 'quiz',
                    'title' => 'Kiểm tra ngân hàng số',
                    'minutes' => 10,
                    'pass_score' => 70,
                    'questions' => [
                        [
                            'content' => 'Khách gọi điện nói quên mật khẩu ứng dụng và nhờ nhân viên đọc lại mật khẩu. Nên xử lý thế nào?',
                            'explanation' => 'Hướng dẫn khách tự đặt lại mật khẩu qua chức năng quên mật khẩu với xác thực OTP. '
                                . 'Ngân hàng không lưu mật khẩu dạng đọc được và nhân viên không bao giờ được hỏi hay đọc mật khẩu của khách. '
                                . 'Nhắc khách nguyên tắc này cũng giúp họ nhận ra kẻ mạo danh nhân viên ngân hàng.',
                            'options' => [
                                ['Hướng dẫn khách tự đặt lại qua chức năng quên mật khẩu', true],
                                ['Tra cứu trong hệ thống rồi đọc lại cho khách', false],
                                ['Đề nghị khách ra quầy để nhân viên đặt hộ mật khẩu mới', false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function khoaOnboardingVanHoa(): array
    {
        return [
            'code' => 'ONB-CULTURE',
            'title' => 'Văn hóa doanh nghiệp và quy định nội bộ',
            'slug' => 'van-hoa-doanh-nghiep',
            'description' => 'Dành cho nhân viên mới: giá trị cốt lõi, quy định giờ giấc, trang phục và ứng xử nội bộ.',
            'owner' => 'HR',
            'onboarding' => true,
            'assign' => ['new_hire' => true, 'mandatory' => true, 'due_days' => 14],
            'lessons' => [
                [
                    'type' => 'text',
                    'title' => 'Quy định nội bộ cần biết trong tuần đầu',
                    'summary' => 'Giờ làm việc, trang phục, chấm công và nghỉ phép.',
                    'minutes' => 18,
                    'content' => '<h3>Giờ làm việc</h3>
<p>Giờ hành chính từ 8h00 đến 17h30, nghỉ trưa 12h00-13h00. Nhân viên quầy giao dịch theo ca do chi nhánh phân công. Chấm công bằng vân tay hoặc thẻ nhân viên tại cửa vào; quên chấm công cần báo quản lý trực tiếp trong ngày.</p>
<h3>Trang phục</h3>
<ul>
<li>Nhân viên tiếp xúc khách hàng: đồng phục theo quy định, thẻ nhân viên đeo ở vị trí dễ nhìn.</li>
<li>Khối hỗ trợ: trang phục công sở lịch sự. Thứ Sáu được mặc tự do lịch sự.</li>
<li>Không mặc quần short, dép lê, áo không cổ khi tới cơ quan.</li>
</ul>
<h3>Nghỉ phép</h3>
<p>Nhân viên chính thức có 12 ngày phép năm, cộng thêm 1 ngày cho mỗi 5 năm công tác. Đăng ký nghỉ trên hệ thống trước tối thiểu 3 ngày làm việc, trừ trường hợp đột xuất. Nhân viên thử việc chưa có phép năm nhưng được nghỉ không lương khi có lý do chính đáng.</p>
<h3>Ứng xử nội bộ</h3>
<p>Trao đổi công việc qua email công ty hoặc kênh nội bộ được phê duyệt. Không dùng ứng dụng nhắn tin cá nhân để gửi tài liệu công việc hay thông tin khách hàng.</p>',
                ],
                [
                    'type' => 'video',
                    'title' => 'Video: Chào mừng nhân viên mới',
                    'summary' => 'Giới thiệu môi trường làm việc và lộ trình phát triển.',
                    'minutes' => 8,
                    'youtube' => 'GW1a5Wsg3ts',
                ],
                [
                    'type' => 'quiz',
                    'title' => 'Kiểm tra quy định nội bộ',
                    'minutes' => 10,
                    'pass_score' => 60,
                    'questions' => [
                        [
                            'content' => 'Nhân viên chính thức được bao nhiêu ngày phép năm?',
                            'explanation' => '12 ngày phép năm, cộng thêm 1 ngày cho mỗi 5 năm công tác. '
                                . 'Nhân viên đang thử việc chưa có phép năm nhưng vẫn được nghỉ không lương khi có lý do chính đáng. '
                                . 'Đăng ký nghỉ trên hệ thống trước tối thiểu 3 ngày làm việc.',
                            'options' => [
                                ['12 ngày, cộng 1 ngày cho mỗi 5 năm công tác', true],
                                ['15 ngày cố định cho mọi nhân viên', false],
                                ['10 ngày trong năm đầu, sau đó 12 ngày', false],
                            ],
                        ],
                        [
                            'content' => 'Cần gửi gấp một file hồ sơ khách hàng cho đồng nghiệp. Cách nào đúng?',
                            'explanation' => 'Gửi qua email công ty hoặc kênh nội bộ đã được phê duyệt. '
                                . 'Ứng dụng nhắn tin cá nhân không nằm trong kiểm soát của ngân hàng: không ghi nhật ký được, '
                                . 'không thu hồi được khi gửi nhầm, và là một trong ba nguyên nhân lộ lọt dữ liệu khách hàng thường gặp nhất.',
                            'options' => [
                                ['Gửi qua email công ty hoặc kênh nội bộ được phê duyệt', true],
                                ['Gửi qua Zalo cho nhanh vì đang gấp', false],
                                ['Chụp màn hình gửi qua tin nhắn điện thoại', false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
