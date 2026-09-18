<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\User\AiChat;
use App\Models\AiConversation;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\User;
use App\Services\AiAssistantService;
use App\Services\TrainingKnowledgeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Trợ lý AI đào tạo (spec 4.3).
 *
 * Ràng buộc quan trọng nhất: AI chỉ được trả lời dựa trên nội dung mà chính
 * nhân viên đó được phép xem.
 */
class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config([
            'groq.api_key' => 'gsk_test_key',
            'groq.enabled' => true,
            'groq.model' => 'model-chinh',
            'groq.fallback_models' => ['model-du-phong-1', 'model-du-phong-2'],
        ]);

        Cache::flush();

        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->user->assignRole(RoleName::EMPLOYEE->value);
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);

        RateLimiter::clear('ai-chat:' . $this->employee->id);
    }

    /** Giả lập một câu trả lời từ Groq. */
    private function fakeGroq(string $answer = 'Câu trả lời mẫu.'): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $answer]]],
            ]),
        ]);
    }

    private function makeCourseWithLesson(string $lessonContent, bool $enroll = true): Course
    {
        $course = Course::factory()->create(['status' => Course::STATUS_PUBLISHED]);

        Lesson::create([
            'course_id' => $course->id,
            'title' => 'Bài giảng thử',
            'content_type' => Lesson::TYPE_TEXT,
            'content_html' => $lessonContent,
            'sort_order' => 1,
        ]);

        if ($enroll) {
            Enrollment::create([
                'course_id' => $course->id,
                'employee_id' => $this->employee->id,
                'status' => Enrollment::STATUS_IN_PROGRESS,
                'assigned_at' => now(),
                'total_lessons' => 1,
            ]);
        }

        return $course;
    }

    // ---- Ràng buộc quyền truy cập -------------------------------------------

    public function test_chi_lay_bai_giang_cua_khoa_duoc_gan(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung mật về quy trình nội bộ</p>', enroll: false);

        $chunks = app(TrainingKnowledgeService::class)
            ->search($this->employee, 'quy trình nội bộ');

        $this->assertTrue(
            $chunks->where('source', 'lesson')->isEmpty(),
            'Không được lấy bài giảng của khóa nhân viên chưa được gán'
        );
    }

    public function test_lay_duoc_bai_giang_cua_khoa_da_gan(): void
    {
        $this->makeCourseWithLesson('<p>Quy trình mở tài khoản gồm ba bước</p>');

        $chunks = app(TrainingKnowledgeService::class)
            ->search($this->employee, 'quy trình mở tài khoản');

        $this->assertTrue($chunks->where('source', 'lesson')->isNotEmpty());
    }

    public function test_khong_co_ngu_canh_thi_khong_goi_api(): void
    {
        Http::fake();

        $result = app(AiAssistantService::class)
            ->ask($this->employee, 'câu hỏi hoàn toàn không liên quan xyzabc');

        $this->assertFalse($result['grounded']);
        $this->assertSame([], $result['sources']);

        // Tiết kiệm chi phí: không tìm được gì thì trả lời luôn
        Http::assertNothingSent();
    }

    public function test_ngu_canh_gui_len_api_chi_chua_noi_dung_duoc_phep(): void
    {
        $this->makeCourseWithLesson('<p>NOIDUNGDUOCPHEP về bảo mật</p>');
        $this->makeCourseWithLesson('<p>NOIDUNGCAM về bảo mật</p>', enroll: false);

        $this->fakeGroq();

        app(AiAssistantService::class)->ask($this->employee, 'bảo mật');

        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            $this->assertStringContainsString('NOIDUNGDUOCPHEP', $body);
            $this->assertStringNotContainsString('NOIDUNGCAM', $body);

            return true;
        });
    }

    private function makeQuizQuestion(string $content, string $explanation, bool $enroll = true): Quiz
    {
        $course = Course::factory()->create(['status' => Course::STATUS_PUBLISHED]);

        $quiz = Quiz::create([
            'title' => 'Bài kiểm tra thử',
            'course_id' => $course->id,
            'pass_score' => 70,
            'status' => Quiz::STATUS_PUBLISHED,
        ]);

        $question = Question::create([
            'quiz_id' => $quiz->id,
            'content' => $content,
            'explanation' => $explanation,
            'type' => Question::TYPE_SINGLE,
            'score' => 1,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        QuestionOption::create([
            'question_id' => $question->id,
            'content' => 'DAPANDUNG_BIMAT',
            'is_correct' => true,
            'sort_order' => 0,
        ]);

        QuestionOption::create([
            'question_id' => $question->id,
            'content' => 'DAPANSAI_BIMAT',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        if ($enroll) {
            Enrollment::create([
                'course_id' => $course->id,
                'employee_id' => $this->employee->id,
                'status' => Enrollment::STATUS_IN_PROGRESS,
                'assigned_at' => now(),
                'total_lessons' => 1,
            ]);
        }

        return $quiz;
    }

    // ---- Trắc nghiệm --------------------------------------------------------

    public function test_lay_duoc_cau_hoi_va_giai_thich_trac_nghiem(): void
    {
        $this->makeQuizQuestion(
            'Mật khẩu an toàn cần gì?',
            'Cần đủ độ dài và kết hợp nhiều loại ký tự.'
        );

        $chunks = app(TrainingKnowledgeService::class)
            ->search($this->employee, 'mật khẩu an toàn');

        $quizChunks = $chunks->where('source', 'quiz');

        $this->assertTrue($quizChunks->isNotEmpty(), 'Phải lấy được nội dung ôn tập trắc nghiệm');
        $this->assertStringContainsString('đủ độ dài', $quizChunks->first()['body']);
    }

    public function test_khong_dua_dap_an_vao_ngu_canh(): void
    {
        // Đây là ràng buộc quan trọng nhất của phần trắc nghiệm: đưa đáp án vào
        // ngữ cảnh thì nhân viên chỉ cần hỏi trợ lý là xong bài kiểm tra
        $this->makeQuizQuestion(
            'Mật khẩu an toàn cần gì?',
            'Cần đủ độ dài và kết hợp nhiều loại ký tự.'
        );

        $this->fakeGroq();

        app(AiAssistantService::class)->ask($this->employee, 'mật khẩu an toàn');

        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('DAPANDUNG_BIMAT', $body);
            $this->assertStringNotContainsString('DAPANSAI_BIMAT', $body);

            return true;
        });
    }

    public function test_khong_lay_trac_nghiem_cua_khoa_chua_duoc_gan(): void
    {
        $this->makeQuizQuestion(
            'Câu hỏi khóa khác',
            'GIAITHICHKHOAKHAC nội dung mật',
            enroll: false
        );

        $chunks = app(TrainingKnowledgeService::class)
            ->search($this->employee, 'GIAITHICHKHOAKHAC');

        $this->assertTrue($chunks->where('source', 'quiz')->isEmpty());
    }

    public function test_bo_qua_cau_hoi_khong_co_giai_thich(): void
    {
        // Câu hỏi trần không kèm giải thích thì không giúp gì cho việc ôn tập
        $this->makeQuizQuestion('Câu hỏi về bảomatduynhat', '');

        $chunks = app(TrainingKnowledgeService::class)
            ->search($this->employee, 'bảomatduynhat');

        $this->assertTrue($chunks->where('source', 'quiz')->isEmpty());
    }

    public function test_noi_dung_on_tap_duoc_uu_tien_hon(): void
    {
        // Cùng một từ khóa xuất hiện ở cả bài giảng và câu hỏi ôn tập:
        // nội dung ôn tập phải xếp trên vì sát với thứ người học đang cần
        $this->makeCourseWithLesson('<p>phishing là tấn công lừa đảo</p>');
        $this->makeQuizQuestion('Nhận biết phishing thế nào?', 'phishing có dấu hiệu hối thúc gấp');

        $chunks = app(TrainingKnowledgeService::class)
            ->search($this->employee, 'phishing');

        $this->assertSame('quiz', $chunks->first()['source']);
    }

    // ---- Tổng hợp cấu trúc khóa học -----------------------------------------

    public function test_lay_duoc_cau_truc_khoa_hoc(): void
    {
        // Nhóm câu hỏi "khóa này có mấy bài", "còn bài nào chưa học" không trả
        // lời được từ nội dung từng bài riêng lẻ
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        $chunks = app(TrainingKnowledgeService::class)
            ->search($this->employee, 'khóa học có mấy bài');

        $this->assertTrue(
            $chunks->where('source', 'outline')->isNotEmpty(),
            'Phải có đoạn tổng hợp cấu trúc khóa học'
        );
    }

    public function test_cau_truc_khoa_hoc_ghi_trang_thai_tung_bai(): void
    {
        $course = $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        $outline = app(TrainingKnowledgeService::class)
            ->search($this->employee, $course->title)
            ->firstWhere('source', 'outline');

        $this->assertNotNull($outline);
        $this->assertStringContainsString('Bài giảng thử', $outline['body']);
        $this->assertStringContainsString('chưa hoàn thành', $outline['body']);
    }

    public function test_khong_lay_cau_truc_khoa_chua_duoc_gan(): void
    {
        $course = $this->makeCourseWithLesson('<p>Nội dung</p>', enroll: false);

        $chunks = app(TrainingKnowledgeService::class)
            ->search($this->employee, $course->title);

        $this->assertTrue($chunks->where('source', 'outline')->isEmpty());
    }

    public function test_bai_giang_dai_duoc_chia_nhieu_doan_khong_bi_cat(): void
    {
        // Cắt cụt nghĩa là nửa sau của bài không vào được ngữ cảnh: hỏi về phần
        // cuối bài thì AI nói không tìm thấy dù nội dung có thật
        $dau = str_repeat('Phần đầu nói về quy trình mở tài khoản. ', 60);
        $cuoi = 'DAUHIEUCUOIBAI là nội dung nằm ở cuối bài giảng.';

        $this->makeCourseWithLesson('<p>' . $dau . $cuoi . '</p>');

        $chunks = app(TrainingKnowledgeService::class)
            ->search($this->employee, 'DAUHIEUCUOIBAI');

        $allText = $chunks->pluck('body')->implode(' ');

        $this->assertStringContainsString('DAUHIEUCUOIBAI', $allText);
    }

    public function test_bai_ngan_khong_bi_chia(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung ngắn về bảo mật.</p>');

        $lessons = app(TrainingKnowledgeService::class)
            ->search($this->employee, 'bảo mật')
            ->where('source', 'lesson');

        $this->assertCount(1, $lessons);
        $this->assertStringNotContainsString('phần 1', $lessons->first()['title']);
    }

    // ---- Gọi API ------------------------------------------------------------

    public function test_tra_ve_cau_tra_loi_va_nguon(): void
    {
        $this->makeCourseWithLesson('<p>Rủi ro bảo mật gồm ba nhóm</p>');
        $this->fakeGroq('Có ba nhóm rủi ro.');

        $result = app(AiAssistantService::class)->ask($this->employee, 'rủi ro bảo mật');

        $this->assertSame('Có ba nhóm rủi ro.', $result['answer']);
        $this->assertTrue($result['grounded']);
        $this->assertNotEmpty($result['sources']);
    }

    public function test_lay_reasoning_khi_content_rong(): void
    {
        // gpt-oss-20b trả content rỗng và dồn hết vào reasoning
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '', 'reasoning' => 'Nội dung từ reasoning.']]],
            ]),
        ]);

        $result = app(AiAssistantService::class)->ask($this->employee, 'bảo mật');

        $this->assertSame('Nội dung từ reasoning.', $result['answer']);
    }

    public function test_loi_api_bao_loi_than_thien_khong_lo_chi_tiet(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fake([
            '*/chat/completions' => Http::response(
                ['error' => ['message' => 'Invalid API key xyz']],
                401
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Trợ lý AI đang bận');

        app(AiAssistantService::class)->ask($this->employee, 'bảo mật');
    }

    public function test_chua_cau_hinh_key_thi_bao_chua_bat(): void
    {
        config(['groq.api_key' => null]);

        $this->assertFalse(app(AiAssistantService::class)->isEnabled());

        $this->expectException(\RuntimeException::class);
        app(AiAssistantService::class)->ask($this->employee, 'câu hỏi');
    }

    // ---- Chuyển model dự phòng ----------------------------------------------

    /** Model nào được gọi trong request thứ $index. */
    private function modelCalledAt(int $index): string
    {
        $requests = Http::recorded();

        return $requests[$index][0]->data()['model'] ?? '';
    }

    public function test_model_chinh_hong_thi_chuyen_sang_du_phong(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fakeSequence()
            // Model chính bị khóa ở mức project
            ->push(['error' => ['message' => 'model is blocked at the project level']], 400)
            // Model dự phòng chạy được
            ->push(['choices' => [['message' => ['content' => 'Trả lời từ model dự phòng.']]]]);

        $result = app(AiAssistantService::class)->ask($this->employee, 'bảo mật');

        $this->assertSame('Trả lời từ model dự phòng.', $result['answer']);
        $this->assertSame('model-chinh', $this->modelCalledAt(0));
        $this->assertSame('model-du-phong-1', $this->modelCalledAt(1));
    }

    public function test_thu_lan_luot_het_danh_sach_du_phong(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fakeSequence()
            ->push(['error' => ['message' => 'lỗi']], 400)
            ->push(['error' => ['message' => 'lỗi']], 500)
            ->push(['choices' => [['message' => ['content' => 'Model cuối cùng trả lời.']]]]);

        $result = app(AiAssistantService::class)->ask($this->employee, 'bảo mật');

        $this->assertSame('Model cuối cùng trả lời.', $result['answer']);
        $this->assertSame('model-du-phong-2', $this->modelCalledAt(2));
    }

    public function test_model_tra_ve_rong_cung_bi_chuyen(): void
    {
        // gpt-oss-20b từng trả content rỗng — coi như model đó hỏng
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => '', 'reasoning' => '']]]])
            ->push(['choices' => [['message' => ['content' => 'Model sau trả lời được.']]]]);

        $result = app(AiAssistantService::class)->ask($this->employee, 'bảo mật');

        $this->assertSame('Model sau trả lời được.', $result['answer']);
    }

    public function test_tat_ca_model_hong_thi_bao_loi(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'hỏng']], 500)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Trợ lý AI đang bận');

        app(AiAssistantService::class)->ask($this->employee, 'bảo mật');
    }

    public function test_sai_khoa_api_thi_khong_thu_model_khac(): void
    {
        // 401 nghĩa là hỏng ở tài khoản — đổi model cũng lỗi y hệt, thử thêm
        // chỉ làm người dùng chờ vô ích
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fake([
            '*/chat/completions' => Http::response(['error' => ['message' => 'Invalid API Key']], 401),
        ]);

        try {
            app(AiAssistantService::class)->ask($this->employee, 'bảo mật');
            $this->fail('Phải ném ngoại lệ');
        } catch (\RuntimeException $e) {
            // đúng như mong đợi
        }

        $this->assertCount(1, Http::recorded(), 'Chỉ được gọi đúng một lần');
    }

    public function test_403_do_model_bi_khoa_thi_van_chuyen_du_phong(): void
    {
        // Kiểm chứng thật với groq/compound-mini: Groq trả 403 permissions_error
        // khi riêng model đó bị chặn ở mức project — đổi model là chạy được
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fakeSequence()
            ->push(['error' => ['message' => 'The model `x` is blocked at the project level']], 403)
            ->push(['choices' => [['message' => ['content' => 'Model dự phòng trả lời.']]]]);

        $result = app(AiAssistantService::class)->ask($this->employee, 'bảo mật');

        $this->assertSame('Model dự phòng trả lời.', $result['answer']);
        $this->assertCount(2, Http::recorded());
    }

    public function test_403_do_khoa_api_thi_khong_thu_model_khac(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fake([
            '*/chat/completions' => Http::response(
                ['error' => ['message' => 'Your API key does not have permission']],
                403
            ),
        ]);

        try {
            app(AiAssistantService::class)->ask($this->employee, 'bảo mật');
        } catch (\RuntimeException $e) {
            // đúng như mong đợi
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_cham_han_muc_cung_khong_thu_model_khac(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fake([
            '*/chat/completions' => Http::response(['error' => ['message' => 'Rate limit reached']], 429),
        ]);

        try {
            app(AiAssistantService::class)->ask($this->employee, 'bảo mật');
        } catch (\RuntimeException $e) {
            // đúng như mong đợi
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_model_hong_bi_tam_loai_o_cau_hoi_sau(): void
    {
        // Không nhớ model nào đang hỏng thì mỗi câu hỏi lại phải chờ nó lỗi lại
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fakeSequence()
            ->push(['error' => ['message' => 'hỏng']], 500)
            ->push(['choices' => [['message' => ['content' => 'Lần 1.']]]])
            ->push(['choices' => [['message' => ['content' => 'Lần 2.']]]]);

        $assistant = app(AiAssistantService::class);

        $assistant->ask($this->employee, 'bảo mật');
        $assistant->ask($this->employee, 'bảo mật');

        // Câu thứ hai phải đi thẳng tới model dự phòng, không thử lại model chính
        $this->assertSame('model-du-phong-1', $this->modelCalledAt(2));
        $this->assertCount(3, Http::recorded());
    }

    public function test_khong_co_du_phong_thi_van_chay_binh_thuong(): void
    {
        config(['groq.fallback_models' => []]);

        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');
        $this->fakeGroq('Trả lời.');

        $result = app(AiAssistantService::class)->ask($this->employee, 'bảo mật');

        $this->assertSame('Trả lời.', $result['answer']);
        $this->assertSame('model-chinh', $this->modelCalledAt(0));
    }

    // ---- Giao diện chat -----------------------------------------------------

    public function test_gui_cau_hoi_va_nhan_tra_loi(): void
    {
        $this->makeCourseWithLesson('<p>Rủi ro bảo mật gồm ba nhóm</p>');
        $this->fakeGroq('Có ba nhóm rủi ro.');

        $component = Livewire::actingAs($this->user)
            ->test(AiChat::class)
            ->set('question', 'rủi ro bảo mật')
            ->call('send');

        $messages = $component->get('messages');

        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('Có ba nhóm rủi ro.', $messages[1]['content']);
    }

    public function test_bam_cau_hoi_goi_y_chay_duoc(): void
    {
        // Livewire chỉ tiêm dependency khi chính nó gọi method — gọi $this->send()
        // trần từ useSuggestion() sẽ nổ ArgumentCountError
        $this->makeCourseWithLesson('<p>Nội dung về phishing lừa đảo</p>');
        $this->fakeGroq('Dấu hiệu nhận biết email lừa đảo.');

        $component = Livewire::actingAs($this->user)
            ->test(AiChat::class)
            ->call('useSuggestion', 'Làm sao nhận biết email lừa đảo?');

        $messages = $component->get('messages');

        $this->assertCount(2, $messages);
        $this->assertSame('Dấu hiệu nhận biết email lừa đảo.', $messages[1]['content']);
    }

    public function test_noi_dung_model_tra_ve_khong_chen_duoc_html(): void
    {
        // Nội dung do model sinh ra là dữ liệu ngoài, phải escape trước khi
        // đổi markdown sang HTML
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');
        $this->fakeGroq('<script>alert(1)</script> va **NOIDUNGDAM**');

        $html = Livewire::actingAs($this->user)
            ->test(AiChat::class)
            ->call('toggle')
            ->set('question', 'bảo mật')
            ->call('send')
            ->html();

        // Thẻ script bị vô hiệu hóa, còn markdown ** vẫn thành chữ đậm
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('<strong>NOIDUNGDAM</strong>', $html);
    }

    public function test_luu_lich_su_hoi_thoai(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');
        $this->fakeGroq('Trả lời.');

        Livewire::actingAs($this->user)
            ->test(AiChat::class)
            ->set('question', 'bảo mật')
            ->call('send');

        $conversation = AiConversation::where('employee_id', $this->employee->id)->first();

        $this->assertNotNull($conversation);
        $this->assertCount(2, $conversation->messages);
        $this->assertSame('bảo mật', $conversation->messages[0]->content);
        $this->assertSame('Trả lời.', $conversation->messages[1]->content);
    }

    public function test_cau_hoi_rong_thi_khong_lam_gi(): void
    {
        Http::fake();

        Livewire::actingAs($this->user)
            ->test(AiChat::class)
            ->set('question', '   ')
            ->call('send')
            ->assertSet('messages', []);

        Http::assertNothingSent();
    }

    public function test_chan_hoi_qua_nhanh(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');
        $this->fakeGroq();

        config(['groq.rate_limit_per_minute' => 2]);

        $component = Livewire::actingAs($this->user)->test(AiChat::class);

        for ($i = 0; $i < 3; $i++) {
            $component->set('question', "câu hỏi bảo mật {$i}")->call('send');
        }

        $this->assertStringContainsString('hỏi hơi nhanh', $component->get('errorMessage'));
    }

    public function test_loi_api_thi_giu_lai_cau_hoi_de_gui_lai(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');

        Http::fake(['*/chat/completions' => Http::response([], 500)]);

        $component = Livewire::actingAs($this->user)
            ->test(AiChat::class)
            ->set('question', 'bảo mật')
            ->call('send');

        // Câu hỏi phải quay lại ô nhập, không được mất
        $this->assertSame('bảo mật', $component->get('question'));
        $this->assertSame([], $component->get('messages'));
        $this->assertNotSame('', $component->get('errorMessage'));
    }

    public function test_bat_dau_hoi_thoai_moi_giu_lai_lich_su_cu(): void
    {
        $this->makeCourseWithLesson('<p>Nội dung bảo mật</p>');
        $this->fakeGroq();

        Livewire::actingAs($this->user)
            ->test(AiChat::class)
            ->set('question', 'bảo mật')
            ->call('send')
            ->call('startNew')
            ->assertSet('messages', [])
            ->assertSet('conversationId', null);

        // Hội thoại cũ vẫn còn trong CSDL
        $this->assertSame(1, AiConversation::count());
    }

    public function test_nhan_vien_khac_khong_thay_hoi_thoai_cua_nguoi_khac(): void
    {
        $other = Employee::factory()->create();
        AiConversation::create(['employee_id' => $other->id, 'title' => 'Của người khác']);

        $messages = Livewire::actingAs($this->user)
            ->test(AiChat::class)
            ->call('toggle')
            ->get('messages');

        $this->assertSame([], $messages);
    }
}
