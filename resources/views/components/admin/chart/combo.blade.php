@props([
    // Nhãn trục X, một nhãn mỗi điểm/cột
    'labels' => [],
    // Chuỗi cột: [['name' => 'Xem', 'values' => [...], 'color' => '#141821'], ...]
    'bars' => [],
    // true: cột chồng lên nhau; false: cột đứng cạnh nhau
    'stacked' => false,
    // Chuỗi đường: [['name' => ..., 'values' => [...], 'color' => ..., 'axis' => 'left'|'right', 'dashed' => bool, 'dots' => bool]]
    'lines' => [],
    // Vạch mục tiêu: ['value' => 90, 'axis' => 'right', 'label' => 'Mục tiêu 90%']
    'target' => null,
    'leftMax' => null,
    'rightMax' => null,
    'leftSuffix' => '',
    'rightSuffix' => '',
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
    'label' => 'Biểu đồ',
])

{{--
    Biểu đồ cột + đường vẽ bằng SVG thuần (mạng nội bộ ngân hàng chặn CDN,
    SVG in ra PDF vẫn nét). Hỗ trợ hai trục Y: trục trái cho số lượng,
    trục phải cho tỷ lệ — như "lượt hoàn thành" và "tỷ lệ lũy kế".
--}}
@php
    $labels = array_values(collect($labels)->all());
    $n = max(1, count($labels));
    $bars = array_values(collect($bars)->all());
    $lines = array_values(collect($lines)->all());

    $W = max(320, (int) $width);
    $H = (int) $height;
    $padL = 44;
    $padR = collect($lines)->contains(fn ($l) => ($l['axis'] ?? 'left') === 'right') || ($target['axis'] ?? null) === 'right' ? 44 : 12;
    $padT = 14;
    $padB = 26;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

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
        (($target['axis'] ?? 'left') === 'right') ? $rightValues[] = (float) $target['value'] : $leftValues[] = (float) $target['value'];
    }

    // Làm tròn trục lên mốc dễ đọc (1, 2, 2.5, 5 × 10^n), chia 4 vạch
    $nice = function (float $max): float {
        if ($max <= 0) return 4;
        $exp = 10 ** floor(log10($max / 4));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $exp * 4 >= $max) return $m * $exp * 4;
        }
        return $max;
    };
    $lMax = $leftMax ?? $nice(max($leftValues));
    $rMax = $rightMax ?? $nice(max($rightValues));

    $slot = $plotW / $n;
    $xCenter = fn ($i) => $padL + $slot * ($i + 0.5);
    $yL = fn ($v) => $padT + $plotH - ((float) $v / ($lMax ?: 1)) * $plotH;
    $yR = fn ($v) => $padT + $plotH - ((float) $v / ($rMax ?: 1)) * $plotH;
    $fmt = fn ($v) => $v >= 1000 ? number_format($v, 0, ',', '.') : rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');

    $every = $tickEvery ?? max(1, (int) ceil($n / 8));
    $barGroupW = $slot * 0.62;
    $barCount = max(1, count($bars));
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    <svg viewBox="0 0 {{ $W }} {{ $H }}" class="block h-auto w-full" role="img" aria-label="{{ $label }}">
        {{-- Lưới + nhãn trục --}}
        @foreach (range(0, 4) as $t)
            @php $y = $padT + $plotH - $t / 4 * $plotH; @endphp
            <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y }}" y2="{{ $y }}" stroke="#eef0f4" />
            <text x="{{ $padL - 6 }}" y="{{ $y + 3.5 }}" text-anchor="end" font-size="10" fill="#6b7280">{{ $fmt($lMax * $t / 4) }}{{ $leftSuffix }}</text>
            @if ($padR > 12)
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
            <g @if ($clickAction) wire:click="{{ $clickAction }}({{ $i }})" style="cursor: pointer" @endif opacity="{{ $dim ? 0.35 : 1 }}">
                <title>{{ $title }}</title>
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
        @endforeach

        {{-- Vạch mục tiêu --}}
        @if ($target)
            @php $ty = ($target['axis'] ?? 'left') === 'right' ? $yR($target['value']) : $yL($target['value']); @endphp
            <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $ty }}" y2="{{ $ty }}" stroke="#c00016" stroke-dasharray="5 4" />
            @if (! empty($target['label']))
                <text x="{{ $W - $padR - 4 }}" y="{{ $ty - 5 }}" text-anchor="end" font-size="10" fill="#c00016">{{ $target['label'] }}</text>
            @endif
        @endif

        {{-- Đường --}}
        @foreach ($lines as $li => $l)
            @php
                $toY = ($l['axis'] ?? 'left') === 'right' ? $yR : $yL;
                $pts = collect($l['values'])->map(fn ($v, $i) => $v === null ? null : round($xCenter($i), 1) . ',' . round($toY($v), 1))->filter()->implode(' ');
                $lastIndex = collect($l['values'])->filter(fn ($v) => $v !== null)->keys()->last();
            @endphp
            @if ($pts !== '')
                <polyline points="{{ $pts }}" fill="none" stroke="{{ $l['color'] }}" stroke-width="2"
                          @if (! empty($l['dashed'])) stroke-dasharray="5 4" @endif stroke-linejoin="round" />
                @if (! empty($l['dots']))
                    @foreach ($l['values'] as $i => $v)
                        @if ($v !== null)
                            <circle cx="{{ $xCenter($i) }}" cy="{{ $toY($v) }}" r="2.5" fill="{{ $l['color'] }}"><title>{{ $labels[$i] ?? '' }} · {{ $l['name'] }}: {{ $fmt((float) $v) }}</title></circle>
                        @endif
                    @endforeach
                @endif
                @if ($lastIndex !== null)
                    <circle cx="{{ $xCenter($lastIndex) }}" cy="{{ $toY($l['values'][$lastIndex]) }}" r="3.5" fill="{{ $l['color'] }}" />
                    @if ($li === 0 && $endNote)
                        <text x="{{ $xCenter($lastIndex) - 6 }}" y="{{ $toY($l['values'][$lastIndex]) - 8 }}" text-anchor="end" font-size="10.5" font-weight="600" fill="{{ $l['color'] }}">{{ $endNote }}</text>
                    @endif
                @endif
            @endif
        @endforeach

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
</div>
