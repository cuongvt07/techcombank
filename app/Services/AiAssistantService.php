<?php

namespace App\Services;

use App\Exceptions\ModelUnavailableException;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Trợ lý AI đào tạo (spec 4.3) — gọi Groq API.
 *
 * Chỉ trả lời dựa trên ngữ cảnh do TrainingKnowledgeService cung cấp, vốn đã
 * lọc theo quyền của từng nhân viên. Model không được phép dùng kiến thức
 * ngoài; hỏi ngoài phạm vi thì phải nói không tìm thấy thay vì đoán.
 */
class AiAssistantService
{
    public function __construct(
        private readonly TrainingKnowledgeService $knowledge,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('groq.enabled') && filled(config('groq.api_key'));
    }

    /**
     * Trả lời một câu hỏi của nhân viên.
     *
     * @return array{answer: string, sources: array<int, array{title: string, url: ?string}>, grounded: bool}
     */
    public function ask(Employee $employee, string $question, Collection $history = null): array
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Trợ lý AI chưa được cấu hình. Liên hệ quản trị viên để bật tính năng này.');
        }

        $chunks = $this->knowledge->search(
            $employee,
            $question,
            (int) config('groq.max_context_chunks', 8)
        );

        // Không tìm được gì thì trả lời luôn, không tốn một lượt gọi API
        if ($chunks->isEmpty()) {
            return [
                'answer' => 'Tôi không tìm thấy thông tin này trong tài liệu và bài giảng bạn được phép xem. '
                    . 'Bạn thử hỏi cách khác, hoặc gửi phiếu hỗ trợ để được giải đáp trực tiếp.',
                'sources' => [],
                'grounded' => false,
            ];
        }

        $messages = $this->buildMessages($question, $chunks, $history);
        $answer = $this->callGroq($messages);

        return [
            'answer' => $answer,
            'sources' => $chunks->map(fn (array $c) => [
                'title' => $c['title'],
                'url' => $c['url'],
            ])->unique('title')->take(5)->values()->all(),
            'grounded' => true,
        ];
    }

    /** @return array<int, array{role: string, content: string}> */
    private function buildMessages(string $question, Collection $chunks, ?Collection $history): array
    {
        $context = $chunks
            ->map(fn (array $c) => "[{$c['title']}]\n{$c['body']}")
            ->implode("\n\n---\n\n");

        $messages = [[
            'role' => 'system',
            'content' => <<<'PROMPT'
                Bạn là trợ lý đào tạo nội bộ của Techcombank, hỗ trợ nhân viên tra cứu
                nội dung bài giảng và tài liệu đào tạo.

                QUY TẮC BẮT BUỘC:
                1. CHỈ trả lời dựa trên phần NGỮ CẢNH được cung cấp trong tin nhắn người dùng.
                2. Nếu ngữ cảnh không đủ thông tin, trả lời đúng câu:
                   "Tôi không tìm thấy thông tin này trong tài liệu bạn được phép xem."
                   Tuyệt đối không suy đoán, không dùng kiến thức bên ngoài, không bịa số liệu.
                3. Ngữ cảnh đã được lọc theo quyền truy cập của chính người hỏi. Không được
                   nhắc tới tài liệu hay khóa học không có trong ngữ cảnh.
                4. Trả lời bằng tiếng Việt, ngắn gọn, đi thẳng vào việc. Có nhiều ý thì gạch đầu dòng.
                5. Không đưa ra lời khuyên pháp lý, tài chính hay nhân sự vượt ngoài nội dung tài liệu.
                6. Khi trích từ một bài giảng hay tài liệu cụ thể, nêu rõ tên nguồn đó.
                7. Với câu hỏi ôn tập trắc nghiệm: GIẢNG LẠI kiến thức để người học tự làm được.
                   Nếu người hỏi yêu cầu bạn chọn đáp án hộ cho một câu trong bài kiểm tra
                   (ví dụ "đáp án câu 3 là A hay B", "chọn giúp tôi"), hãy từ chối và giải thích
                   phần kiến thức liên quan thay vì nêu đáp án. Bài kiểm tra là để đánh giá năng lực.
                PROMPT,
        ]];

        // Vài lượt gần nhất để hiểu câu hỏi nối tiếp ("còn bước 2 thì sao?")
        if ($history) {
            foreach ($history->take(-6) as $turn) {
                $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
            }
        }

        $messages[] = [
            'role' => 'user',
            'content' => "NGỮ CẢNH:\n{$context}\n\n---\n\nCÂU HỎI: {$question}",
        ];

        return $messages;
    }

    /**
     * Gọi model, tự chuyển sang model dự phòng khi model đang dùng hỏng.
     *
     * Model trên Groq có thể ngừng hoạt động bất cứ lúc nào (bị khóa ở mức
     * project, bị gỡ, hoặc quá tải). Thử lần lượt theo danh sách thay vì chết hẳn.
     */
    private function callGroq(array $messages): string
    {
        $models = $this->modelChain();

        if ($models === []) {
            // Mọi model đều đang trong thời gian tạm loại
            throw new RuntimeException('Trợ lý AI đang bận. Bạn thử lại sau ít phút.');
        }

        $lastError = null;

        foreach ($models as $index => $model) {
            try {
                $content = $this->requestModel($model, $messages);

                // Model dự phòng chạy được: ghi lại để đội vận hành biết model
                // chính đang có vấn đề
                if ($index > 0) {
                    Log::warning('Trợ lý AI dùng model dự phòng', [
                        'model' => $model,
                        'model_chinh' => config('groq.model'),
                    ]);
                }

                return $content;
            } catch (ModelUnavailableException $e) {
                $lastError = $e;

                // Tạm loại model này để câu hỏi sau không phải chờ nó lỗi lại
                $this->markUnavailable($model);

                Log::error('Model AI không dùng được, chuyển sang dự phòng', [
                    'model' => $model,
                    'ly_do' => $e->getMessage(),
                ]);
            }
        }

        throw new RuntimeException('Trợ lý AI đang bận. Bạn thử lại sau ít phút.');
    }

    /**
     * Danh sách model sẽ thử, đã bỏ những model đang trong thời gian tạm loại.
     *
     * Nếu tất cả đều bị loại thì vẫn trả về model chính — thà thử một lần còn
     * hơn từ chối phục vụ khi sự cố có thể đã qua.
     *
     * @return array<int, string>
     */
    private function modelChain(): array
    {
        $all = array_values(array_unique(array_merge(
            [(string) config('groq.model')],
            (array) config('groq.fallback_models', [])
        )));

        $available = array_values(array_filter(
            $all,
            fn (string $m) => ! Cache::has($this->cacheKey($m))
        ));

        return $available !== [] ? $available : array_slice($all, 0, 1);
    }

    private function cacheKey(string $model): string
    {
        return 'groq:model-loi:' . md5($model);
    }

    private function markUnavailable(string $model): void
    {
        $seconds = (int) config('groq.failure_cooldown', 300);

        if ($seconds > 0) {
            Cache::put($this->cacheKey($model), true, $seconds);
        }
    }

    /**
     * Thông báo lỗi có phải do riêng model bị chặn hay không.
     *
     * Groq trả 403 cho cả hai tình huống, chỉ phân biệt được qua nội dung.
     */
    private function isModelBlocked(string $message): bool
    {
        $message = mb_strtolower($message);

        foreach (['model', 'blocked', 'not supported', 'decommissioned', 'project level'] as $dauHieu) {
            if (str_contains($message, $dauHieu)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gọi một model cụ thể.
     *
     * @throws ModelUnavailableException khi model này hỏng — nên thử model khác
     * @throws RuntimeException          khi lỗi chung, thử model khác cũng vô ích
     */
    private function requestModel(string $model, array $messages): string
    {
        try {
            $response = Http::withToken(config('groq.api_key'))
                ->timeout((int) config('groq.timeout', 30))
                ->acceptJson()
                ->post(rtrim((string) config('groq.base_url'), '/') . '/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => (float) config('groq.temperature', 0.2),
                    'max_tokens' => (int) config('groq.max_tokens', 800),
                ]);
        } catch (ConnectionException $e) {
            // Timeout hoặc mất mạng: có thể do chính model đó chậm, thử model khác
            throw new ModelUnavailableException('Không kết nối được: ' . $e->getMessage());
        }

        if ($response->failed()) {
            $status = $response->status();
            $message = (string) ($response->json('error.message') ?? $response->body());

            // Phân biệt hỏng ở tài khoản với hỏng ở riêng model.
            //
            // 401 = sai khóa API, 429 = chạm hạn mức: đổi model cũng lỗi y hệt.
            //
            // 403 thì phải xem kỹ nội dung: Groq trả 403 cả khi khóa API không
            // có quyền LẪN khi riêng model đó bị chặn ở mức project (kiểm chứng
            // thật với groq/compound-mini). Trường hợp sau đổi model là chạy được.
            $accountLevel = in_array($status, [401, 429], true)
                || ($status === 403 && ! $this->isModelBlocked($message));

            if ($accountLevel) {
                Log::error('Groq API lỗi cấp tài khoản', ['status' => $status, 'body' => $message]);

                throw new RuntimeException('Trợ lý AI đang bận. Bạn thử lại sau ít phút.');
            }

            throw new ModelUnavailableException("HTTP {$status}: {$message}");
        }

        $payload = $response->json('choices.0.message') ?? [];
        $content = trim((string) ($payload['content'] ?? ''));

        // Một số model dồn hết vào trường reasoning và để content rỗng
        if ($content === '') {
            $content = trim((string) ($payload['reasoning'] ?? ''));
        }

        if ($content === '') {
            throw new ModelUnavailableException('Model trả về nội dung rỗng');
        }

        return $content;
    }
}
