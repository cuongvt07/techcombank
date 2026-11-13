@php
    /** @var \App\Support\Reports\TemplateReport $report */
    use App\Support\Reports\TemplateReport;

    $org = TemplateReport::org();
    $columns = array_values($report->columns);
    $twoTier = $report->hasGroups();
    $totalWidth = max(1, array_sum(array_map(fn ($c) => $c['width'] ?? 14, $columns)));

    // Vẽ một dòng ô: tôn trọng colspan và căn lề mặc định của cột
    $renderRow = function (array $cells) use ($columns) {
        $html = '';
        $col = 0;
        foreach ($cells as $cell) {
            $span = max(1, (int) ($cell['span'] ?? 1));
            $align = $cell['align'] ?? ($columns[$col]['align'] ?? 'left');
            $classes = trim('al-' . $align . (! empty($cell['tone']) ? ' tone-' . $cell['tone'] : '') . (! empty($cell['bold']) ? ' b' : ''));
            $html .= '<td' . ($span > 1 ? ' colspan="' . $span . '"' : '') . ' class="' . e($classes) . '">' . e($cell['text']) . '</td>';
            $col += $span;
        }

        return new \Illuminate\Support\HtmlString($html);
    };
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $report->title }} · {{ $report->periodLabel }}</title>
    <link rel="icon" href="{{ asset('brand/logo-mark.svg') }}" type="image/svg+xml">
    {{-- CSS viết thuần (không qua Tailwind build) để trang in luôn đúng khuôn --}}
    <style>
        @page { size: A4 {{ $report->landscape ? 'landscape' : 'portrait' }}; margin: 12mm 10mm 14mm; }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { margin: 0; background: #e5e7eb; color: #111827; font-family: "Times New Roman", Times, serif; font-size: 13px; }
        .toolbar { position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
                   padding: 10px 16px; background: #111827; color: #f9fafb; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 13px; }
        .toolbar .spacer { flex: 1; }
        .toolbar .hint { color: #d1d5db; font-size: 12px; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 4px; border: 1px solid #4b5563;
               background: #1f2937; color: #f9fafb; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .btn:hover { background: #374151; }
        .btn-primary { background: #c00016; border-color: #c00016; }
        .btn-primary:hover { background: #a00012; }
        .sheet { width: {{ $report->landscape ? '297mm' : '210mm' }}; max-width: calc(100% - 32px); margin: 16px auto; padding: 12mm 10mm;
                 background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, .15); }

        .org { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 8px; border-bottom: 4px double #c00016; }
        .org-name { margin: 0; color: #c00016; font-size: 16px; font-weight: 700; }
        .org-line { margin: 4px 0 0; color: #4b5563; font-size: 12px; }
        /* Logo dạng đứng như mẫu: hình thoi ở trên, chữ TECHCOMBANK nhỏ bên dưới */
        .org-logo { display: flex; flex-direction: column; align-items: center; gap: 3px; flex-shrink: 0; }
        .org-logo img { height: 26px; width: auto; }
        .org-logo span { color: #c00016; font-family: Arial, Helvetica, sans-serif; font-size: 11px; font-weight: 800; letter-spacing: .04em; }
        h1 { margin: 18px 0 4px; text-align: center; font-size: 19px; font-weight: 700; text-transform: uppercase; }
        .meta { margin: 0 0 12px; text-align: center; font-size: 12.5px; }
        .summary { margin: 0 0 12px; padding: 7px 12px; border: 1px solid #7cb342; border-radius: 3px; background: #eef7ee; color: #1b5e20; font-weight: 700; font-size: 12.5px; }

        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 12px; }
        .grid th { padding: 6px 4px; border: 1px solid #e5e7eb; background: #c00016; color: #fff; font-weight: 700; text-align: center; vertical-align: middle; }
        .grid td { padding: 6px 5px; border: 1px solid #d1d5db; vertical-align: middle; word-wrap: break-word; overflow-wrap: anywhere; }
        .grid tbody tr:nth-child(even) td { background: #fafafa; }
        .grid tbody tr.total td { background: #fdf2f2; color: #c00016; font-weight: 700; border-top: 2px solid #c00016; }
        .grid td.empty { padding: 18px; color: #6b7280; font-style: italic; text-align: center; }
        .al-left { text-align: left; } .al-center { text-align: center; } .al-right { text-align: right; }
        .b { font-weight: 700; }
        .tone-great { color: #1e8e3e; font-weight: 700; } .tone-good { color: #1a56db; font-weight: 700; }
        .tone-warn { color: #b45309; font-weight: 700; } .tone-bad { color: #c81e1e; font-weight: 700; }
        .tone-muted { color: #6b7280; }

        .notes { margin: 8px 0 0; padding: 0; list-style: none; color: #4b5563; font-size: 11px; font-style: italic; }
        .notes li { margin-top: 2px; }
        .sign { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-top: 26px; text-align: center; font-size: 13px; break-inside: avoid; page-break-inside: avoid; }
        .sign strong { display: block; }
        .sign em { display: block; font-size: 12px; }
        .sign .name { margin-top: 64px; font-style: italic; }

        @media (max-width: 640px) {
            .sheet { padding: 16px; max-width: calc(100% - 16px); margin: 8px auto; overflow-x: auto; }
            table.grid { min-width: 720px; }
            .org-logo img { height: 18px; }
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .sheet { width: auto; max-width: none; margin: 0; padding: 0; box-shadow: none; }
            table.grid { min-width: 0; }
            .grid thead { display: table-header-group; }
            .grid tfoot { display: table-footer-group; }
            .grid tr { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <nav class="toolbar" aria-label="Thao tác báo cáo">
        @if ($report->backUrl)
            <a href="{{ $report->backUrl }}" class="btn">← Quay lại báo cáo</a>
        @endif
        <span class="spacer"></span>
        <span class="hint">Chọn "Lưu dưới dạng PDF" trong hộp thoại in để tải bản PDF.</span>
        <button type="button" class="btn btn-primary" onclick="window.print()">In / Lưu PDF</button>
        <a href="{{ $excelUrl }}" class="btn">Tải Excel theo mẫu</a>
    </nav>

    <main class="sheet">
        <header class="org">
            <div>
                <p class="org-name">{{ $org['name'] }}</p>
                <p class="org-line">{{ $org['address'] }}</p>
                <p class="org-line">{{ $org['division'] }}</p>
            </div>
            <div class="org-logo">
                <img src="{{ asset('brand/logo-mark.svg') }}" alt="">
                <span>TECHCOMBANK</span>
            </div>
        </header>

        <h1>{{ $report->title }}</h1>
        <p class="meta">Kỳ báo cáo: {{ $report->periodLabel }} &nbsp;|&nbsp; Đơn vị: {{ $report->unitLabel }}</p>

        @if ($report->summary !== [])
            <p class="summary">{{ $report->summaryLine() }}</p>
        @endif

        <table class="grid">
            <colgroup>
                @foreach ($columns as $column)
                    <col style="width: {{ round(($column['width'] ?? 14) / $totalWidth * 100, 2) }}%">
                @endforeach
            </colgroup>
            <thead>
                <tr>
                    @foreach ($report->headerBlocks() as $block)
                        @if ($block['grouped'])
                            <th colspan="{{ $block['span'] }}" scope="colgroup">{{ $block['label'] }}</th>
                        @else
                            <th @if ($twoTier) rowspan="2" @endif scope="col">{{ $block['label'] }}</th>
                        @endif
                    @endforeach
                </tr>
                @if ($twoTier)
                    <tr>
                        @foreach ($columns as $column)
                            @if (! empty($column['group']))
                                <th scope="col">{{ $column['label'] }}</th>
                            @endif
                        @endforeach
                    </tr>
                @endif
            </thead>
            <tbody>
                @forelse ($report->rows as $cells)
                    <tr>{{ $renderRow($cells) }}</tr>
                @empty
                    <tr><td colspan="{{ count($columns) }}" class="empty">{{ $report->emptyText }}</td></tr>
                @endforelse
                @if ($report->totals !== null)
                    <tr class="total">{{ $renderRow($report->totals) }}</tr>
                @endif
            </tbody>
        </table>

        @if ($report->notes !== [])
            <ul class="notes">
                @foreach ($report->notes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        @endif

        <section class="sign" aria-label="Chữ ký">
            <div>
                <strong>Người lập báo cáo</strong>
                <em>(Ký, ghi rõ họ tên)</em>
                @if ($report->preparedBy)
                    <div class="name">{{ $report->preparedBy }}</div>
                @endif
            </div>
            <div>
                <strong>{{ TemplateReport::placeAndDate() }}</strong>
            </div>
            <div>
                <strong>{{ $org['approver'] }}</strong>
                <em>(Ký, ghi rõ họ tên)</em>
            </div>
        </section>
    </main>
</body>
</html>
