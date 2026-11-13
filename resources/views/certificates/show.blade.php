{{--
    Chứng nhận hoàn thành khóa học — trang độc lập (không dùng layout có sidebar)
    để in hoặc "Lưu thành PDF" từ hộp thoại in của trình duyệt.

    Khổ A4 ngang: trên màn hình co giãn theo bề rộng (đơn vị cqw), khi in cố định
    297mm × 210mm, mọi chữ / hình trang trí đều co theo cùng tỉ lệ.
    Toàn bộ CSS viết tại chỗ, không phụ thuộc bản build Tailwind.
--}}
@php
    $employee = $certificate->employee;
    $course = $certificate->course;
    $issuedAt = $certificate->issued_at;
    $score = $certificate->score !== null
        ? rtrim(rtrim(number_format((float) $certificate->score, 2, ',', '.'), '0'), ',')
        : null;
@endphp
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Chứng nhận {{ $certificate->certificate_no }} — {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('brand/logo-mark.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap">
    <style>
        :root {
            --red: #c00016;
            --red-dark: #8f0010;
            --gold: #c9a24a;
            --gold-light: #e8cf8c;
            --ink: #141821;
            --muted: #6b7280;
            --line: #e4e7ee;
            --serif: 'Playfair Display', Georgia, 'Times New Roman', serif;
            --sans: 'Be Vietnam Pro', 'Segoe UI', Arial, sans-serif;
        }

        * { box-sizing: border-box; }

        html, body { margin: 0; }

        body {
            min-height: 100vh;
            background: #eef0f4;
            color: var(--ink);
            font-family: var(--sans);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ---- Thanh công cụ (ẩn khi in) ---- */
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 16px;
            background: #fff;
            border-bottom: 1px solid var(--line);
        }

        .toolbar-title { min-width: 0; font-size: 14px; font-weight: 600; }
        .toolbar-title small { display: block; color: var(--muted); font-weight: 400; font-size: 12px; }
        .toolbar-actions { display: flex; gap: 8px; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 40px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
            font: 600 13px/1 var(--sans);
            text-decoration: none;
            cursor: pointer;
        }

        .btn:hover { border-color: var(--red); color: var(--red); }
        .btn-primary { background: var(--red); border-color: var(--red); color: #fff; }
        .btn-primary:hover { background: var(--red-dark); border-color: var(--red-dark); color: #fff; }

        .notice {
            max-width: 1120px;
            margin: 16px auto 0;
            padding: 10px 14px;
            border: 1px solid #d97706;
            border-radius: 12px;
            background: #fff7ed;
            color: #b45309;
            font-size: 13px;
            font-weight: 600;
        }

        .stage { padding: 24px 16px 48px; }

        /* ---- Tờ chứng nhận A4 ngang ---- */
        .sheet {
            position: relative;
            width: 100%;
            max-width: 1120px;
            margin: 0 auto;
            aspect-ratio: 297 / 210;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 10px 40px rgba(5, 7, 10, .12);
            container-type: inline-size;
        }

        .corner { position: absolute; width: 22%; height: auto; pointer-events: none; }
        .corner-tl { top: 0; left: 0; }
        .corner-br { right: 0; bottom: 0; transform: rotate(180deg); }

        .guilloche { position: absolute; width: 30%; opacity: .5; pointer-events: none; }
        .guilloche-tr { top: 0; right: 0; }
        .guilloche-bl { left: 0; bottom: 0; transform: rotate(180deg); }

        .frame {
            position: absolute;
            inset: 4.2%;
            border: .14cqw solid var(--gold);
        }

        .frame::after {
            content: '';
            position: absolute;
            inset: .6cqw;
            border: .07cqw solid #e6d7b0;
        }

        .content {
            position: absolute;
            inset: 7% 9% 6%;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .logo { height: 2.6cqw; width: auto; }

        .title {
            margin: 1.6cqw 0 0;
            font-family: var(--serif);
            font-weight: 700;
            font-size: 5.1cqw;
            line-height: 1.1;
            color: var(--red);
            letter-spacing: .02em;
        }

        .awarded { margin: 1.1cqw 0 0; font-size: 1.65cqw; color: var(--ink); }

        .name {
            margin: 1.2cqw 0 0;
            font-family: var(--serif);
            font-weight: 700;
            font-size: 4.2cqw;
            line-height: 1.15;
            color: var(--ink);
        }

        .ornament { width: 16cqw; height: auto; margin-top: 1cqw; }

        .body-text {
            max-width: 78%;
            margin: 1cqw 0 0;
            font-size: 1.6cqw;
            line-height: 1.55;
        }

        .body-text strong { color: var(--red-dark); }

        .date-line {
            margin: 1.1cqw 0 0;
            font-family: var(--serif);
            font-weight: 600;
            font-size: 1.65cqw;
            color: var(--red);
        }

        .footer {
            width: 100%;
            margin-top: auto;
            display: grid;
            grid-template-columns: 1fr 1.2fr 1.3fr;
            align-items: end;
            gap: 2cqw;
        }

        .seal { width: 10.5cqw; height: auto; justify-self: center; }

        .signature { display: flex; flex-direction: column; align-items: center; }
        .signature svg { width: 13cqw; height: auto; }
        .signature .rule { width: 17cqw; border-top: .1cqw solid #c4c9d4; margin-top: .3cqw; }
        .signature .role { margin-top: .6cqw; font-size: 1.25cqw; font-weight: 600; }
        .signature .unit { font-size: 1.1cqw; color: var(--muted); }

        .issuer { font-size: 1.15cqw; line-height: 1.5; }
        .issuer b { display: block; font-size: 1.3cqw; }
        .issuer .issue-date { margin-top: .6cqw; font-size: 1.45cqw; font-weight: 600; }

        .meta {
            margin-top: 1.4cqw;
            font-size: .95cqw;
            color: var(--muted);
            letter-spacing: .01em;
        }

        .meta code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: var(--ink); }

        /* Màn hình rất hẹp: vẫn giữ tỉ lệ tờ A4, gợi ý xoay ngang */
        .rotate-hint { display: none; max-width: 1120px; margin: 0 auto 12px; color: var(--muted); font-size: 12px; text-align: center; }
        @media (max-width: 640px) { .rotate-hint { display: block; } }

        @page { size: A4 landscape; margin: 0; }

        @media print {
            body { background: #fff; min-height: 0; }
            .toolbar, .notice, .rotate-hint { display: none !important; }
            .stage { padding: 0; }
            .sheet {
                width: 297mm;
                max-width: none;
                height: 210mm;
                aspect-ratio: auto;
                margin: 0;
                box-shadow: none;
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <header class="toolbar">
        <div class="toolbar-title">
            Chứng nhận hoàn thành
            <small>{{ $course?->title }} · {{ $certificate->certificate_no }}</small>
        </div>
        <div class="toolbar-actions">
            <a href="{{ route('learn.progress') }}" class="btn">← Quay lại</a>
            <button type="button" class="btn btn-primary" onclick="window.print()">In / Lưu PDF</button>
        </div>
    </header>

    @unless ($certificate->isValid())
        <p class="notice">Chứng nhận này đã hết hạn ngày {{ $certificate->valid_until?->format('d/m/Y') }}.</p>
    @endunless

    <main class="stage">
        <p class="rotate-hint">Xoay ngang điện thoại để xem chứng nhận rõ hơn.</p>

        <article class="sheet" aria-label="Chứng nhận hoàn thành khóa học">
            {{-- Góc chéo trang trí: đỏ thương hiệu + sọc vàng --}}
            <svg class="corner corner-tl" viewBox="0 0 220 180" aria-hidden="true">
                <polygon points="0,0 220,0 0,180" fill="#c00016"/>
                <polygon points="150,0 172,0 0,141 0,123" fill="#e8cf8c"/>
                <polygon points="0,0 120,0 0,98" fill="#8f0010" opacity=".35"/>
            </svg>
            <svg class="corner corner-br" viewBox="0 0 220 180" aria-hidden="true">
                <polygon points="0,0 220,0 0,180" fill="#c00016"/>
                <polygon points="150,0 172,0 0,141 0,123" fill="#e8cf8c"/>
                <polygon points="0,0 120,0 0,98" fill="#8f0010" opacity=".35"/>
            </svg>

            {{-- Hoa văn đường cong mảnh ở hai góc còn lại --}}
            @foreach (['guilloche-tr', 'guilloche-bl'] as $pos)
                <svg class="guilloche {{ $pos }}" viewBox="0 0 300 220" fill="none" aria-hidden="true">
                    @for ($i = 0; $i < 14; $i++)
                        <path d="M{{ 60 + $i * 12 }},0 C{{ 120 + $i * 8 }},{{ 60 + $i * 4 }} {{ 200 + $i * 4 }},{{ 40 + $i * 6 }} 300,{{ 90 + $i * 9 }}"
                              stroke="#c9a24a" stroke-width=".6"/>
                    @endfor
                </svg>
            @endforeach

            <div class="frame" aria-hidden="true"></div>

            <div class="content">
                <img class="logo" src="{{ asset('brand/logo.svg') }}" alt="Techcombank">

                <h1 class="title">Chứng Nhận Hoàn Thành</h1>
                <p class="awarded">Chứng nhận này được trao cho</p>

                <p class="name">{{ $employee?->full_name ?? '—' }}</p>

                <svg class="ornament" viewBox="0 0 200 16" fill="none" aria-hidden="true">
                    <path d="M8 8h70M122 8h70" stroke="#c9a24a" stroke-width="1"/>
                    <path d="M0 8l8-5 8 5-8 5zM184 8l8-5 8 5-8 5z" stroke="#c9a24a" stroke-width="1"/>
                    <path d="M100 1l7 7-7 7-7-7z" fill="#c00016"/>
                    <circle cx="84" cy="8" r="2" fill="#c9a24a"/>
                    <circle cx="116" cy="8" r="2" fill="#c9a24a"/>
                </svg>

                <p class="body-text">
                    Đã hoàn thành xuất sắc khóa học <strong>{{ $course?->title ?? '—' }}</strong>@if ($score !== null) với kết quả đạt <strong>{{ $score }} điểm</strong>@endif,
                    thể hiện kiến thức, kỹ năng chuyên môn và tinh thần học tập nghiêm túc.
                </p>

                @if ($issuedAt)
                    <p class="date-line">
                        Cấp ngày {{ $issuedAt->format('d') }} tháng {{ $issuedAt->format('m') }} năm {{ $issuedAt->format('Y') }}
                    </p>
                @endif

                <div class="footer">
                    {{-- Con dấu tròn --}}
                    <svg class="seal" viewBox="0 0 200 200" aria-hidden="true">
                        <defs>
                            <path id="seal-arc-top" d="M40,100 a60,60 0 1,1 120,0"/>
                            <path id="seal-arc-bottom" d="M34,100 a66,66 0 0,0 132,0"/>
                        </defs>
                        @php
                            $points = [];
                            for ($i = 0; $i < 48; $i++) {
                                $r = $i % 2 === 0 ? 98 : 88;
                                $a = deg2rad($i * 7.5);
                                $points[] = round(100 + $r * cos($a), 2) . ',' . round(100 + $r * sin($a), 2);
                            }
                        @endphp
                        <polygon points="{{ implode(' ', $points) }}" fill="#c00016"/>
                        <circle cx="100" cy="100" r="80" fill="none" stroke="#e8cf8c" stroke-width="2"/>
                        <circle cx="100" cy="100" r="74" fill="none" stroke="#e8cf8c" stroke-width="1" stroke-dasharray="3 3"/>
                        <circle cx="100" cy="100" r="44" fill="#fff" opacity=".1"/>
                        <text font-family="Be Vietnam Pro, Arial, sans-serif" font-size="13" font-weight="700" fill="#fff" letter-spacing="3">
                            <textPath href="#seal-arc-top" startOffset="50%" text-anchor="middle">TECHCOMBANK</textPath>
                        </text>
                        <text font-family="Be Vietnam Pro, Arial, sans-serif" font-size="10" font-weight="600" fill="#e8cf8c" letter-spacing="2">
                            <textPath href="#seal-arc-bottom" startOffset="50%" text-anchor="middle">★ ĐÀO TẠO NỘI BỘ ★</textPath>
                        </text>
                        <text x="100" y="96" text-anchor="middle" font-family="Playfair Display, Georgia, serif" font-size="20" font-weight="700" fill="#fff">CHỨNG</text>
                        <text x="100" y="118" text-anchor="middle" font-family="Playfair Display, Georgia, serif" font-size="20" font-weight="700" fill="#fff">NHẬN</text>
                    </svg>

                    {{-- Chữ ký (nét ký tượng trưng, không gắn tên cá nhân) --}}
                    <div class="signature">
                        <svg viewBox="0 0 220 70" fill="none" aria-hidden="true">
                            <path d="M12 52c18-30 34-44 44-40 9 4-8 34-2 38 7 5 22-26 31-24 8 2-3 22 4 24 9 3 18-18 27-16 8 2 2 16 10 16 14 0 30-20 52-24 12-2 22 0 30 4"
                                  stroke="#141821" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M40 60c40-6 90-8 150-4" stroke="#141821" stroke-width="1.4" stroke-linecap="round"/>
                        </svg>
                        <span class="rule"></span>
                        <span class="role">Giám đốc Trung tâm Đào tạo</span>
                        <span class="unit">Khối Nhân sự</span>
                    </div>

                    <div class="issuer">
                        <b>Ngân hàng TMCP Kỹ Thương Việt Nam</b>
                        Trung tâm Đào tạo &amp; Phát triển Nhân lực<br>
                        Hệ thống {{ config('app.name') }}
                        <div class="issue-date">{{ $issuedAt?->format('d/m/Y') ?? '—' }}</div>
                    </div>
                </div>

                <p class="meta">
                    Mã chứng nhận: <code>{{ $certificate->certificate_no }}</code>
                    · Mã xác thực: <code>{{ $certificate->verification_code }}</code>
                    @if ($certificate->valid_until)
                        · Hiệu lực đến: {{ $certificate->valid_until->format('d/m/Y') }}
                    @endif
                </p>
            </div>
        </article>
    </main>
</body>
</html>
