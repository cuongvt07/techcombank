<?php

namespace App\Livewire\User;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Employee;
use App\Services\AiAssistantService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Component;
use RuntimeException;

/**
 * Popup chat với trợ lý AI đào tạo (spec 4.3).
 *
 * Đặt trong layout người dùng nên có mặt ở mọi trang; mở bằng nút tròn góc
 * dưới phải.
 */
class AiChat extends Component
{
    public bool $open = false;
    public string $question = '';
    public ?int $conversationId = null;

    /** @var array<int, array{role: string, content: string, sources: array}> */
    public array $messages = [];

    public string $errorMessage = '';

    public function render(): View
    {
        return view('livewire.user.ai-chat', [
            'enabled' => app(AiAssistantService::class)->isEnabled(),
            'suggestions' => $this->suggestions(),
        ]);
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;

        if ($this->open && $this->messages === []) {
            $this->loadLatestConversation();
        }
    }

    /** Câu hỏi gợi ý cho người chưa biết bắt đầu từ đâu. */
    private function suggestions(): array
    {
        return [
            'Ôn tập giúp tôi phần an toàn thông tin',
            'Làm sao nhận biết email lừa đảo?',
            'Phát hiện rò rỉ dữ liệu thì xử lý thế nào?',
        ];
    }

    private function employee(): ?Employee
    {
        return auth()->user()?->employee;
    }

    /** Nạp lại hội thoại gần nhất để người dùng đọc tiếp mạch cũ. */
    private function loadLatestConversation(): void
    {
        $employee = $this->employee();

        if (! $employee) {
            return;
        }

        $conversation = AiConversation::where('employee_id', $employee->id)
            ->latest('updated_at')
            ->first();

        if (! $conversation) {
            return;
        }

        $this->conversationId = $conversation->id;
        $this->messages = $conversation->messages()
            ->latest('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn (AiMessage $m) => [
                'role' => $m->role,
                'content' => $m->content,
                'sources' => $m->sources ?? [],
            ])
            ->values()
            ->all();
    }

    public function useSuggestion(string $text, AiAssistantService $assistant): void
    {
        // Livewire chỉ tiêm tham số khi chính nó gọi method, nên phải nhận
        // $assistant ở đây rồi truyền tay sang send()
        $this->question = $text;
        $this->send($assistant);
    }

    public function send(AiAssistantService $assistant): void
    {
        $this->errorMessage = '';

        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $employee = $this->employee();

        if (! $employee) {
            $this->errorMessage = 'Tài khoản của bạn chưa gắn với hồ sơ nhân viên.';

            return;
        }

        // Chặn hỏi dồn dập: mỗi lượt là một lần gọi API tốn phí
        $key = 'ai-chat:' . $employee->id;
        $limit = (int) config('groq.rate_limit_per_minute', 10);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $seconds = RateLimiter::availableIn($key);
            $this->errorMessage = "Bạn hỏi hơi nhanh. Thử lại sau {$seconds} giây.";

            return;
        }

        RateLimiter::hit($key, 60);

        // Hiện câu hỏi ngay để người dùng thấy phản hồi tức thì
        $this->messages[] = ['role' => 'user', 'content' => $question, 'sources' => []];
        $this->question = '';

        try {
            $result = $assistant->ask($employee, $question, $this->historyForContext());
        } catch (RuntimeException $e) {
            $this->errorMessage = $e->getMessage();
            // Bỏ câu hỏi vừa thêm để người dùng gửi lại được
            array_pop($this->messages);
            $this->question = $question;

            return;
        }

        $this->messages[] = [
            'role' => 'assistant',
            'content' => $result['answer'],
            'sources' => $result['sources'],
        ];

        $this->persist($employee, $question, $result);
    }

    /** Vài lượt gần nhất, bỏ nguồn để không nhét rác vào prompt. */
    private function historyForContext(): Collection
    {
        return collect($this->messages)
            ->slice(0, -1)
            ->map(fn (array $m) => ['role' => $m['role'], 'content' => $m['content']])
            ->values();
    }

    private function persist(Employee $employee, string $question, array $result): void
    {
        $conversation = $this->conversationId
            ? AiConversation::find($this->conversationId)
            : null;

        if (! $conversation) {
            $conversation = AiConversation::create([
                'employee_id' => $employee->id,
                'title' => mb_substr($question, 0, 120),
            ]);

            $this->conversationId = $conversation->id;
        }

        $conversation->messages()->create([
            'role' => AiMessage::ROLE_USER,
            'content' => $question,
        ]);

        $conversation->messages()->create([
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $result['answer'],
            'sources' => $result['sources'],
        ]);

        $conversation->touch();
    }

    /** Bắt đầu hội thoại mới, giữ lại hội thoại cũ trong CSDL. */
    public function startNew(): void
    {
        $this->reset(['messages', 'conversationId', 'question', 'errorMessage']);
    }
}
