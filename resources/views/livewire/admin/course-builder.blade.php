<div>
    @if (! $editingCourse)
        {{-- MỨC 1: danh sách khóa học --}}
        <x-admin.page-header title="Bộ tài liệu ban hành"
                             subtitle="Khóa học gồm nhiều bài giảng xếp theo trình tự, giao tự động theo phòng ban.">
            <x-slot:actions>
                <button type="button" wire:click="createCourse" class="admin-btn">
                    @svg('heroicon-o-plus', 'h-4 w-4')
                    Tạo khóa học
                </button>
            </x-slot:actions>
        </x-admin.page-header>

        <div class="admin-panel">
            <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
                <div class="min-w-[240px] flex-1">
                    <input type="search" wire:model.live.debounce.400ms="search" class="admin-input"
                           placeholder="Tìm theo tên hoặc mã khóa học...">
                </div>
                <select wire:model.live="statusFilter" class="admin-input w-auto min-w-[170px]">
                    <option value="">Mọi trạng thái</option>
                    <option value="draft">Nháp</option>
                    <option value="published">Đã xuất bản</option>
                    <option value="archived">Lưu trữ</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Khóa học</th>
                            <th>Phòng ban</th>
                            <th class="w-24">Bài học</th>
                            <th class="w-48">Tiến độ chung</th>
                            <th class="w-36">Kiểu học</th>
                            <th class="w-28">Trạng thái</th>
                            <th class="w-32 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($courses as $course)
                            <tr wire:key="course-{{ $course->id }}">
                                <td>
                                    <button type="button" wire:click="selectCourse({{ $course->id }})"
                                            class="text-left hover:text-brand-red">
                                        <span class="block font-bold">{{ $course->title }}</span>
                                        <span class="block font-mono text-[11px] text-brand-muted">{{ $course->code }}</span>
                                    </button>
                                </td>
                                <td class="text-brand-muted">{{ $course->ownerDepartment?->name ?? '—' }}</td>
                                <td><span class="tag">{{ $course->lessons_count }} bài</span></td>
                                <td>
                                    @if ($course->enrollments_count > 0)
                                        @php $avg = (int) round($course->avg_progress ?? 0); @endphp

                                        <div class="flex items-center gap-2">
                                            <div class="progress-track w-20">
                                                <div class="progress-fill {{ $avg >= 80 ? '!bg-state-success' : '' }}"
                                                     style="width: {{ $avg }}%"></div>
                                            </div>
                                            <span class="text-xs font-semibold">{{ $avg }}%</span>
                                        </div>

                                        <div class="mt-1 flex flex-wrap items-center gap-x-2 text-[11px] text-brand-muted">
                                            <span>{{ $course->completed_count }}/{{ $course->enrollments_count }} người xong</span>
                                            @php $pending = $course->enrollments_count - $course->completed_count; @endphp
                                            @if ($pending > 0)
                                                <span class="font-semibold text-state-warning">còn {{ $pending }}</span>
                                            @endif
                                            @if ($course->overdue_count > 0)
                                                <span class="font-semibold text-brand-red">
                                                    {{ $course->overdue_count }} quá hạn
                                                </span>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-xs text-brand-muted">Chưa giao cho ai</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="tag">{{ $course->sequential ? 'Tuần tự' : 'Tự do' }}</span>
                                    @if ($course->is_onboarding)
                                        <span class="tag tag-amber mt-1 block w-fit">Onboarding</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $map = [
                                            'draft' => ['', 'Nháp'],
                                            'pending_review' => ['tag-amber', 'Chờ duyệt'],
                                            'published' => ['tag-green', 'Đã xuất bản'],
                                            'archived' => ['', 'Lưu trữ'],
                                        ];
                                        [$cls, $label] = $map[$course->status] ?? ['', $course->status];
                                    @endphp
                                    <span class="tag {{ $cls }}">{{ $label }}</span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-admin.icon-button icon="heroicon-o-list-bullet" label="Danh sách bài học"
                                                             wire:click="selectCourse({{ $course->id }})" />
                                        <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa khóa học"
                                                             wire:click="editCourse({{ $course->id }})" />
                                        @if ($course->status === 'published')
                                            <x-admin.icon-button icon="heroicon-o-archive-box" label="Gỡ xuất bản"
                                                                 variant="danger"
                                                                 wire:click="unpublishCourse({{ $course->id }})"
                                                                 wire:confirm="Gỡ xuất bản khóa học này?" />
                                        @else
                                            <x-admin.icon-button icon="heroicon-o-paper-airplane" label="Xuất bản khóa học"
                                                                 variant="primary"
                                                                 wire:click="publishCourse({{ $course->id }})" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-brand-muted">Chưa có khóa học nào.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($courses->hasPages())
                <div class="border-t border-brand-line p-4">{{ $courses->links() }}</div>
            @endif
        </div>
    @else
        {{-- MỨC 2: bên trong một khóa học — danh sách bài giảng --}}
        <button type="button" wire:click="backToList"
                class="mb-3 inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold text-brand-muted hover:text-brand-red">
            @svg('heroicon-o-chevron-left', 'h-4 w-4')
            Về danh sách khóa học
        </button>

        <x-admin.page-header :title="$editingCourse->title"
                             :subtitle="$editingCourse->sequential
                                ? 'Học tuần tự — phải hoàn thành bài trước mới mở được bài sau.'
                                : 'Học tự do — người học chọn bài bất kỳ.'">
            <x-slot:actions>
                <button type="button" wire:click="editCourse({{ $editingCourse->id }})" class="admin-btn-secondary">
                    Cấu hình khóa
                </button>
                <button type="button" wire:click="createLesson" class="admin-btn">
                    @svg('heroicon-o-plus', 'h-4 w-4')
                    Thêm bài học
                </button>
            </x-slot:actions>
        </x-admin.page-header>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="admin-panel p-3.5">
                <span class="text-[11px] font-semibold uppercase text-brand-muted">Tổng bài học</span>
                <strong class="mt-1 block text-2xl leading-none">{{ $lessons->count() }}</strong>
            </div>
            <div class="admin-panel p-3.5">
                <span class="text-[11px] font-semibold uppercase text-brand-muted">Bài bắt buộc</span>
                <strong class="mt-1 block text-2xl leading-none">{{ $lessons->where('is_required', true)->count() }}</strong>
            </div>
            <div class="admin-panel p-3.5">
                <span class="text-[11px] font-semibold uppercase text-brand-muted">Đã giao</span>
                <strong class="mt-1 block text-2xl leading-none">{{ $enrolledCount }}</strong>
            </div>
            <div class="admin-panel p-3.5">
                <span class="text-[11px] font-semibold uppercase text-brand-muted">Thời lượng</span>
                <strong class="mt-1 block text-2xl leading-none">
                    {{ $lessons->sum('estimated_minutes') ?: '—' }}
                    <span class="text-sm font-bold text-brand-muted">phút</span>
                </strong>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px] xl:items-start">
            {{-- Danh sách bài giảng trong khóa, theo đúng trình tự học --}}
            <div class="grid min-w-0 gap-2.5">
                @forelse ($lessons as $index => $lesson)
                    <div class="admin-panel p-4" wire:key="lesson-{{ $lesson->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex min-w-0 gap-3">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-admin bg-brand-soft text-xs font-bold">
                                    {{ $index + 1 }}
                                </span>

                                <div class="min-w-0">
                                    <p class="font-bold">{{ $lesson->title }}</p>

                                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                        @php
                                            $typeMap = [
                                                'text' => ['heroicon-o-document-text', 'Văn bản'],
                                                'document' => ['heroicon-o-paper-clip', 'Tài liệu'],
                                                'video' => ['heroicon-o-play-circle', 'Video'],
                                                'quiz' => ['heroicon-o-clipboard-document-check', 'Trắc nghiệm'],
                                            ];
                                            [$icon, $typeLabel] = $typeMap[$lesson->content_type] ?? ['heroicon-o-document', $lesson->content_type];
                                        @endphp

                                        <span class="tag inline-flex items-center gap-1">
                                            @svg($icon, 'h-3.5 w-3.5')
                                            {{ $typeLabel }}
                                        </span>

                                        @if ($lesson->is_required)
                                            <span class="tag tag-red">Bắt buộc</span>
                                        @else
                                            <span class="tag">Tự chọn</span>
                                        @endif

                                        @if ($lesson->estimated_minutes)
                                            <span class="tag">{{ $lesson->estimated_minutes }} phút</span>
                                        @endif

                                        @if ($lesson->isVideo())
                                            <span class="tag">Xem tối thiểu {{ $lesson->minWatchPercent() }}%</span>
                                        @endif
                                    </div>

                                    {{-- Nguồn nội dung: cảnh báo ngay nếu bài trỏ vào chỗ trống --}}
                                    <p class="mt-1.5 text-xs text-brand-muted">
                                        @switch($lesson->content_type)
                                            @case('text')
                                                {{ filled($lesson->content_html) ? 'Nội dung soạn trực tiếp' : '⚠ Chưa có nội dung' }}
                                                @break
                                            @case('document')
                                            @case('video')
                                                {{ $lesson->document?->title ?? '⚠ Chưa gắn tài liệu' }}
                                                @break
                                            @case('quiz')
                                                {{ $lesson->quiz?->title ?? '⚠ Chưa gắn bài kiểm tra' }}
                                                @break
                                        @endswitch
                                    </p>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-1.5">
                                @if (! $loop->first)
                                    <button type="button" wire:click="moveLesson({{ $lesson->id }}, -1)"
                                            class="grid h-8 w-8 place-items-center rounded-admin border border-brand-line text-brand-muted transition-colors hover:border-brand-red hover:text-brand-red"
                                            aria-label="Chuyển lên">
                                        @svg('heroicon-o-chevron-up', 'h-4 w-4')
                                    </button>
                                @endif
                                @if (! $loop->last)
                                    <button type="button" wire:click="moveLesson({{ $lesson->id }}, 1)"
                                            class="grid h-8 w-8 place-items-center rounded-admin border border-brand-line text-brand-muted transition-colors hover:border-brand-red hover:text-brand-red"
                                            aria-label="Chuyển xuống">
                                        @svg('heroicon-o-chevron-down', 'h-4 w-4')
                                    </button>
                                @endif

                                <x-admin.icon-button
                                    :icon="$lesson->is_required ? 'heroicon-s-star' : 'heroicon-o-star'"
                                    :label="$lesson->is_required ? 'Bỏ đánh dấu bắt buộc' : 'Đánh dấu bắt buộc'"
                                    wire:click="toggleLessonRequired({{ $lesson->id }})" />
                                <x-admin.icon-button icon="heroicon-o-pencil-square" label="Sửa bài học"
                                                     wire:click="editLesson({{ $lesson->id }})" />
                                <x-admin.icon-button icon="heroicon-o-trash" label="Xóa bài học"
                                                     variant="danger"
                                                     wire:click="deleteLesson({{ $lesson->id }})"
                                                     wire:confirm="Xóa bài học này? Tiến độ của người đang học sẽ được tính lại." />
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="admin-panel p-10 text-center">
                        <p class="font-bold">Khóa học chưa có bài học nào.</p>
                        <p class="mt-1 text-sm text-brand-muted">
                            Mỗi bài học chọn một dạng nội dung: văn bản, tài liệu, video hoặc bài kiểm tra.
                        </p>
                    </div>
                @endforelse
            </div>

            {{-- Điều kiện gán tự động (spec 3.2.4) --}}
            <div class="admin-panel">
                <div class="admin-panel-head">
                    <h3>Điều kiện giao khóa</h3>
                    <button type="button" wire:click="createRule"
                            class="whitespace-nowrap text-xs font-semibold text-brand-red hover:underline">+ Thêm</button>
                </div>

                <div class="grid gap-2.5 p-4">
                    @forelse ($rules as $rule)
                        <div class="rounded-admin border border-brand-line p-3" wire:key="rule-{{ $rule->id }}">
                            <div class="mb-2 flex items-start justify-between gap-2">
                                <span class="tag {{ $rule->is_mandatory ? 'tag-red' : '' }}">
                                    {{ $rule->is_mandatory ? 'Bắt buộc' : 'Tự chọn' }}
                                </span>
                                <div class="flex shrink-0 gap-1">
                                    <button type="button" wire:click="applyRuleNow({{ $rule->id }})"
                                            class="whitespace-nowrap text-[11px] font-semibold text-brand-red hover:underline">Áp dụng</button>
                                    <span class="text-brand-line">|</span>
                                    <button type="button" wire:click="deleteRule({{ $rule->id }})"
                                            wire:confirm="Xóa điều kiện này?"
                                            class="whitespace-nowrap text-[11px] font-semibold text-brand-muted hover:text-brand-red">Xóa</button>
                                </div>
                            </div>

                            <ul class="grid gap-1 text-xs text-brand-muted">
                                @if ($rule->department)
                                    <li><strong class="text-brand-ink">Phòng ban:</strong> {{ $rule->department->name }}
                                        @if ($rule->include_sub_departments)
                                            <span class="text-[11px]">(gồm phòng con)</span>
                                        @endif
                                    </li>
                                @endif
                                @if ($rule->jobTitle)
                                    <li><strong class="text-brand-ink">Chức danh:</strong> {{ $rule->jobTitle->name }}</li>
                                @endif
                                @if ($rule->jobGrade)
                                    <li><strong class="text-brand-ink">Cấp bậc:</strong> {{ $rule->jobGrade->name }}</li>
                                @endif
                                @if ($rule->employment_status)
                                    <li><strong class="text-brand-ink">Trạng thái:</strong> {{ $rule->employment_status }}</li>
                                @endif
                                @if ($rule->due_days)
                                    <li><strong class="text-brand-ink">Hạn:</strong> {{ $rule->due_days }} ngày</li>
                                @endif
                            </ul>
                        </div>
                    @empty
                        <p class="text-sm text-brand-muted">
                            Chưa có điều kiện nào. Khóa học chỉ được giao khi admin gán thủ công.
                        </p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- Modal khóa học --}}
    @if ($showCourseModal)
        <x-admin.modal wireModel="showCourseModal"
                       :title="$courseFormId ? 'Cấu hình khóa học' : 'Tạo khóa học'"
                       maxWidth="max-w-3xl">
            <form wire:submit="saveCourse" id="course-form" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="c_code" class="admin-label">Mã khóa học</label>
                    <input id="c_code" type="text" wire:model="code" class="admin-input">
                    @error('code') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="c_dept" class="admin-label">Phòng ban ban hành</label>
                    <select id="c_dept" wire:model="owner_department_id" class="admin-input">
                        <option value="">— Chọn —</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label for="c_title" class="admin-label">Tên khóa học</label>
                    <input id="c_title" type="text" wire:model="title" class="admin-input">
                    @error('title') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="c_desc" class="admin-label">Mô tả</label>
                    <textarea id="c_desc" wire:model="description" rows="2" class="admin-input !h-auto py-2.5"></textarea>
                </div>

                <div>
                    <label for="c_days" class="admin-label">Hạn hoàn thành (ngày)</label>
                    <input id="c_days" type="number" wire:model="duration_days" class="admin-input"
                           placeholder="Để trống = không hạn">
                    @error('duration_days') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="c_pass" class="admin-label">Điểm đạt toàn khóa (%)</label>
                    <input id="c_pass" type="number" wire:model="pass_score" class="admin-input"
                           placeholder="Để trống = không chấm tổng">
                    @error('pass_score') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-2.5 border-t border-brand-line pt-4 sm:col-span-2">
                    <label class="flex cursor-pointer items-start gap-2.5 text-sm font-medium">
                        <input type="checkbox" wire:model="sequential"
                               class="mt-0.5 h-4 w-4 shrink-0 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        <span>
                            Học tuần tự
                            <span class="mt-0.5 block text-xs font-normal text-brand-muted">
                                Phải hoàn thành bài trước mới mở được bài tiếp theo.
                            </span>
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-start gap-2.5 text-sm font-medium">
                        <input type="checkbox" wire:model="is_onboarding"
                               class="mt-0.5 h-4 w-4 shrink-0 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        <span>
                            Lộ trình onboarding
                            <span class="mt-0.5 block text-xs font-normal text-brand-muted">
                                Tự động giao cho mọi nhân viên mới ngay khi tạo tài khoản.
                            </span>
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium">
                        <input type="checkbox" wire:model="issue_certificate"
                               class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        Cấp chứng nhận khi hoàn thành
                    </label>
                </div>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showCourseModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="course-form" class="admin-btn">
                    {{ $courseFormId ? 'Lưu thay đổi' : 'Tạo khóa học' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Modal bài học --}}
    @if ($showLessonModal)
        <x-admin.modal wireModel="showLessonModal"
                       :title="$lessonId ? 'Sửa bài học' : 'Thêm bài học'"
                       maxWidth="max-w-3xl">
            <form wire:submit="saveLesson" id="lesson-form" class="grid gap-4">
                <div>
                    <label for="l_title" class="admin-label">Tên bài học</label>
                    <input id="l_title" type="text" wire:model="lessonTitle" class="admin-input">
                    @error('lessonTitle') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="l_sum" class="admin-label">Tóm tắt</label>
                    <input id="l_sum" type="text" wire:model="lessonSummary" class="admin-input">
                </div>

                <div>
                    <label for="l_type" class="admin-label">Dạng nội dung</label>
                    <select id="l_type" wire:model.live="contentType" class="admin-input">
                        <option value="text">Văn bản (soạn trực tiếp)</option>
                        <option value="document">Tài liệu từ thư viện</option>
                        <option value="video">Video từ thư viện</option>
                        <option value="quiz">Bài kiểm tra trắc nghiệm</option>
                    </select>
                </div>

                {{-- Nguồn nội dung đổi theo dạng đã chọn --}}
                @if ($contentType === 'text')
                    <div>
                        <label for="l_html" class="admin-label">Nội dung bài học</label>
                        <textarea id="l_html" wire:model="contentHtml" rows="8"
                                  class="admin-input !h-auto py-2.5 font-mono text-xs"
                                  placeholder="<p>Nội dung bài học...</p>"></textarea>
                        @error('contentHtml') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>
                @elseif ($contentType === 'document' || $contentType === 'video')
                    <div>
                        <label for="l_doc" class="admin-label">
                            Chọn {{ $contentType === 'video' ? 'video' : 'tài liệu' }} từ thư viện
                        </label>
                        <select id="l_doc" wire:model="documentId" class="admin-input">
                            <option value="">— Chọn —</option>
                            @foreach ($documents->where('kind', $contentType === 'video' ? 'video' : 'file') as $doc)
                                <option value="{{ $doc->id }}">{{ $doc->title }}</option>
                            @endforeach
                        </select>
                        @error('documentId') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-brand-muted">
                            Chỉ hiện tài liệu đã xuất bản trong thư viện.
                        </p>
                    </div>

                    @if ($contentType === 'video')
                        <div>
                            <label for="l_watch" class="admin-label">% xem tối thiểu để tính hoàn thành</label>
                            <input id="l_watch" type="number" wire:model="minWatchPercent" class="admin-input"
                                   placeholder="Mặc định 80">
                            @error('minWatchPercent') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        </div>
                    @endif
                @else
                    <div>
                        <label for="l_quiz" class="admin-label">Chọn bài kiểm tra</label>
                        <select id="l_quiz" wire:model="quizId" class="admin-input">
                            <option value="">— Chọn —</option>
                            @foreach ($quizzes as $quiz)
                                <option value="{{ $quiz->id }}">{{ $quiz->title }}</option>
                            @endforeach
                        </select>
                        @error('quizId') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-brand-muted">
                            Chỉ hiện bài kiểm tra đã xuất bản. Bài học hoàn thành khi người học đạt điểm.
                        </p>
                    </div>
                @endif

                <div class="grid gap-4 border-t border-brand-line pt-4 sm:grid-cols-2">
                    <div>
                        <label for="l_mins" class="admin-label">Thời lượng dự kiến (phút)</label>
                        <input id="l_mins" type="number" wire:model="estimatedMinutes" class="admin-input">
                        @error('estimatedMinutes') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex cursor-pointer items-start gap-2.5 self-end pb-2 text-sm font-medium">
                        <input type="checkbox" wire:model="lessonRequired"
                               class="mt-0.5 h-4 w-4 shrink-0 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                        <span>
                            Bài bắt buộc
                            <span class="mt-0.5 block text-xs font-normal text-brand-muted">
                                Chỉ bài bắt buộc được tính vào % tiến độ.
                            </span>
                        </span>
                    </label>
                </div>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showLessonModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="lesson-form" class="admin-btn">
                    {{ $lessonId ? 'Lưu bài học' : 'Thêm bài học' }}
                </button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Modal điều kiện gán --}}
    @if ($showRuleModal)
        <x-admin.modal wireModel="showRuleModal" title="Điều kiện giao khóa học tự động">
            <form wire:submit="saveRule" id="rule-form" class="grid gap-4">
                <p class="rounded-admin border border-brand-line bg-brand-soft p-3 text-xs text-brand-muted">
                    Nhân viên khớp <strong class="text-brand-ink">tất cả</strong> điều kiện dưới đây sẽ được giao khóa học này.
                    Khi nhân viên đổi phòng ban hoặc chức danh, hệ thống tự giao lại theo điều kiện mới.
                </p>

                <div>
                    <label for="r_dept" class="admin-label">Phòng ban</label>
                    <select id="r_dept" wire:model="ruleDepartmentId" class="admin-input">
                        <option value="">— Không ràng buộc —</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->id }}">{{ $dept->full_path }}</option>
                        @endforeach
                    </select>
                    @error('ruleDepartmentId') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                </div>

                <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium">
                    <input type="checkbox" wire:model="ruleIncludeSub"
                           class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                    Áp dụng cho cả phòng ban con
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="r_title" class="admin-label">Chức danh</label>
                        <select id="r_title" wire:model="ruleJobTitleId" class="admin-input">
                            <option value="">— Không ràng buộc —</option>
                            @foreach ($jobTitles as $jt)
                                <option value="{{ $jt->id }}">{{ $jt->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="r_grade" class="admin-label">Cấp bậc</label>
                        <select id="r_grade" wire:model="ruleJobGradeId" class="admin-input">
                            <option value="">— Không ràng buộc —</option>
                            @foreach ($jobGrades as $jg)
                                <option value="{{ $jg->id }}">{{ $jg->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="r_status" class="admin-label">Trạng thái làm việc</label>
                        <select id="r_status" wire:model="ruleEmploymentStatus" class="admin-input">
                            <option value="">— Không ràng buộc —</option>
                            <option value="probation">Thử việc</option>
                            <option value="official">Chính thức</option>
                        </select>
                    </div>

                    <div>
                        <label for="r_due" class="admin-label">Hạn hoàn thành (ngày)</label>
                        <input id="r_due" type="number" wire:model="ruleDueDays" class="admin-input"
                               placeholder="Để trống = không hạn">
                        @error('ruleDueDays') <p class="mt-1 text-xs font-semibold text-brand-red">{{ $message }}</p> @enderror
                    </div>
                </div>

                <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium">
                    <input type="checkbox" wire:model="ruleMandatory"
                           class="h-4 w-4 rounded-admin-sm border-brand-line text-brand-red focus:ring-brand-red">
                    Khóa học bắt buộc
                </label>
            </form>

            <x-slot:footer>
                <button type="button" wire:click="$set('showRuleModal', false)" class="admin-btn-secondary">Hủy</button>
                <button type="submit" form="rule-form" class="admin-btn">Tạo và áp dụng</button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>
