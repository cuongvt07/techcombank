<div>
    @if (! $editingQuiz)
        {{-- Danh sách bài kiểm tra --}}
        <x-admin.page-header title="Bài kiểm tra"
                             subtitle="Ngân hàng câu hỏi, cấu hình đề thi và số lần làm bài.">
            <x-slot:actions>
                <button type="button" wire:click="createQuiz" class="admin-btn">
                    @svg('heroicon-o-plus', 'h-4 w-4')
                    Tạo bài kiểm tra
                </button>
            </x-slot:actions>
        </x-admin.page-header>

        <div class="admin-panel">
            <div class="border-b border-brand-line p-4">
                <input type="search" wire:model.live.debounce.400ms="search" class="admin-input max-w-md"
                       placeholder="Tìm theo tên bài kiểm tra...">
            </div>

            <div class="overflow-x-auto">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Bài kiểm tra</th>
                            <th>Khóa học</th>
                            <th class="w-24">Số câu</th>
                            <th class="w-28">Điểm đạt</th>
                            <th class="w-32">Thời gian</th>
                            <th class="w-28">Trạng thái</th>
                            <th class="w-32 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($quizzes as $quiz)
                            <tr wire:key="quiz-{{ $quiz->id }}">
                                <td>
                                    <button type="button" wire:click="selectQuiz({{ $quiz->id }})"
                                            class="text-left font-bold hover:text-brand-red">
                                        {{ $quiz->title }}
                                    </button>
                                </td>
                                <td class="text-brand-muted">{{ $quiz->course?->title ?? 'Dùng chung' }}</td>
                                <td>
                                    <span class="tag">{{ $quiz->active_questions_count }} câu</span>
                                    @if ($quiz->questions_per_attempt)
                                        <span class="mt-1 block text-[11px] text-brand-muted">
                                            rút {{ $quiz->questions_per_attempt }}/lượt
                                        </span>
                                    @endif
                                </td>
                                <td class="font-bold">{{ $quiz->pass_score }}%</td>
                                <td class="text-brand-muted">
                                    {{ $quiz->duration_minutes ? $quiz->duration_minutes . ' phút' : 'Không giới hạn' }}
                                </td>
                                <td>
                                    <span class="tag {{ $quiz->status === 'published' ? 'tag-green' : '' }}">
                                        {{ $quiz->status === 'published' ? 'Đã xuất bản' : 'Nháp' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-admin.icon-button icon="heroicon-o-list-bullet" label="Ngân hàng câu hỏi"
                                                             wire:click="selectQuiz({{ $quiz->id }})" />
                                        <x-admin.icon-button icon="heroicon-o-cog-6-tooth" label="Cấu hình đề thi"
                                                             wire:click="editQuiz({{ $quiz->id }})" />
                                        @if ($quiz->status !== 'published')
                                            <x-admin.icon-button icon="heroicon-o-paper-airplane" label="Xuất bản bài kiểm tra"
                                                                 variant="primary"
                                                                 wire:click="publishQuiz({{ $quiz->id }})" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-brand-muted">Chưa có bài kiểm tra nào.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($quizzes->hasPages())
                <div class="border-t border-brand-line p-4">{{ $quizzes->links() }}</div>
            @endif
        </div>
    @else
        {{-- Màn hình soạn câu hỏi của một bài kiểm tra --}}
        <div class="mb-4">
            <button type="button" wire:click="backToList"
                    class="mb-3 inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold text-brand-muted hover:text-brand-red">
                @svg('heroicon-o-chevron-left', 'h-4 w-4')
                Về danh sách bài kiểm tra
            </button>
        </div>

        <x-admin.page-header :title="$editingQuiz->title"
                             subtitle="Ngân hàng câu hỏi. Nhập trực tiếp hoặc import từ Excel.">
            <x-slot:actions>
                <button type="button" wire:click="downloadTemplate" class="admin-btn-secondary">
                    @svg('heroicon-o-arrow-down-tray', 'h-4 w-4')
                    File mẫu
                </button>
                <button type="button" wire:click="openImport" class="admin-btn-secondary">
                    @svg('heroicon-o-arrow-up-tray', 'h-4 w-4')
                    Import Excel
                </button>
                <button type="button" wire:click="createQuestion" class="admin-btn">
                    @svg('heroicon-o-plus', 'h-4 w-4')
                    Thêm câu hỏi
                </button>
            </x-slot:actions>
        </x-admin.page-header>

        {{-- Tóm tắt cấu hình đề, để admin thấy ngay ràng buộc khi soạn câu hỏi --}}
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="admin-panel p-3.5">
                <span class="text-[11px] font-semibold uppercase text-brand-muted">Tổng câu hỏi</span>
                <strong class="mt-1 block text-2xl leading-none">{{ $questions->count() }}</strong>
            </div>
            <div class="admin-panel p-3.5">
                <span class="text-[11px] font-semibold uppercase text-brand-muted">Rút mỗi lượt</span>
                <strong class="mt-1 block text-2xl leading-none">
                    {{ $editingQuiz->questions_per_attempt ?? 'Tất cả' }}
                </strong>
            </div>
            <div class="admin-panel p-3.5">
                <span class="text-[11px] font-semibold uppercase text-brand-muted">Điểm đạt</span>
                <strong class="mt-1 block text-2xl leading-none">{{ $editingQuiz->pass_score }}%</strong>
            </div>
            <div class="admin-panel p-3.5">
                <span class="text-[11px] font-semibold uppercase text-brand-muted">Số lần thi</span>
                <strong class="mt-1 block text-2xl leading-none">
                    {{ $editingQuiz->max_attempts ?? '∞' }}
                </strong>
            </div>
        </div>

        <div class="grid gap-3">
            @forelse ($questions as $index => $question)
                <div class="admin-panel p-4" wire:key="q-{{ $question->id }}">
                    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 gap-3">
                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-admin bg-brand-soft text-xs font-bold">
                                {{ $index + 1 }}
                            </span>
                            <div class="min-w-0">
                                <p class="font-bold {{ $question->is_active ? '' : 'text-brand-muted line-through' }}">
                                    {{ $question->content }}
                                </p>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <span class="tag">
                                        @switch($question->type)
                                            @case('single') Chọn 1 đáp án @break
                                            @case('multiple') Chọn nhiều đáp án @break
                                            @default Đúng / Sai
                                        @endswitch
                                    </span>
                                    <span class="tag">{{ rtrim(rtrim((string) $question->score, '0'), '.') }} điểm</span>
                                    @unless ($question->is_active)
                                        <span class="tag tag-amber">Đang tắt</span>
                                    @endunless
                                </div>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1.5">
                            <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa câu hỏi"
                                                 wire:click="editQuestion({{ $question->id }})" />
                            <x-admin.icon-button
                                :icon="$question->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye'"
                                :label="$question->is_active ? 'Tắt câu hỏi' : 'Bật câu hỏi'"
                                wire:click="toggleQuestionActive({{ $question->id }})" />
                            <x-admin.icon-button icon="heroicon-o-trash" label="Xóa câu hỏi"
                                                 variant="danger"
                                                 wire:click="deleteQuestion({{ $question->id }})"
                                                 wire:confirm="Xóa câu hỏi này?" />
                        </div>
                    </div>

                    <div class="grid gap-1.5 pl-10">
                        @foreach ($question->options as $option)
                            <div class="flex items-center gap-2 text-[13px]">
                                <span @class([
                                    'grid h-5 w-5 shrink-0 place-items-center rounded-admin-sm border text-white',
                                    'border-state-success bg-state-success' => $option->is_correct,
                                    'border-brand-line bg-white' => ! $option->is_correct,
                                ])>
                                    @if ($option->is_correct)
                                        @svg('heroicon-o-check', 'h-3 w-3 stroke-[3]')
                                    @endif
                                </span>
                                <span class="{{ $option->is_correct ? 'font-bold' : 'text-brand-muted' }}">
                                    {{ $option->content }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    @if ($question->explanation)
                        <p class="mt-2.5 border-t border-brand-line pl-10 pt-2.5 text-xs text-brand-muted">
                            <strong>Giải thích:</strong> {{ $question->explanation }}
                        </p>
                    @endif
                </div>
            @empty
                <div class="admin-panel p-10 text-center">
                    <p class="font-bold">Chưa có câu hỏi nào.</p>
                    <p class="mt-1 text-sm text-brand-muted">
                        Thêm trực tiếp trên giao diện, hoặc tải file mẫu rồi import từ Excel.
                    </p>
                </div>
            @endforelse
        </div>
    @endif

    {{-- Modal cấu hình bài kiểm tra --}}
    @if ($showQuizModal)
        <x-admin.modal wireModel="showQuizModal"
                       :title="$quizFormId ? 'Cấu hình bài kiểm tra' : 'Tạo bài kiểm tra'"
                       maxWidth="max-w-3xl">
            <form wire:submit="saveQuiz" id="quiz-form" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="q_title" class="admin-label">Tên bài kiểm tra</label>
                    <input id="q_title" type="text" wire:model="title" class="admin-input">
                    @error('title') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="q_desc" class="admin-label">Mô tả</label>
                    <textarea id="q_desc" wire:model="description" rows="2" class="admin-input !h-auto py-2.5"></textarea>
                </div>

                <div class="sm:col-span-2">
                    <label for="q_course" class="admin-label">Thuộc khóa học</label>
                    <select id="q_course" wire:model="course_id" class="admin-input">
                        <option value="">— Dùng chung, không thuộc khóa nào —</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}">{{ $course->title }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="q_perattempt" class="admin-label">Số câu mỗi lượt thi</label>
                    <input id="q_perattempt" type="number" wire:model="questions_per_attempt" class="admin-input"
                           placeholder="Để trống = dùng tất cả">
                    @error('questions_per_attempt') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="q_duration" class="admin-label">Thời gian làm bài (phút)</label>
                    <input id="q_duration" type="number" wire:model="duration_minutes" class="admin-input"
                           placeholder="Để trống = không giới hạn">
                    @error('duration_minutes') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="q_pass" class="admin-label">Điểm đạt (%)</label>
                    <input id="q_pass" type="number" wire:model="pass_score" class="admin-input">
                    @error('pass_score') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="q_attempts" class="admin-label">Số lần thi tối đa</label>
                    <input id="q_attempts" type="number" wire:model="max_attempts" class="admin-input"
                           placeholder="Để trống = không giới hạn">
                    @error('max_attempts') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-2.5 border-t border-brand-line pt-4 sm:col-span-2">
                    <p class="admin-label !mb-0">Tùy chọn khi làm bài</p>

                    <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium">
                        <input type="checkbox" wire:model="shuffle_questions"
                               class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        Trộn thứ tự câu hỏi
                    </label>

                    <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium">
                        <input type="checkbox" wire:model="shuffle_options"
                               class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        Trộn thứ tự đáp án
                    </label>

                    <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium">
                        <input type="checkbox" wire:model="show_result_immediately"
                               class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        Hiện kết quả ngay sau khi nộp
                    </label>

                    <label class="flex cursor-pointer items-start gap-2.5 text-sm font-medium">
                        <input type="checkbox" wire:model="show_correct_answers"
                               class="mt-0.5 h-4 w-4 shrink-0 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        <span>
                            Cho xem đáp án đúng sau khi nộp
                            <span class="mt-0.5 block text-xs font-normal text-brand-muted">
                                Cân nhắc tắt để tránh lộ ngân hàng câu hỏi qua các lượt thi lại.
                            </span>
                        </span>
                    </label>
                </div>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showQuizModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="quiz-form" class="admin-btn">
                    {{ $quizFormId ? 'Lưu thay đổi' : 'Tạo bài kiểm tra' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Modal soạn câu hỏi --}}
    @if ($showQuestionModal)
        <x-admin.modal wireModel="showQuestionModal"
                       :title="$questionId ? 'Sửa câu hỏi' : 'Thêm câu hỏi'"
                       maxWidth="max-w-3xl">
            <form wire:submit="saveQuestion" id="question-form" class="grid gap-4">
                <div>
                    <label for="qc" class="admin-label">Nội dung câu hỏi</label>
                    <textarea id="qc" wire:model="questionContent" rows="2" class="admin-input !h-auto py-2.5"></textarea>
                    @error('questionContent') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="qt" class="admin-label">Loại câu hỏi</label>
                        <select id="qt" wire:model.live="questionType" class="admin-input">
                            <option value="single_choice">Chọn 1 đáp án</option>
                            <option value="multiple_choice">Chọn nhiều đáp án</option>
                            <option value="true_false">Đúng / Sai</option>
                        </select>
                    </div>

                    <div>
                        <label for="qs" class="admin-label">Điểm</label>
                        <input id="qs" type="number" step="0.5" wire:model="questionScore" class="admin-input">
                        @error('questionScore') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <span class="admin-label !mb-0">Đáp án</span>
                        @if ($questionType !== 'true_false')
                            <button type="button" wire:click="addOption"
                                    class="whitespace-nowrap text-xs font-semibold text-brand-red hover:underline">+ Thêm đáp án</button>
                        @endif
                    </div>

                    <p class="mb-2 text-xs text-brand-muted">
                        Bấm ô vuông bên trái để đánh dấu đáp án đúng.
                        @if ($questionType === 'multiple_choice')
                            Câu chọn nhiều đáp án: người học phải chọn đúng trọn bộ mới được tính điểm.
                        @endif
                    </p>

                    <div class="grid gap-2">
                        @foreach ($options as $index => $option)
                            <div class="flex items-center gap-2" wire:key="opt-{{ $index }}">
                                <button type="button" wire:click="markCorrect({{ $index }})"
                                        @class([
                                            'grid h-9 w-9 shrink-0 place-items-center rounded-admin border transition-colors',
                                            'border-state-success bg-state-success text-white' => $option['is_correct'] ?? false,
                                            'border-brand-line bg-white text-transparent hover:border-state-success' => ! ($option['is_correct'] ?? false),
                                        ])
                                        aria-label="Đánh dấu đáp án đúng">
                                    @svg('heroicon-o-check', 'h-4 w-4 stroke-[3]')
                                </button>

                                <input type="text" wire:model="options.{{ $index }}.content"
                                       class="admin-input" placeholder="Nội dung đáp án..."
                                       @readonly($questionType === 'true_false')>

                                @if ($questionType !== 'true_false' && count($options) > 2)
                                    <button type="button" wire:click="removeOption({{ $index }})"
                                            class="grid h-9 w-9 shrink-0 place-items-center rounded-admin text-brand-muted transition-colors hover:bg-brand-red-tint hover:text-brand-red"
                                            aria-label="Xóa đáp án">
                                        @svg('heroicon-o-trash', 'h-4 w-4')
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @error('options') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    @error('options.*.content') <p class="mt-1.5 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="qe" class="admin-label">Giải thích (hiện sau khi nộp, nếu được bật)</label>
                    <textarea id="qe" wire:model="questionExplanation" rows="2" class="admin-input !h-auto py-2.5"></textarea>
                </div>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showQuestionModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="question-form" class="admin-btn">
                    {{ $questionId ? 'Lưu câu hỏi' : 'Thêm câu hỏi' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Modal import Excel --}}
    @if ($showImportModal)
        <x-admin.modal wireModel="showImportModal" title="Import câu hỏi từ Excel" maxWidth="max-w-3xl">
            <div class="grid gap-4">
                <div class="rounded-admin border border-brand-line bg-brand-soft p-3.5 text-sm">
                    <p class="font-bold">Cấu trúc file</p>
                    <p class="mt-1 text-brand-muted">
                        Mỗi dòng là một câu hỏi, gồm các cột:
                        <code class="font-mono text-xs">noi_dung</code>,
                        <code class="font-mono text-xs">loai</code>,
                        <code class="font-mono text-xs">dap_an_a</code>…<code class="font-mono text-xs">dap_an_f</code>,
                        <code class="font-mono text-xs">dap_an_dung</code>,
                        <code class="font-mono text-xs">diem</code>,
                        <code class="font-mono text-xs">giai_thich</code>.
                        Cột <code class="font-mono text-xs">dap_an_dung</code> ghi chữ cái đáp án,
                        nhiều đáp án ngăn bằng dấu phẩy (VD: <code class="font-mono text-xs">A,C</code>).
                    </p>
                    <button type="button" wire:click="downloadTemplate"
                            class="mt-2.5 text-xs font-semibold text-brand-red hover:underline">
                        Tải file mẫu
                    </button>
                </div>

                <div>
                    <label for="imp" class="admin-label">Chọn file Excel</label>
                    <input id="imp" type="file" wire:model="importFile"
                           class="admin-input !h-auto !py-2 file:mr-3 file:rounded-admin file:border-0 file:bg-brand-soft file:px-3 file:py-1.5 file:text-xs file:font-bold">
                    @error('importFile') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    <div wire:loading wire:target="importFile" class="mt-1 text-xs font-semibold text-brand-muted">Đang tải file...</div>
                </div>

                @if ($importFile)
                    <button type="button" wire:click="previewImport" class="admin-btn-secondary justify-self-start">
                        Kiểm tra file
                    </button>
                @endif

                {{-- Báo lỗi kèm số dòng để admin sửa đúng chỗ trong file --}}
                @if ($importErrors)
                    <div class="rounded-admin border border-brand-red bg-brand-red-tint p-3.5">
                        <p class="text-sm font-semibold text-brand-red">
                            File có {{ count($importErrors) }} lỗi — chưa import câu hỏi nào.
                        </p>
                        <ul class="mt-2 grid gap-1 text-xs text-brand-red">
                            @foreach (array_slice($importErrors, 0, 15) as $error)
                                <li>• {{ $error }}</li>
                            @endforeach
                            @if (count($importErrors) > 15)
                                <li class="font-bold">… và {{ count($importErrors) - 15 }} lỗi khác.</li>
                            @endif
                        </ul>
                    </div>
                @elseif ($importRows)
                    <div class="rounded-admin border border-state-success bg-state-success-tint p-3.5">
                        <p class="text-sm font-semibold text-state-success">
                            File hợp lệ: {{ count($importRows) }} câu hỏi sẵn sàng import.
                        </p>
                    </div>

                    <div class="max-h-64 overflow-y-auto rounded-admin border border-brand-line">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th class="w-16">Dòng</th>
                                    <th>Nội dung</th>
                                    <th class="w-32">Loại</th>
                                    <th class="w-24">Đáp án</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($importRows as $row)
                                    <tr>
                                        <td class="text-brand-muted">{{ $row['line'] }}</td>
                                        <td class="max-w-md truncate">{{ $row['content'] }}</td>
                                        <td class="text-brand-muted">
                                            @switch($row['type'])
                                                @case('single_choice') Chọn 1 @break
                                                @case('multiple_choice') Chọn nhiều @break
                                                @default Đúng/Sai
                                            @endswitch
                                        </td>
                                        <td>
                                            <span class="tag tag-green">
                                                {{ collect($row['options'])->where('is_correct', true)->count() }} đúng
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <x-slot:footer>
                <button type="button" wire:click="$set('showImportModal', false)" class="admin-btn-secondary">Đóng</button>
                <button type="button" wire:click="confirmImport" class="admin-btn"
                        @disabled($importErrors || ! $importRows)>
                    Import {{ $importRows ? count($importRows) . ' câu hỏi' : '' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
