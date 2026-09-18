<?php

namespace App\Livewire\Admin;

use App\Models\Course;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Services\FileStorageService;
use App\Services\QuizImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cấu hình bài kiểm tra trắc nghiệm (spec 3.2.3):
 * nhập câu hỏi trực tiếp trên giao diện hoặc import từ Excel theo template chuẩn.
 */
class QuizBuilder extends Component
{
    use WithPagination, WithFileUploads;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public ?int $editingQuizId = null;

    // --- Form bài kiểm tra ---
    public bool $showQuizModal = false;
    public ?int $quizFormId = null;
    public string $title = '';
    public string $description = '';
    public ?int $course_id = null;
    public ?int $questions_per_attempt = null;
    public ?int $duration_minutes = null;
    public int $pass_score = 70;
    public ?int $max_attempts = null;
    public bool $shuffle_questions = true;
    public bool $shuffle_options = true;
    public bool $show_result_immediately = true;
    public bool $show_correct_answers = false;

    // --- Form câu hỏi ---
    public bool $showQuestionModal = false;
    public ?int $questionId = null;
    public string $questionContent = '';
    public string $questionType = Question::TYPE_SINGLE;
    public float $questionScore = 1;
    public string $questionExplanation = '';
    /** @var array<int, array{content: string, is_correct: bool}> */
    public array $options = [];

    // --- Import Excel ---
    public bool $showImportModal = false;
    public $importFile = null;
    public array $importErrors = [];
    public array $importRows = [];

    public function mount(): void
    {
        $this->resetOptions();
    }

    public function render(): View
    {
        return view('livewire.admin.quiz-builder', [
            'quizzes' => $this->quizzes(),
            'courses' => Course::orderBy('title')->get(),
            'editingQuiz' => $this->editingQuiz(),
            'questions' => $this->questions(),
        ])->layout('layouts.admin', ['title' => 'Bài kiểm tra']);
    }

    public function updated($property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }
    }

    // ---- Bài kiểm tra -------------------------------------------------------

    public function createQuiz(): void
    {
        $this->resetQuizForm();
        $this->showQuizModal = true;
    }

    public function editQuiz(int $id): void
    {
        $quiz = Quiz::findOrFail($id);

        $this->quizFormId = $quiz->id;
        $this->title = $quiz->title;
        $this->description = (string) $quiz->description;
        $this->course_id = $quiz->course_id;
        $this->questions_per_attempt = $quiz->questions_per_attempt;
        $this->duration_minutes = $quiz->duration_minutes;
        $this->pass_score = $quiz->pass_score;
        $this->max_attempts = $quiz->max_attempts;
        $this->shuffle_questions = (bool) $quiz->shuffle_questions;
        $this->shuffle_options = (bool) $quiz->shuffle_options;
        $this->show_result_immediately = (bool) $quiz->show_result_immediately;
        $this->show_correct_answers = (bool) $quiz->show_correct_answers;

        $this->showQuizModal = true;
    }

    public function saveQuiz(): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'course_id' => ['nullable', Rule::exists('courses', 'id')],
            'questions_per_attempt' => ['nullable', 'integer', 'min:1'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'pass_score' => ['required', 'integer', 'min:0', 'max:100'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $payload = array_merge($data, [
            'shuffle_questions' => $this->shuffle_questions,
            'shuffle_options' => $this->shuffle_options,
            'show_result_immediately' => $this->show_result_immediately,
            'show_correct_answers' => $this->show_correct_answers,
        ]);

        if ($this->quizFormId) {
            Quiz::findOrFail($this->quizFormId)->update($payload);
            session()->flash('status', 'Đã cập nhật bài kiểm tra.');
        } else {
            $payload['created_by'] = auth()->id();
            $payload['status'] = Quiz::STATUS_DRAFT;
            $quiz = Quiz::create($payload);
            $this->editingQuizId = $quiz->id;
            session()->flash('status', 'Đã tạo bài kiểm tra. Hãy thêm câu hỏi.');
        }

        $this->showQuizModal = false;
        $this->resetQuizForm();
    }

    /** Xuất bản: chặn khi chưa đủ câu hỏi cho một lượt thi. */
    public function publishQuiz(int $id): void
    {
        $quiz = Quiz::withCount('activeQuestions')->findOrFail($id);

        if ($quiz->active_questions_count === 0) {
            session()->flash('error', 'Bài kiểm tra chưa có câu hỏi nào.');

            return;
        }

        if ($quiz->questions_per_attempt && $quiz->questions_per_attempt > $quiz->active_questions_count) {
            session()->flash('error', sprintf(
                'Ngân hàng chỉ có %d câu nhưng cấu hình rút %d câu mỗi lượt.',
                $quiz->active_questions_count,
                $quiz->questions_per_attempt,
            ));

            return;
        }

        $quiz->update(['status' => Quiz::STATUS_PUBLISHED]);
        session()->flash('status', 'Đã xuất bản bài kiểm tra.');
    }

    public function selectQuiz(int $id): void
    {
        $this->editingQuizId = $id;
    }

    public function backToList(): void
    {
        $this->editingQuizId = null;
    }

    // ---- Câu hỏi ------------------------------------------------------------

    public function createQuestion(): void
    {
        $this->resetQuestionForm();
        $this->showQuestionModal = true;
    }

    public function editQuestion(int $id): void
    {
        $question = Question::with('options')->findOrFail($id);

        $this->questionId = $question->id;
        $this->questionContent = $question->content;
        $this->questionType = $question->type;
        $this->questionScore = (float) $question->score;
        $this->questionExplanation = (string) $question->explanation;
        $this->options = $question->options
            ->map(fn ($o) => ['content' => $o->content, 'is_correct' => (bool) $o->is_correct])
            ->values()
            ->all();

        $this->showQuestionModal = true;
    }

    public function addOption(): void
    {
        $this->options[] = ['content' => '', 'is_correct' => false];
    }

    public function removeOption(int $index): void
    {
        unset($this->options[$index]);
        $this->options = array_values($this->options);
    }

    /**
     * Chọn đáp án đúng. Câu chọn 1 đáp án thì việc chọn ô mới phải bỏ ô cũ,
     * nếu không sẽ lưu được câu hỏi mâu thuẫn với chính loại của nó.
     */
    public function markCorrect(int $index): void
    {
        if ($this->questionType === Question::TYPE_MULTIPLE) {
            $this->options[$index]['is_correct'] = ! ($this->options[$index]['is_correct'] ?? false);

            return;
        }

        foreach ($this->options as $i => $option) {
            $this->options[$i]['is_correct'] = $i === $index;
        }
    }

    /** Đổi loại câu hỏi phải đồng bộ lại đáp án đúng cho khớp ràng buộc. */
    public function updatedQuestionType(string $value): void
    {
        if ($value === Question::TYPE_TRUE_FALSE) {
            $this->options = [
                ['content' => 'Đúng', 'is_correct' => true],
                ['content' => 'Sai', 'is_correct' => false],
            ];

            return;
        }

        if ($value !== Question::TYPE_MULTIPLE) {
            $firstCorrect = collect($this->options)->search(fn ($o) => $o['is_correct'] ?? false);

            foreach ($this->options as $i => $option) {
                $this->options[$i]['is_correct'] = $i === $firstCorrect;
            }
        }
    }

    public function saveQuestion(): void
    {
        $this->validate([
            'questionContent' => ['required', 'string', 'max:2000'],
            'questionType' => ['required', Rule::in([
                Question::TYPE_SINGLE, Question::TYPE_MULTIPLE, Question::TYPE_TRUE_FALSE,
            ])],
            'questionScore' => ['required', 'numeric', 'min:0.1'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.content' => ['required', 'string', 'max:1000'],
        ], [
            'options.min' => 'Câu hỏi cần ít nhất 2 đáp án.',
            'options.*.content.required' => 'Nội dung đáp án không được để trống.',
        ]);

        $correctCount = collect($this->options)->where('is_correct', true)->count();

        if ($correctCount === 0) {
            $this->addError('options', 'Phải chọn ít nhất một đáp án đúng.');

            return;
        }

        if ($this->questionType !== Question::TYPE_MULTIPLE && $correctCount > 1) {
            $this->addError('options', 'Loại câu hỏi này chỉ cho phép một đáp án đúng.');

            return;
        }

        DB::transaction(function () {
            $quiz = $this->editingQuiz();

            $question = $this->questionId
                ? Question::findOrFail($this->questionId)
                : new Question(['quiz_id' => $quiz->id, 'sort_order' => (int) $quiz->questions()->max('sort_order') + 1]);

            $question->fill([
                'content' => $this->questionContent,
                'type' => $this->questionType,
                'score' => $this->questionScore,
                'explanation' => $this->questionExplanation ?: null,
            ])->save();

            // Ghi lại toàn bộ đáp án: đơn giản và tránh lệch thứ tự khi admin
            // thêm/xoá ô giữa chừng. Lượt thi đã nộp không bị ảnh hưởng vì
            // quiz_attempt_answers giữ snapshot riêng.
            $question->options()->delete();

            foreach (array_values($this->options) as $index => $option) {
                QuestionOption::create([
                    'question_id' => $question->id,
                    'content' => $option['content'],
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                    'sort_order' => $index,
                ]);
            }
        });

        session()->flash('status', $this->questionId ? 'Đã cập nhật câu hỏi.' : 'Đã thêm câu hỏi.');

        $this->showQuestionModal = false;
        $this->resetQuestionForm();
    }

    public function deleteQuestion(int $id): void
    {
        Question::findOrFail($id)->delete();
        session()->flash('status', 'Đã xóa câu hỏi.');
    }

    public function toggleQuestionActive(int $id): void
    {
        $question = Question::findOrFail($id);
        $question->update(['is_active' => ! $question->is_active]);
    }

    // ---- Import Excel -------------------------------------------------------

    public function openImport(): void
    {
        $this->reset(['importFile', 'importErrors', 'importRows']);
        $this->showImportModal = true;
    }

    /** Đọc và kiểm tra file, hiển thị kết quả trước khi ghi (spec 3.2.3). */
    public function previewImport(QuizImportService $service): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:' . FileStorageService::MAX_FILE_KB],
        ]);

        $result = $service->preview($this->importFile->getRealPath());

        $this->importErrors = $result['errors'];
        $this->importRows = $result['rows'];
    }

    public function confirmImport(QuizImportService $service): void
    {
        if ($this->importErrors !== [] || $this->importRows === []) {
            return;
        }

        $count = $service->import($this->editingQuiz(), $this->importRows);

        session()->flash('status', "Đã import {$count} câu hỏi.");

        $this->showImportModal = false;
        $this->reset(['importFile', 'importErrors', 'importRows']);
    }

    /** Tải file mẫu để admin điền theo đúng cấu trúc. */
    public function downloadTemplate(QuizImportService $service): BinaryFileResponse
    {
        $rows = $service->templateRows();

        return Excel::download(new class($rows) implements \Maatwebsite\Excel\Concerns\FromArray {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        }, 'mau-import-cau-hoi.xlsx');
    }

    // ---- Truy vấn -----------------------------------------------------------

    private function quizzes()
    {
        return Quiz::query()
            ->with('course')
            ->withCount('activeQuestions')
            ->when($this->search, fn ($q) => $q->where('title', 'like', '%' . $this->search . '%'))
            ->orderByDesc('id')
            ->paginate(12);
    }

    private function editingQuiz(): ?Quiz
    {
        return $this->editingQuizId ? Quiz::find($this->editingQuizId) : null;
    }

    private function questions()
    {
        if (! $this->editingQuizId) {
            return collect();
        }

        return Question::with('options')
            ->where('quiz_id', $this->editingQuizId)
            ->orderBy('sort_order')
            ->get();
    }

    private function resetQuizForm(): void
    {
        $this->reset([
            'quizFormId', 'title', 'description', 'course_id',
            'questions_per_attempt', 'duration_minutes', 'max_attempts',
        ]);
        $this->pass_score = 70;
        $this->shuffle_questions = true;
        $this->shuffle_options = true;
        $this->show_result_immediately = true;
        $this->show_correct_answers = false;
        $this->resetValidation();
    }

    private function resetQuestionForm(): void
    {
        $this->reset(['questionId', 'questionContent', 'questionExplanation']);
        $this->questionType = Question::TYPE_SINGLE;
        $this->questionScore = 1;
        $this->resetOptions();
        $this->resetValidation();
    }

    /**
     * Hai ô đáp án trống, chưa đánh dấu đáp án đúng nào.
     * Không đặt sẵn ô đầu là đúng: admin sẽ tưởng đã chọn rồi và bỏ qua bước
     * đánh dấu, tạo ra câu hỏi có đáp án đúng nằm sai chỗ.
     */
    private function resetOptions(): void
    {
        $this->options = [
            ['content' => '', 'is_correct' => false],
            ['content' => '', 'is_correct' => false],
        ];
    }
}
