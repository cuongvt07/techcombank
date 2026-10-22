@php
    use App\Livewire\Admin\Reports\QuizResultReport as Report;
    use App\Services\Reports\QuizResultReportService as Svc;
    use App\Support\Reports\ReportPeriod;

    $isQuestions = $tab === 'questions';
    $num = fn ($n) => number_format((int) $n, 0, ',', '.');
    $arrow = fn (string $key, bool $isDefault = false) => ($sort === $key || ($isDefault && $sort === '')) ? ($dir === 'desc' ? '▼' : '▲') : '';

    // Đạt lần đầu so với kỳ trước (điểm phần trăm)
    $firstRate = $pairKpi['first_pass_rate'];
    $prevRate = $pairKpi['prev_first_pass_rate'];
    $firstDelta = $firstRate !== null && $prevRate !== null ? $firstRate - $prevRate : null;
    $firstDeltaText = $firstDelta === null ? null
        : (abs($firstDelta) < 0.05 ? '= kỳ trước' : ($firstDelta > 0 ? '▲ ' : '▼ ') . number_format(abs($firstDelta), 1, ',', '.') . ' pp');
    $firstDeltaTone = $firstDelta === null || abs($firstDelta) < 0.05 ? 'neutral' : ($firstDelta > 0 ? 'good' : 'bad');
@endphp

<div>
    <x-admin.page-header title="Báo cáo kết quả kiểm tra"
                         :subtitle="$isQuestions
                            ? 'Chỉ tính lượt đã nộp · điểm đạt theo cấu hình hiện tại của bài kiểm tra'
                            : 'Một dòng là một nhân viên trên một bài kiểm tra · gộp mọi lượt làm của người đó'">
        <x-slot:actions>
            @can('report_quiz.update')
                @if ($isQuestions)
                    <button type="button" wire:click="showExhausted" class="admin-btn-secondary !border-brand-ink">
                        Cấp thêm lượt làm
                    </button>
                @else
                    <button type="button" wire:click="remindAllRetry" class="admin-btn-secondary !border-brand-ink disabled:cursor-not-allowed disabled:opacity-40"
                            @disabled($retryCount === 0)
                            data-confirm="Gửi nhắc làm lại cho {{ $num($retryCount) }} nhân viên chưa đạt và còn lượt (khớp bộ lọc hiện tại)?"
                            data-confirm-title="Gửi nhắc làm lại" data-confirm-ok="Gửi nhắc">
                        Gửi nhắc làm lại
                    </button>
                @endif
            @endcan
            <x-admin.report.export-menu />
        </x-slot:actions>
    </x-admin.page-header>

    @if ($notice)
        <div class="mb-4 flex items-start justify-between gap-3 rounded-admin border border-state-success bg-state-success-tint px-4 py-3 text-sm font-semibold text-state-success" role="status">
            <span>{{ $notice }}</span>
            <button type="button" wire:click="$set('notice', null)" class="shrink-0" aria-label="Đóng">@svg('heroicon-m-x-mark', 'h-4 w-4')</button>
        </div>
    @endif

    {{-- Bộ lọc --}}
    <div class="admin-panel mb-4 flex flex-wrap items-end gap-3 p-4">
        <x-admin.report.period-picker :preset="$preset" :from="$from" :to="$to" label="Kỳ (theo ngày nộp)" />

        <div>
            <label for="qr-course" class="admin-label">Khóa học</label>
            <select id="qr-course" wire:model.live="courseId" class="admin-input w-auto min-w-[180px] max-w-[260px]">
                <option value="">Tất cả</option>
                @foreach ($courses as $c)
                    <option value="{{ $c->id }}">{{ $c->title }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="qr-quiz" class="admin-label">Bài kiểm tra</label>
            <select id="qr-quiz" wire:model.live="quizId" class="admin-input w-auto min-w-[180px] max-w-[260px]">
                <option value="">Tất cả ({{ $quizzes->count() }} bài)</option>
                @foreach ($quizzes as $q)
                    <option value="{{ $q->id }}">{{ $q->title }}</option>
                @endforeach
            </select>
        </div>

        @if ($isQuestions)
            <div>
                <label for="qr-attempt" class="admin-label">Lần làm</label>
                <select id="qr-attempt" wire:model.live="attemptScope" class="admin-input w-auto min-w-[140px]">
                    <option value="">Tất cả</option>
                    <option value="first">Lần đầu</option>
                    <option value="last">Lần cuối</option>
                </select>
            </div>
            <div>
                <label for="qr-attempt-result" class="admin-label">Kết quả</label>
                <select id="qr-attempt-result" wire:model.live="attemptResult" class="admin-input w-auto min-w-[140px]">
                    <option value="">Tất cả</option>
                    <option value="passed">Đạt</option>
                    <option value="failed">Không đạt</option>
                </select>
            </div>
        @else
            <div>
                <label for="qr-dept" class="admin-label">Đơn vị</label>
                <select id="qr-dept" wire:model.live="departmentId" class="admin-input w-auto min-w-[180px] max-w-[240px]">
                    <option value="">Tất cả ({{ $departments->count() }} đơn vị)</option>
                    @foreach ($departments as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="qr-result" class="admin-label">Kết quả cuối</label>
                <select id="qr-result" wire:model.live="result" class="admin-input w-auto min-w-[180px]">
                    <option value="">Tất cả</option>
                    @foreach (Svc::RESULT_LABELS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <button type="button" wire:click="resetFilters" class="admin-btn-secondary ml-auto">Đặt lại</button>
    </div>

    {{-- Chỉ số --}}
    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @if ($isQuestions)
            <x-admin.report.kpi label="Lượt nộp" :value="$num($attemptKpi['attempts'])" />
            <x-admin.report.kpi label="Nhân viên dự thi" :value="$num($attemptKpi['employees'])" />
            <x-admin.report.kpi label="Tỷ lệ đạt (lượt)" :value="Report::percent($attemptKpi['pass_rate'])"
                                :note="$attemptKpi['attempts'] ? $num($attemptKpi['passed']) . ' / ' . $num($attemptKpi['attempts']) . ' lượt' : null" />
            <x-admin.report.kpi label="Đạt lần đầu" :value="Report::percent($firstRate)" :delta="$firstDeltaText" :deltaTone="$firstDeltaTone" />
            <x-admin.report.kpi label="Điểm trung bình"
                                :value="$attemptKpi['avg_score'] === null ? '—' : number_format($attemptKpi['avg_score'], 1, ',', '.')"
                                :note="$selectedQuiz ? 'điểm đạt ' . $selectedQuiz->pass_score : null" />
            <x-admin.report.kpi label="Hết lượt chưa đạt" :value="$num($pairKpi['exhausted'])" note="cần cấp thêm lượt"
                                :alert="$pairKpi['exhausted'] > 0" wire:click="showExhausted" class="cursor-pointer" />
        @else
            <x-admin.report.kpi label="Nhân viên dự thi" :value="$num($pairKpi['employees'])"
                                :note="$pairKpi['total'] !== $pairKpi['employees'] ? $num($pairKpi['total']) . ' lượt NV × bài' : null" />
            <x-admin.report.kpi label="Đã đạt" :value="$num($pairKpi['passed'])" :note="Report::percent($pairKpi['passed_rate'])" />
            <x-admin.report.kpi label="Chưa đạt, còn lượt" :value="$num($pairKpi['retry'])" note="gửi nhắc làm lại"
                                wire:click="$set('result', 'retry')" class="cursor-pointer" />
            <x-admin.report.kpi label="Hết lượt chưa đạt" :value="$num($pairKpi['exhausted'])" note="cần cấp thêm lượt"
                                :alert="$pairKpi['exhausted'] > 0" wire:click="$set('result', 'exhausted')" class="cursor-pointer" />
            <x-admin.report.kpi label="Đạt lần đầu" :value="Report::percent($firstRate)" :delta="$firstDeltaText" :deltaTone="$firstDeltaTone" />
            <x-admin.report.kpi label="Lượt đến khi đạt"
                                :value="$pairKpi['avg_pass_no'] === null ? '—' : number_format($pairKpi['avg_pass_no'], 1, ',', '.')"
                                note="trung bình" />
        @endif
    </div>

    {{-- Biểu đồ --}}
    @if ($isQuestions)
        @php
            $histTotal = collect($histogram)->sum(fn ($b) => $b['failed'] + $b['passed']);
            $histFailed = collect($histogram)->sum('failed');
            $histMax = max(1, collect($histogram)->max(fn ($b) => $b['failed'] + $b['passed']));
            // Trục Y làm tròn lên mốc dễ đọc, 4 vạch
            $exp = 10 ** floor(log10(max(1, $histMax / 4)));
            $yMax = collect([1, 2, 2.5, 5, 10])->map(fn ($m) => $m * $exp * 4)->first(fn ($v) => $v >= $histMax) ?? $histMax;
            [$W, $H, $pl, $pr, $pt, $pb] = [560, 230, 40, 12, 24, 30];
            $pw = $W - $pl - $pr;
            $ph = $H - $pt - $pb;
            $slot = $pw / 10;
            $peak = collect($histogram)->map(fn ($b) => $b['failed'] + $b['passed'])->search($histMax);
            $quizzesBelow = $quizRates->filter(fn ($r) => $r->rate < Svc::QUIZ_ALERT_RATE)->count();
        @endphp
        <div class="mb-4 grid gap-4 xl:grid-cols-2">
            <x-admin.report.panel title="Phân bố điểm"
                                  :subtitle="$selectedQuiz ? $selectedQuiz->title . ' · điểm đạt ' . $selectedQuiz->pass_score : 'Gộp mọi bài kiểm tra'"
                                  :note="$histTotal
                                      ? ($selectedQuiz
                                          ? 'Cột đỏ: dưới điểm đạt (' . $num($histFailed) . ' lượt, ' . Report::percent($histFailed / $histTotal * 100) . ') · bấm một cột để lọc bảng câu hỏi theo khoảng điểm'
                                          : 'Đang gộp ' . $quizRates->count() . ' bài có điểm đạt khác nhau: phần đỏ là lượt dưới điểm đạt của chính bài đó (' . $num($histFailed) . ' lượt). Chọn một bài để xem vạch điểm đạt.')
                                      : null">
                @if ($histTotal === 0)
                    <p class="py-10 text-center text-sm text-brand-muted">Chưa có lượt nộp nào khớp bộ lọc trong kỳ {{ $period->label() }}.</p>
                @else
                    <svg viewBox="0 0 {{ $W }} {{ $H }}" class="block h-auto w-full" role="img" aria-label="Phân bố điểm theo khoảng 10 điểm">
                        @foreach (range(0, 4) as $t)
                            @php $y = $pt + $ph - $t / 4 * $ph; @endphp
                            <line x1="{{ $pl }}" x2="{{ $W - $pr }}" y1="{{ $y }}" y2="{{ $y }}" stroke="#eef0f4" />
                            <text x="{{ $pl - 6 }}" y="{{ $y + 3.5 }}" text-anchor="end" font-size="10" fill="#6b7280">{{ $num($yMax * $t / 4) }}</text>
                        @endforeach

                        @foreach ($histogram as $i => $b)
                            @php
                                $x = $pl + $slot * $i + $slot * 0.11;
                                $bw = $slot * 0.78;
                                $hFail = $b['failed'] / $yMax * $ph;
                                $hPass = $b['passed'] / $yMax * $ph;
                                $dim = $band !== null && $band !== '' && (int) $band !== $i;
                            @endphp
                            <g wire:click="filterBand({{ $i }})" style="cursor: pointer" opacity="{{ $dim ? 0.35 : 1 }}">
                                <title>{{ $b['label'] }} điểm: {{ $num($b['failed'] + $b['passed']) }} lượt ({{ $num($b['failed']) }} dưới điểm đạt)</title>
                                <rect x="{{ $pl + $slot * $i }}" y="{{ $pt }}" width="{{ $slot }}" height="{{ $ph }}" fill="transparent" />
                                @if ($hFail > 0)
                                    <rect x="{{ $x }}" y="{{ $pt + $ph - $hFail }}" width="{{ $bw }}" height="{{ $hFail }}" fill="#c00016" />
                                @endif
                                @if ($hPass > 0)
                                    <rect x="{{ $x }}" y="{{ $pt + $ph - $hFail - $hPass }}" width="{{ $bw }}" height="{{ $hPass }}" fill="#141821" />
                                @endif
                                @if ($i === $peak)
                                    <text x="{{ $x + $bw / 2 }}" y="{{ $pt + $ph - $hFail - $hPass - 5 }}" text-anchor="middle" font-size="10" font-weight="600" fill="#141821">{{ $num($histMax) }} lượt</text>
                                @endif
                            </g>
                        @endforeach

                        @if ($selectedQuiz)
                            @php $px = $pl + $pw * min(100, $selectedQuiz->pass_score) / 100; @endphp
                            <line x1="{{ $px }}" x2="{{ $px }}" y1="{{ $pt - 8 }}" y2="{{ $pt + $ph }}" stroke="#c00016" stroke-dasharray="4 3" />
                            <text x="{{ $px - 4 }}" y="{{ $pt - 12 }}" text-anchor="end" font-size="10" fill="#c00016">điểm đạt {{ $selectedQuiz->pass_score }}</text>
                        @endif

                        <line x1="{{ $pl }}" x2="{{ $W - $pr }}" y1="{{ $pt + $ph }}" y2="{{ $pt + $ph }}" stroke="#d1d5db" />
                        @foreach ([0, 20, 40, 60, 80, 100] as $v)
                            <text x="{{ $pl + $pw * $v / 100 }}" y="{{ $H - 10 }}" text-anchor="middle" font-size="10" fill="#6b7280">{{ $v }}</text>
                        @endforeach
                    </svg>
                    @if ($band !== null && $band !== '')
                        <p class="mt-2 text-xs">
                            Bảng câu hỏi đang lọc khoảng <strong>{{ $histogram[(int) $band]['label'] }} điểm</strong>.
                            <button type="button" wire:click="filterBand({{ (int) $band }})" class="font-semibold text-brand-red hover:underline">Bỏ lọc</button>
                        </p>
                    @endif
                @endif
            </x-admin.report.panel>

            <x-admin.report.panel title="Tỷ lệ đạt theo bài kiểm tra" subtitle="thấp nhất xếp trên"
                                  :note="$quizRates->isEmpty() ? null : ($quizzesBelow
                                      ? $quizzesBelow . ' bài có tỷ lệ lượt đạt dưới ' . (int) Svc::QUIZ_ALERT_RATE . '%. Xem <strong>bảng câu hỏi</strong> bên dưới để biết câu nào kéo điểm xuống trước khi sửa nội dung bài giảng.'
                                      : 'Mọi bài đều có từ ' . (int) Svc::QUIZ_ALERT_RATE . '% lượt đạt trở lên. Bấm một thanh để xem riêng bài đó.')"
                                  :noteAlert="$quizzesBelow > 0">
                @if ($quizRates->isEmpty())
                    <p class="py-10 text-center text-sm text-brand-muted">Chưa có lượt nộp nào khớp bộ lọc.</p>
                @else
                    <x-admin.chart.hbar :max="100" labelWidth="minmax(0, 13rem)" valueWidth="3.5rem" subWidth="4.5rem"
                                        clickAction="filterQuiz" :activeKey="$quizId"
                                        :rows="$quizRates->map(fn ($r) => [
                                            'label' => $r->title,
                                            'segments' => [['value' => $r->rate, 'color' => $r->rate < Svc::QUIZ_ALERT_RATE ? '#c00016' : '#141821', 'name' => 'Tỷ lệ đạt', 'text' => Report::percent($r->rate)]],
                                            'value' => Report::percent($r->rate),
                                            'sub' => $num($r->total) . ' lượt',
                                            'key' => $r->quiz_id,
                                        ])->all()" />
                @endif
            </x-admin.report.panel>
        </div>
    @else
        @php
            $total = $pairKpi['total'];
            $notPassed = $pairKpi['retry'] + $pairKpi['exhausted'];
            $share = fn ($n) => $total ? Report::percent($n / $total * 100) : '—';
        @endphp
        <div class="mb-4 grid gap-4 xl:grid-cols-2">
            <x-admin.report.panel title="Số lượt đến khi đạt" :subtitle="$num($pairKpi['employees']) . ' nhân viên dự thi'"
                                  :note="$total ? ($notPassed
                                      ? 'Trong ' . $num($notPassed) . ' người chưa đạt, <strong>' . $num($pairKpi['exhausted']) . ' người đã hết lượt</strong> nên không thể tự làm lại. Nhóm này phải được cấp thêm lượt thủ công.'
                                      : 'Mọi nhân viên dự thi trong kỳ đều đã đạt.') : null"
                                  :noteAlert="$pairKpi['exhausted'] > 0">
                @if ($total === 0)
                    <p class="py-10 text-center text-sm text-brand-muted">Chưa có lượt nộp nào khớp bộ lọc trong kỳ {{ $period->label() }}.</p>
                @else
                    <x-admin.chart.hbar :max="$total" labelWidth="minmax(0, 9rem)" valueWidth="3rem" subWidth="3.5rem"
                                        :rows="[
                                            ['label' => 'Đạt ngay lần đầu', 'segments' => [['value' => $pairKpi['pass1'], 'color' => '#141821']], 'value' => $num($pairKpi['pass1']), 'sub' => $share($pairKpi['pass1'])],
                                            ['label' => 'Đạt ở lượt 2', 'segments' => [['value' => $pairKpi['pass2'], 'color' => '#6b7280']], 'value' => $num($pairKpi['pass2']), 'sub' => $share($pairKpi['pass2'])],
                                            ['label' => 'Đạt ở lượt 3+', 'segments' => [['value' => $pairKpi['pass3'], 'color' => '#6b7280']], 'value' => $num($pairKpi['pass3']), 'sub' => $share($pairKpi['pass3'])],
                                            ['label' => 'Chưa đạt', 'segments' => [['value' => $notPassed, 'color' => '#c00016']], 'value' => $num($notPassed), 'sub' => $share($notPassed), 'tone' => 'danger'],
                                        ]" />
                @endif
            </x-admin.report.panel>

            <x-admin.report.panel title="Tỷ lệ nhân viên đã đạt theo đơn vị" subtitle="thấp nhất xếp trên"
                                  note="Mẫu số là số nhân viên đã dự thi trong đơn vị (tính theo từng bài), không phải toàn bộ nhân sự. Bấm một thanh để lọc bảng theo đơn vị.">
                @if ($departmentRates->isEmpty())
                    <p class="py-10 text-center text-sm text-brand-muted">Chưa có dữ liệu theo đơn vị.</p>
                @else
                    <x-admin.chart.hbar :max="100" labelWidth="minmax(0, 11rem)" valueWidth="3.5rem" subWidth="4.5rem"
                                        clickAction="filterDepartment" :activeKey="$departmentId"
                                        :rows="$departmentRates->map(fn ($r) => [
                                            'label' => $r->department_name ?? 'Chưa gán đơn vị',
                                            'segments' => [['value' => $r->rate, 'color' => $r->rate < Svc::DEPARTMENT_ALERT_RATE ? '#c00016' : '#141821', 'name' => 'Đã đạt', 'text' => Report::percent($r->rate)]],
                                            'value' => Report::percent($r->rate),
                                            'sub' => $num($r->people) . ' người',
                                            'key' => $r->department_id ?? '',
                                        ])->all()" />
                @endif
            </x-admin.report.panel>
        </div>
    @endif

    {{-- Bảng --}}
    <div class="admin-panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-line p-4">
            <div class="inline-flex overflow-hidden rounded-admin border border-brand-ink" role="tablist" aria-label="Chế độ xem">
                @foreach (Report::TABS as $key => $label)
                    <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            @class([
                                'h-10 px-3.5 text-[13px] transition-colors',
                                'bg-brand-ink font-semibold text-white' => $tab === $key,
                                'bg-white text-brand-ink hover:bg-brand-soft' => $tab !== $key,
                            ])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($isQuestions)
                @unless ($canSeeAnswers)
                    <span class="inline-flex min-h-[32px] items-center gap-1.5 rounded-admin border border-brand-line bg-brand-soft px-2.5 text-xs text-brand-muted"
                          title="Cột đáp án đúng chỉ hiện với người có quyền sửa bài kiểm tra">
                        @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5') Chứa đáp án · cần quyền quizzes.update
                    </span>
                @endunless
                <span class="text-xs text-brand-muted">{{ $num($questions->total()) }} câu hỏi</span>
            @else
                <div class="relative min-w-[220px] flex-1">
                    <label for="qr-search" class="sr-only">Lọc trong bảng</label>
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-brand-muted">@svg('heroicon-o-magnifying-glass', 'h-4 w-4')</span>
                    <input id="qr-search" type="search" wire:model.live.debounce.400ms="search" class="admin-input pl-9"
                           placeholder="Lọc theo mã NV, họ tên, đơn vị, bài kiểm tra...">
                </div>
                <span class="text-xs text-brand-muted">{{ $num($rows->total()) }} dòng</span>
            @endif

            <div class="ml-auto flex items-center gap-2">
                <label for="qr-per-page" class="text-xs text-brand-muted">Số dòng</label>
                <select id="qr-per-page" wire:model.live="perPage" class="admin-input w-auto">
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>

        @if ($isQuestions)
            <div class="overflow-x-auto">
                <table class="admin-table" style="min-width: {{ $canSeeAnswers ? 1100 : 940 }}px">
                    <thead>
                        <tr>
                            <th style="width: 64px">
                                <button type="button" wire:click="sortBy('stt')" class="uppercase hover:text-brand-ink">STT {{ $arrow('stt') }}</button>
                            </th>
                            <th style="width: 28%">Nội dung câu hỏi</th>
                            <th style="width: 120px">Loại</th>
                            <th class="text-right" style="width: 96px">
                                <button type="button" wire:click="sortBy('shown')" class="uppercase hover:text-brand-ink">Số lần ra {{ $arrow('shown') }}</button>
                            </th>
                            <th style="width: 170px">
                                <button type="button" wire:click="sortBy('rate')" class="uppercase hover:text-brand-ink">% trả lời đúng {{ $arrow('rate', true) }}</button>
                            </th>
                            <th style="width: 22%">Đáp án sai được chọn nhiều nhất</th>
                            <th style="width: 120px">Cờ chất lượng</th>
                            @if ($canSeeAnswers)
                                <th style="width: 18%">Đáp án đúng</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($questions as $q)
                            @php
                                $muted = $q->shown < Svc::MIN_SAMPLE;
                                $barColor = $muted ? '#d1d5db' : ($q->correct_rate < Svc::REVIEW_BELOW ? '#c00016' : '#141821');
                            @endphp
                            <tr wire:key="qq-{{ $q->id }}">
                                <td class="tabular-nums">{{ $q->sort_order + 1 }}</td>
                                <td>
                                    <span class="block">{{ $q->content }}</span>
                                    @unless ($selectedQuiz)
                                        <span class="mt-0.5 block text-xs text-brand-muted">{{ $q->quiz_title }}</span>
                                    @endunless
                                </td>
                                <td class="text-brand-muted">{{ Report::typeLabel($q->type) }}</td>
                                <td class="text-right tabular-nums">{{ $num($q->shown) }}</td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="relative h-2 min-w-0 flex-1 overflow-hidden rounded-sm bg-[#eef0f4]">
                                            <span class="absolute inset-y-0 left-0" style="width: {{ min(100, $q->correct_rate) }}%; background: {{ $barColor }}"></span>
                                        </span>
                                        <span @class(['w-12 shrink-0 text-right tabular-nums', 'font-semibold text-brand-red' => ! $muted && $q->correct_rate < Svc::TOO_HARD_BELOW, 'text-brand-muted' => $muted])>
                                            {{ Report::percent($q->correct_rate) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="text-brand-muted">
                                    @if ($q->top_wrong)
                                        {{ $q->top_wrong['quoted'] ? '“' . $q->top_wrong['text'] . '”' : $q->top_wrong['text'] }}
                                        ({{ Report::percent($q->top_wrong['pct']) }})
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if (! $q->flag)
                                        <span class="text-brand-muted">—</span>
                                    @elseif ($q->flag['tone'] === 'danger')
                                        <span class="tag border border-brand-red bg-white text-brand-red">{{ $q->flag['label'] }}</span>
                                    @elseif ($q->flag['tone'] === 'outline')
                                        <span class="tag border border-brand-ink bg-white font-semibold text-brand-ink">{{ $q->flag['label'] }}</span>
                                    @else
                                        <span class="text-brand-muted">{{ $q->flag['label'] }}</span>
                                    @endif
                                </td>
                                @if ($canSeeAnswers)
                                    <td class="text-xs">{{ $q->correct_text ?: '—' }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canSeeAnswers ? 8 : 7 }}" class="py-10 text-center text-sm text-brand-muted">
                                    Chưa có câu trả lời nào khớp bộ lọc trong kỳ {{ $period->label() }}.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-brand-line px-4 py-3 text-xs text-brand-muted">
                <span>Hiển thị {{ $questions->firstItem() ?? 0 }}–{{ $questions->lastItem() ?? 0 }} / {{ $num($questions->total()) }} câu hỏi</span>
                <span>Cờ chất lượng chỉ gắn khi câu xuất hiện từ {{ Svc::MIN_SAMPLE }} lần trở lên</span>
                <span class="ml-auto">Nguồn: quizzes, quiz_attempts, quiz_attempt_answers</span>
            </div>
            @if ($questions->hasPages())
                <div class="border-t border-brand-line px-4 py-3">{{ $questions->links(data: ['summary' => false]) }}</div>
            @endif
        @else
            @php
                $pageKeys = $rows->getCollection()->map(fn ($r) => $r->employee_id . ':' . $r->quiz_id)->all();
                $allOnPage = $pageKeys !== [] && array_diff($pageKeys, $selected) === [];
                $selCount = count($selected);
                $selExhausted = $selection[Svc::RESULT_EXHAUSTED] ?? 0;
                $selRetry = $selection[Svc::RESULT_RETRY] ?? 0;
                $selPassed = $selection[Svc::RESULT_PASSED] ?? 0;
            @endphp

            @if ($selCount > 0)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 bg-brand-ink px-4 py-3 text-white">
                    <strong class="text-sm">Đã chọn {{ $num($selCount) }} nhân viên</strong>
                    <span class="text-xs text-white/70">
                        {{ collect([
                            $selExhausted ? $num($selExhausted) . ' hết lượt chưa đạt' : null,
                            $selRetry ? $num($selRetry) . ' chưa đạt còn lượt' : null,
                            $selPassed ? $num($selPassed) . ' đã đạt' : null,
                        ])->filter()->implode(' · ') }}
                    </span>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        @can('report_quiz.update')
                            <button type="button" wire:click="grantSelected" class="admin-btn disabled:cursor-not-allowed disabled:opacity-40" @disabled($selExhausted === 0)
                                    title="Chỉ cấp cho dòng hết lượt và chưa đạt">
                                Cấp thêm 1 lượt làm
                            </button>
                            <button type="button" wire:click="remindSelected" @disabled($selRetry === 0)
                                    class="inline-flex min-h-[40px] items-center rounded-admin border border-white/40 px-3.5 text-[13px] font-semibold hover:bg-white/10 disabled:opacity-40"
                                    title="Chỉ nhắc dòng chưa đạt và còn lượt">
                                Gửi nhắc
                            </button>
                        @endcan
                        <button type="button" wire:click="clearSelection" class="min-h-[40px] px-2 text-[13px] font-semibold hover:underline">Bỏ chọn</button>
                    </div>
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="admin-table" style="min-width: 1120px">
                    <thead>
                        <tr>
                            <th style="width: 44px">
                                <input type="checkbox" aria-label="Chọn cả trang" @checked($allOnPage) @disabled($pageKeys === [])
                                       wire:click="togglePage(@js($pageKeys))" class="h-4 w-4 rounded-sm border-brand-line text-brand-red focus:ring-brand-red">
                            </th>
                            @foreach ([
                                ['code', 'Mã NV', 'width: 92px', ''],
                                ['name', 'Họ tên', 'width: 130px', ''],
                                ['department', 'Phòng ban', 'width: 120px', ''],
                                ['quiz', 'Bài kiểm tra', 'width: 160px', ''],
                                ['attempts', 'Số lượt', 'width: 64px', 'text-right'],
                                ['first', 'Điểm lần đầu', 'width: 68px', 'text-right'],
                                ['best', 'Điểm cao nhất', 'width: 68px', 'text-right'],
                                ['last', 'Điểm lần cuối', 'width: 68px', 'text-right'],
                                [null, 'Kết quả cuối', 'width: 92px', ''],
                                ['pass_no', 'Lượt đến khi đạt', 'width: 72px', 'text-right'],
                                ['passed_at', 'Ngày đạt', 'width: 90px', ''],
                                ['remaining', 'Lượt còn lại', 'width: 68px', 'text-right'],
                            ] as [$key, $label, $style, $align])
                                <th class="{{ $align }}" style="{{ $style }}">
                                    @if ($key)
                                        <button type="button" wire:click="sortBy('{{ $key }}')" class="uppercase hover:text-brand-ink {{ $align }}">
                                            {{ $label }} {{ $arrow($key, $key === 'remaining') }}
                                        </button>
                                    @else
                                        {{ $label }}
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $r)
                            @php
                                $key = $r->employee_id . ':' . $r->quiz_id;
                                $isSelected = in_array($key, $selected, true);
                            @endphp
                            <tr wire:key="qr-{{ $key }}" @class(['bg-brand-red-tint' => $isSelected])>
                                <td>
                                    <input type="checkbox" wire:model.live="selected" value="{{ $key }}" aria-label="Chọn {{ $r->full_name }}"
                                           class="h-4 w-4 rounded-sm border-brand-line text-brand-red focus:ring-brand-red">
                                </td>
                                <td class="font-semibold text-brand-red">{{ $r->employee_code }}</td>
                                <td class="font-semibold">{{ $r->full_name }}</td>
                                <td class="text-brand-muted">{{ $r->department_name ?? '—' }}</td>
                                <td>{{ $r->quiz_title }}</td>
                                <td class="text-right tabular-nums">{{ (int) $r->used }}/{{ $r->allowed === null ? '∞' : (int) $r->allowed }}</td>
                                <td class="text-right tabular-nums">{{ Report::score($r->first_pct) }}</td>
                                <td class="text-right font-semibold tabular-nums">{{ Report::score($r->best_pct) }}</td>
                                <td class="text-right tabular-nums">{{ Report::score($r->last_pct) }}</td>
                                <td>
                                    @switch($r->final_result)
                                        @case('passed') <span class="tag bg-brand-ink font-semibold text-white">Đạt</span> @break
                                        @case('exhausted') <span class="tag bg-brand-red font-semibold text-white">Hết lượt</span> @break
                                        @default <span class="tag border border-brand-red bg-white text-brand-red">Chưa đạt</span>
                                    @endswitch
                                </td>
                                <td class="text-right tabular-nums">{{ $r->pass_no ?? '—' }}</td>
                                <td class="whitespace-nowrap tabular-nums">{{ $r->passed_at ? ReportPeriod::local($r->passed_at)->format('d/m/Y') : '—' }}</td>
                                <td @class(['text-right tabular-nums', 'font-semibold text-brand-red' => $r->final_result === 'exhausted'])>
                                    {{ $r->remaining === null ? '∞' : (int) $r->remaining }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="py-10 text-center text-sm text-brand-muted">
                                    Chưa có lượt nộp nào khớp bộ lọc trong kỳ {{ $period->label() }}.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-brand-line px-4 py-3 text-xs text-brand-muted">
                <span>Hiển thị {{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} / {{ $num($rows->total()) }} dòng</span>
                @if ($sort === '')
                    <span>Xếp theo lượt còn lại tăng dần nên nhóm hết lượt luôn nằm đầu bảng</span>
                @endif
                <span class="ml-auto">Số lượt = mọi lượt đã dùng / tối đa (gồm lượt cấp thêm)</span>
            </div>
            @if ($rows->hasPages())
                <div class="border-t border-brand-line px-4 py-3">{{ $rows->links(data: ['summary' => false]) }}</div>
            @endif
        @endif
    </div>
</div>
