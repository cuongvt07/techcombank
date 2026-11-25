@props([
    // Nhãn trục X, một nhãn mỗi điểm/cột
    'labels' => [],
    // Chuỗi cột: [['name' => 'Xem', 'values' => [...], 'color' => '#141821'], ...]
    'bars' => [],
    // true: cột chồng lên nhau; false: cột đứng cạnh nhau
    'stacked' => false,
    // Chuỗi đường: [['name' => ..., 'values' => [...], 'color' => ..., 'axis' => 'left'|'right',
    //               'dashed' => bool, 'dots' => bool, 'area' => bool (tô nhạt dưới đường)]]
    'lines' => [],
    // Vạch mục tiêu: ['value' => 90, 'axis' => 'right', 'label' => 'Mục tiêu 90%']
    'target' => null,
    'leftMax' => null,
    'rightMax' => null,
    'leftSuffix' => '',
    'rightSuffix' => '',
    // Tên trục (kèm đơn vị) in ở đầu trục, vd "Lượt hoàn thành"
    'leftTitle' => null,
    'rightTitle' => null,
    'height' => 220,
    // Bề rộng khung vẽ (đơn vị SVG). Biểu đồ trong khối nửa trang nên đặt ~520 để chữ trục
    // không bị thu nhỏ quá mức khi SVG co theo bề ngang thật.
    'width' => 800,
    // Chỉ in nhãn trục X mỗi N điểm (tránh chồng chữ khi nhiều ngày)
    'tickEvery' => null,
    // Tên method Livewire được gọi khi bấm một cột/điểm, nhận chỉ số (index) làm tham số
    'clickAction' => null,
    // Chỉ số được tô nổi (vd. cột đang lọc) — các cột khác bị làm mờ
    'activeIndex' => null,
    // Chỉ tô đỏ nhãn trục X của một điểm (vd. ngày bất thường), không làm mờ cột khác
    'highlightIndex' => null,
    // Ghi chú cạnh điểm cuối của đường đầu tiên, vd "37 tồn đọng"
    'endNote' => null,
    // Rê chuột hiện vạch dọc + hộp giá trị mọi chuỗi tại ngày đó (Alpine, không cần thư viện)
    'crosshair' => false,
    'label' => 'Biểu đồ',
])

{{--
    Biểu đồ cột + đường vẽ bằng SVG thuần (mạng nội bộ ngân hàng chặn CDN,
    SVG in ra PDF vẫn nét). Hỗ trợ hai trục Y: trục trái cho số lượng,
    trục phải cho tỷ lệ — như "lượt hoàn thành" và "tỷ lệ lũy kế".
    Trục chỉ gồm số nguyên khi mọi giá trị của trục đó là số nguyên (số đếm).
--}}
@php
    $labels = array_values(collect($labels)->all());
    $n = max(1, count($labels));
    $bars = array_values(collect($bars)->all());
    $lines = array_values(collect($lines)->all());

    // Giá trị lớn nhất trục trái: tổng cột chồng hoặc cột cao nhất, cùng các đường trục trái
    $leftValues = [0];
    foreach (range(0, $n - 1) as $i) {
        if ($stacked) {
            $leftValues[] = collect($bars)->sum(fn ($b) => (float) ($b['values'][$i] ?? 0));
        } else {
            foreach ($bars as $b) {
                $leftValues[] = (float) ($b['values'][$i] ?? 0);
            }
        }
    }
    $rightValues = [0];
    foreach ($lines as $l) {
        foreach ($l['values'] as $v) {
            if ($v === null) continue;
            if (($l['axis'] ?? 'left') === 'right') $rightValues[] = (float) $v; else $leftValues[] = (float) $v;
        }
    }
    if ($target) {
        if (($target['axis'] ?? 'left') === 'right') {
            $rightValues[] = (float) $target['value'];
        } else {
            $leftValues[] = (float) $target['value'];
        }
    }

    $hasRightSeries = collect($lines)->contains(fn ($l) => ($l['axis'] ?? 'left') === 'right') || ($target['axis'] ?? null) === 'right';
    // Trục phải chỉ có ý nghĩa khi chuỗi của nó có giá trị khác 0 (hoặc đặt sẵn thang)
    $showRight = $hasRightSeries && ($rightMax !== null || max($rightValues) > 0);

    $W = max(320, (int) $width);
    $H = (int) $height;
    $padL = 44;
    $padR = $showRight ? 44 : 12;
    $padT = $leftTitle || $rightTitle ? 26 : 14;
    $padB = 26;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $isInt = fn (array $vals) => collect($vals)->every(fn ($v) => (float) $v == floor((float) $v));

    // Làm tròn trục lên mốc dễ đọc (1, 2, 2.5, 5 × 10^n), chia 4 vạch; trục số đếm thì bước nguyên
    $nice = function (float $max, bool $integer): float {
        if ($max <= 0) return 4;
        $exp = 10 ** floor(log10($max / 4));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            $step = $m * $exp;
            if ($integer && ($step < 1 || floor($step) != $step)) continue;
            if ($step * 4 >= $max) return $step * 4;
        }
        return $integer ? ceil($max / 4) * 4 : $max;
    };
    $lMax = $leftMax ?? $nice(max($leftValues), $isInt($leftValues) && $leftSuffix !== '%');
    $rMax = $rightMax ?? $nice(max($rightValues), $isInt($rightValues) && $rightSuffix !== '%');

    $slot = $plotW / $n;
    $xCenter = fn ($i) => $padL + $slot * ($i + 0.5);
    $yL = fn ($v) => $padT + $plotH - ((float) $v / ($lMax ?: 1)) * $plotH;
    $yR = fn ($v) => $padT + $plotH - ((float) $v / ($rMax ?: 1)) * $plotH;
    $fmt = fn ($v) => $v >= 1000 ? number_format($v, 0, ',', '.') : rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');

    $every = $tickEvery ?? max(1, (int) ceil($n / 8));
    $barGroupW = $slot * 0.62;
    $barCount = max(1, count($bars));
    $uid = 'cb' . substr(md5(json_encode([$labels, $bars, $lines, $label])), 0, 10);

    // Dữ liệu cho hộp giá trị khi rê chuột: tên, màu, giá trị và toạ độ y từng điểm
    $tipSeries = collect($lines)->map(fn ($l) => [
        'name' => $l['name'], 'color' => $l['color'], 'dashed' => ! empty($l['dashed']),
        'suffix' => ($l['axis'] ?? 'left') === 'right' ? $rightSuffix : $leftSuffix,
        'values' => array_values($l['values']),
        'ys' => collect($l['values'])->map(fn ($v) => $v === null ? null : round((($l['axis'] ?? 'left') === 'right' ? $yR : $yL)($v), 1))->values()->all(),
    ])->merge(collect($bars)->map(fn ($b) => [
        'name' => $b['name'], 'color' => $b['color'], 'dashed' => false, 'suffix' => $leftSuffix,
        'values' => array_values($b['values']), 'ys' => [],
    ]))->values()->all();
@endphp

<div {{ $attributes->merge(['class' => 'relative w-full']) }} wire:key="{{ $uid }}"
     @if ($crosshair)
         x-data="{
             i: null, n: {{ $n }}, W: {{ $W }}, padL: {{ $padL }}, slot: {{ $slot }},
             labels: @js($labels), series: @js($tipSeries),
             move(e) {
                 const r = this.$refs.svg.getBoundingClientRect();
                 const x = (e.clientX - r.left) / r.width * this.W;
                 const i = Math.floor((x - this.padL) / this.slot);
                 this.i = i >= 0 && i < this.n ? i : null;
             },
             cx() { return this.i === null ? 0 : this.padL + this.slot * (this.i + 0.5); },
             tipPos() {
                 const p = this.cx() / this.W * 100;
                 return p > 60 ? { left: 'auto', right: (100 - p + 1.5) + '%' } : { left: (p + 1.5) + '%', right: 'auto' };
             },
             fmt(v) { return v === null || v === undefined ? '—' : Number(v).toLocaleString('vi-VN', { maximumFractionDigits: 1 }); },
         }"
         x-on:mousemove="move($event)" x-on:mouseleave="i = null"
     @endif>
    <svg viewBox="0 0 {{ $W }} {{ $H }}" class="block h-auto w-full" role="img" aria-label="{{ $label }}" x-ref="svg">
        @if (collect($lines)->contains(fn ($l) => ! empty($l['area'])))
            <defs>
                @foreach ($lines as $li => $l)
                    @if (! empty($l['area']))
                        <linearGradient id="{{ $uid }}-area-{{ $li }}" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="{{ $l['color'] }}" stop-opacity="0.14" />
                            <stop offset="100%" stop-color="{{ $l['color'] }}" stop-opacity="0" />
                        </linearGradient>
                    @endif
                @endforeach
            </defs>
        @endif

        {{-- Tên trục --}}
        @if ($leftTitle)
            <text x="0" y="11" text-anchor="start" font-size="10" font-weight="600" fill="#5f6672">{{ $leftTitle }}</text>
        @endif
        @if ($rightTitle && $showRight)
            <text x="{{ $W }}" y="11" text-anchor="end" font-size="10" font-weight="600" fill="#5f6672">{{ $rightTitle }}</text>
        @endif

        {{-- Lưới ngang mảnh + nhãn trục (không có lưới dọc) --}}
        @foreach (range(0, 4) as $t)
            @php $y = $padT + $plotH - $t / 4 * $plotH; @endphp
            <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y }}" y2="{{ $y }}" stroke="{{ $t === 0 ? '#d9dde3' : '#EDF0F3' }}" />
            <text x="{{ $padL - 6 }}" y="{{ $y + 3.5 }}" text-anchor="end" font-size="10" fill="#6b7280">{{ $fmt($lMax * $t / 4) }}{{ $leftSuffix }}</text>
            @if ($showRight)
                <text x="{{ $W - $padR + 6 }}" y="{{ $y + 3.5 }}" font-size="10" fill="#6b7280">{{ $fmt($rMax * $t / 4) }}{{ $rightSuffix }}</text>
            @endif
        @endforeach

        {{-- Cột --}}
        @foreach (range(0, $n - 1) as $i)
            @php
                $active = $activeIndex !== null && (int) $activeIndex === $i;
                $dim = $activeIndex !== null && ! $active;
                $base = $padT + $plotH;
                $title = ($labels[$i] ?? '') . ': ' . collect($bars)->map(fn ($b) => $b['name'] . ' ' . $fmt((float) ($b['values'][$i] ?? 0)))->implode(', ');
            @endphp
            @if ($bars || $clickAction)
                <g @if ($clickAction) wire:click="{{ $clickAction }}({{ $i }})" style="cursor: pointer" @endif opacity="{{ $dim ? 0.35 : 1 }}">
                    @if ($bars && ! $crosshair)
                        <title>{{ $title }}</title>
                    @endif
                    {{-- Vùng bấm phủ cả khe, dễ bấm cả khi cột thấp --}}
                    @if ($clickAction)
                        <rect x="{{ $padL + $slot * $i }}" y="{{ $padT }}" width="{{ $slot }}" height="{{ $plotH }}" fill="transparent" />
                    @endif
                    @foreach ($bars as $k => $b)
                        @php
                            $v = (float) ($b['values'][$i] ?? 0);
                            $h = $v / ($lMax ?: 1) * $plotH;
                            if ($stacked) {
                                $x = $xCenter($i) - $barGroupW / 2;
                                $w = $barGroupW;
                                $y = $base - $h;
                                $base = $y;
                            } else {
                                $w = $barGroupW / $barCount;
                                $x = $xCenter($i) - $barGroupW / 2 + $k * $w;
                                $y = $padT + $plotH - $h;
                            }
                        @endphp
                        @if ($h > 0)
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ max(1, $w - ($stacked ? 0 : 2)) }}" height="{{ $h }}" fill="{{ $b['color'] }}" />
                        @endif
                    @endforeach
                </g>
            @endif
        @endforeach

        {{-- Vạch mục tiêu --}}
        @if ($target)
            @php $ty = ($target['axis'] ?? 'left') === 'right' ? $yR($target['value']) : $yL($target['value']); @endphp
            <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $ty }}" y2="{{ $ty }}" stroke="#c00016" stroke-dasharray="5 4" />
            @if (! empty($target['label']))
                <text x="{{ $W - $padR - 4 }}" y="{{ $ty - 5 }}" text-anchor="end" font-size="10" fill="#c00016">{{ $target['label'] }}</text>
            @endif
        @endif

        {{-- Vạch dọc theo chuột --}}
        @if ($crosshair)
            <line x-show="i !== null" x-cloak x-bind:x1="cx()" x-bind:x2="cx()" y1="{{ $padT }}" y2="{{ $padT + $plotH }}"
                  stroke="#9aa0aa" stroke-width="1" stroke-dasharray="3 3" style="pointer-events: none" />
        @endif

        {{-- Đường (vẽ ngược để đường đầu tiên — chuỗi chính — nằm trên cùng) --}}
        @foreach (array_reverse($lines, true) as $li => $l)
            @php
                $toY = ($l['axis'] ?? 'left') === 'right' ? $yR : $yL;
                $coords = collect($l['values'])->map(fn ($v, $i) => $v === null ? null : [round($xCenter($i), 1), round($toY($v), 1)])->filter();
                $pts = $coords->map(fn ($p) => $p[0] . ',' . $p[1])->implode(' ');
                $lastIndex = collect($l['values'])->filter(fn ($v) => $v !== null)->keys()->last();
            @endphp
            @if ($pts !== '')
                @if (! empty($l['area']) && $coords->count() > 1)
                    <polygon points="{{ $coords->first()[0] }},{{ $padT + $plotH }} {{ $pts }} {{ $coords->last()[0] }},{{ $padT + $plotH }}"
                             fill="url(#{{ $uid }}-area-{{ $li }})" />
                @endif
                <polyline points="{{ $pts }}" fill="none" stroke="{{ $l['color'] }}" stroke-width="2.6"
                          @if (! empty($l['dashed'])) stroke-dasharray="5 5" @endif stroke-linejoin="round" stroke-linecap="round" />
                @if (! empty($l['dots']))
                    @foreach ($l['values'] as $i => $v)
                        @if ($v !== null)
                            <circle cx="{{ $xCenter($i) }}" cy="{{ $toY($v) }}" r="2.5" fill="{{ $l['color'] }}"><title>{{ $labels[$i] ?? '' }} · {{ $l['name'] }}: {{ $fmt((float) $v) }}</title></circle>
                        @endif
                    @endforeach
                @endif
                @if ($lastIndex !== null && empty($l['dashed']))
                    <circle cx="{{ $xCenter($lastIndex) }}" cy="{{ $toY($l['values'][$lastIndex]) }}" r="3.5" fill="{{ $l['color'] }}" stroke="#fff" stroke-width="1.5" />
                    @if ($li === 0 && $endNote)
                        <text x="{{ $xCenter($lastIndex) - 6 }}" y="{{ $toY($l['values'][$lastIndex]) - 8 }}" text-anchor="end" font-size="10.5" font-weight="600" fill="{{ $l['color'] }}">{{ $endNote }}</text>
                    @endif
                @endif
            @endif
        @endforeach

        {{-- Chấm của từng đường tại ngày đang rê chuột --}}
        @if ($crosshair)
            @foreach ($tipSeries as $k => $s)
                @if ($s['ys'])
                    <circle x-show="i !== null && series[{{ $k }}].ys[i] !== null" x-cloak r="4" fill="#fff" stroke="{{ $s['color'] }}" stroke-width="2"
                            x-bind:cx="cx()" x-bind:cy="i === null ? 0 : series[{{ $k }}].ys[i]" style="pointer-events: none" />
                @endif
            @endforeach
        @endif

        {{-- Nhãn trục X --}}
        @foreach ($labels as $i => $lbl)
            {{-- Luôn in nhãn cuối; bỏ nhãn đều nào quá sát nhãn cuối để không đè chữ --}}
            @if (($i % $every === 0 && ($n - 1 - $i) >= max(1, $every / 2)) || $i === $n - 1)
                <text x="{{ $xCenter($i) }}" y="{{ $H - 8 }}" text-anchor="middle" font-size="10"
                      @php $hot = ($activeIndex !== null && (int) $activeIndex === $i) || ($highlightIndex !== null && (int) $highlightIndex === $i); @endphp
                      fill="{{ $hot ? '#c00016' : '#6b7280' }}" @if ($hot) font-weight="600" @endif>{{ $lbl }}</text>
            @endif
        @endforeach
    </svg>

    {{-- Hộp giá trị: cả các chuỗi tại một ngày --}}
    @if ($crosshair)
        <div x-show="i !== null" x-cloak role="tooltip"
             class="pointer-events-none absolute top-1 z-20 min-w-[180px] rounded-admin border border-brand-line bg-white px-3 py-2 text-xs"
             style="box-shadow: 0 4px 14px rgba(17, 22, 28, .08)" x-bind:style="tipPos()">
            <p class="mb-1 font-semibold text-brand-ink" x-text="labels[i] ?? ''"></p>
            <template x-for="s in series" :key="s.name">
                <p class="flex items-center gap-2 py-0.5">
                    <span class="w-3 shrink-0 border-t-2" x-bind:class="s.dashed && 'border-dashed'" x-bind:style="`border-color: ${s.color}`"></span>
                    <span class="min-w-0 flex-1 text-brand-muted" x-text="s.name"></span>
                    <strong class="tabular-nums text-brand-ink" x-text="fmt(s.values[i]) + (s.values[i] === null || s.values[i] === undefined ? '' : s.suffix)"></strong>
                </p>
            </template>
        </div>
    @endif
</div>
