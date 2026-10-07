from pathlib import Path
import re
from PIL import Image, ImageDraw, ImageFont
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'output'
SHOTS = OUT / 'playwright'
SCREENSHOTS = OUT / 'screenshots'
ASSETS = OUT / 'report-assets'
ASSETS.mkdir(parents=True, exist_ok=True)
DOCX_PATH = OUT / 'Techcombank_LMS_Bao_cao_gioi_thieu_va_dac_ta_he_thong.docx'
RED = 'C8102E'
INK = '20242B'
MID = '586271'
PALE = 'F4F6F8'
LINE = 'D9DEE5'


def make_architecture(path):
    W, H = 1600, 900
    im = Image.new('RGB', (W, H), '#F4F6F8')
    d = ImageDraw.Draw(im)
    font_path = 'C:/Windows/Fonts/arial.ttf'
    bold_path = 'C:/Windows/Fonts/arialbd.ttf'
    try:
        f = ImageFont.truetype(font_path, 27)
        fb = ImageFont.truetype(bold_path, 32)
        fs = ImageFont.truetype(font_path, 22)
    except OSError:
        f = fb = fs = ImageFont.load_default()
    d.rounded_rectangle((35, 35, W-35, H-35), radius=24, fill='white', outline='#D9DEE5', width=3)
    d.text((75, 62), 'KIẾN TRÚC TỔNG QUAN · TECHCOMBANK LMS', fill='#20242B', font=fb)

    def box(xy, title, lines, fill='#FFFFFF', outline='#C8102E'):
        d.rounded_rectangle(xy, radius=20, fill=fill, outline=outline, width=3)
        x1,y1,x2,y2 = xy
        d.text((x1+22,y1+20), title, font=fb, fill='#C8102E')
        yy=y1+68
        for line in lines:
            d.text((x1+24,yy), line, font=f, fill='#303844')
            yy += 42
    def arrow(a,b,label=None):
        d.line((a,b), fill='#606B78', width=5)
        import math
        ang=math.atan2(b[1]-a[1],b[0]-a[0]); L=18
        p1=(b[0]-L*math.cos(ang-0.5),b[1]-L*math.sin(ang-0.5))
        p2=(b[0]-L*math.cos(ang+0.5),b[1]-L*math.sin(ang+0.5))
        d.polygon([b,p1,p2],fill='#606B78')
        if label:
            mx=(a[0]+b[0])//2; my=(a[1]+b[1])//2
            d.rounded_rectangle((mx-65,my-17,mx+65,my+18),radius=8,fill='white')
            d.text((mx-58,my-14),label,font=fs,fill='#586271')

    box((75,190,385,405),'Người dùng',['Quản trị viên','Nhân viên'],fill='#FFF5F6')
    box((480,165,830,430),'Trình duyệt',['Giao diện thích ứng','Blade · Tailwind CSS','Livewire 3'])
    box((930,130,1330,515),'Laravel 11',['Đăng nhập · phân quyền','Quản trị nhân sự','Tài liệu · khóa học · quiz','Tiến độ · hỗ trợ · AI','Dịch vụ nghiệp vụ'])
    box((130,590,540,780),'MySQL 8',['Hồ sơ · khóa học','Tài liệu · tiến độ','Nhật ký · quyền truy cập'],fill='#F5F8FC',outline='#73849A')
    box((690,590,1035,780),'Lưu trữ tệp',['Kho tệp riêng','PDF · Excel · ảnh'],fill='#F5F8FC',outline='#73849A')
    box((1180,590,1485,780),'Dịch vụ ngoài',['Groq API (tùy cấu hình)','SMTP / hạ tầng triển khai'],fill='#F5F8FC',outline='#73849A')
    arrow((385,295),(480,295),'HTTPS')
    arrow((830,295),(930,295),'HTTP')
    arrow((1050,515),(440,590),'PDO')
    arrow((1110,515),(870,590),'File I/O')
    arrow((1230,515),(1320,590),'API')
    im.save(path)


def shade(cell, color):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement('w:shd'); shd.set(qn('w:fill'), color); tcPr.append(shd)


def set_cell_margins(cell, top=100, start=120, bottom=100, end=120):
    tc = cell._tc; tcPr = tc.get_or_add_tcPr()
    mar = tcPr.first_child_found_in('w:tcMar')
    if mar is None:
        mar = OxmlElement('w:tcMar'); tcPr.append(mar)
    for m,v in [('top',top),('start',start),('bottom',bottom),('end',end)]:
        node = mar.find(qn('w:'+m))
        if node is None: node = OxmlElement('w:'+m); mar.append(node)
        node.set(qn('w:w'),str(v)); node.set(qn('w:type'),'dxa')


def set_repeat_table_header(row):
    trPr = row._tr.get_or_add_trPr()
    tblHeader = OxmlElement('w:tblHeader'); tblHeader.set(qn('w:val'),'true'); trPr.append(tblHeader)


def add_page_field(paragraph):
    run = paragraph.add_run()
    begin = OxmlElement('w:fldChar'); begin.set(qn('w:fldCharType'),'begin')
    instr = OxmlElement('w:instrText'); instr.set(qn('xml:space'),'preserve'); instr.text=' PAGE '
    separate = OxmlElement('w:fldChar'); separate.set(qn('w:fldCharType'),'separate')
    text = OxmlElement('w:t'); text.text='1'
    end = OxmlElement('w:fldChar'); end.set(qn('w:fldCharType'),'end')
    for e in (begin,instr,separate,text,end): run._r.append(e)


def setup(doc):
    sec = doc.sections[0]
    sec.top_margin=Inches(.68); sec.bottom_margin=Inches(.65)
    sec.left_margin=Inches(.72); sec.right_margin=Inches(.72)
    sec.header_distance=Inches(.32); sec.footer_distance=Inches(.32)
    styles=doc.styles
    normal=styles['Normal']; normal.font.name='Arial'; normal.font.size=Pt(10); normal.font.color.rgb=RGBColor.from_string(INK)
    normal._element.rPr.rFonts.set(qn('w:eastAsia'),'Arial')
    normal.paragraph_format.space_after=Pt(5); normal.paragraph_format.line_spacing=1.12
    for style_name,size,color in [('Title',30,RED),('Heading 1',19,RED),('Heading 2',13,INK),('Heading 3',11,INK)]:
        st=styles[style_name]; st.font.name='Arial'; st.font.size=Pt(size); st.font.bold=True; st.font.color.rgb=RGBColor.from_string(color)
        st._element.rPr.rFonts.set(qn('w:eastAsia'),'Arial')
        st.paragraph_format.space_before=Pt(10 if style_name!='Title' else 0); st.paragraph_format.space_after=Pt(6)
    header=sec.header.paragraphs[0]
    header.alignment=WD_ALIGN_PARAGRAPH.RIGHT
    rr=header.add_run('TECHCOMBANK LMS  ·  BÁO CÁO HỆ THỐNG'); rr.bold=True; rr.font.name='Arial'; rr.font.size=Pt(8); rr.font.color.rgb=RGBColor.from_string(MID)
    footer=sec.footer.paragraphs[0]
    footer.alignment=WD_ALIGN_PARAGRAPH.CENTER
    r=footer.add_run('Tài liệu khảo sát nội bộ  |  07/10/2026  |  Trang '); r.font.size=Pt(8); r.font.color.rgb=RGBColor.from_string(MID)
    add_page_field(footer)


def para(doc,text='',style=None,bold_prefix=None):
    p=doc.add_paragraph(style=style)
    if bold_prefix and text.startswith(bold_prefix):
        p.add_run(bold_prefix).bold=True; p.add_run(text[len(bold_prefix):])
    else: p.add_run(text)
    return p


def bullet(doc,text,level=0):
    p=doc.add_paragraph(style='List Bullet' if level==0 else 'List Bullet 2'); p.paragraph_format.space_after=Pt(2); p.add_run(text); return p


def numbered(doc,text):
    p=doc.add_paragraph(style='List Number'); p.paragraph_format.space_after=Pt(2); p.add_run(text); return p


def table(doc,headers,rows,widths=None,font_size=9):
    t=doc.add_table(rows=1, cols=len(headers)); t.alignment=WD_TABLE_ALIGNMENT.CENTER; t.style='Table Grid'
    t.autofit=False
    if widths is None:
        widths=[6.6/len(headers)]*len(headers)
    if widths:
        for row in t.rows:
            for c,w in zip(row.cells,widths): c.width=Inches(w)
    head=t.rows[0]; set_repeat_table_header(head)
    for i,h in enumerate(headers):
        c=head.cells[i]; shade(c,RED); set_cell_margins(c); c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
        p=c.paragraphs[0]; p.paragraph_format.space_after=Pt(0); r=p.add_run(h); r.bold=True; r.font.size=Pt(font_size); r.font.color.rgb=RGBColor(255,255,255)
    for ri,row in enumerate(rows):
        cells=t.add_row().cells
        if widths:
            for c,w in zip(cells,widths): c.width=Inches(w)
        for i,val in enumerate(row):
            c=cells[i]; set_cell_margins(c); c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
            if ri%2: shade(c,'F7F8FA')
            p=c.paragraphs[0]; p.paragraph_format.space_after=Pt(0); p.paragraph_format.line_spacing=1.0
            r=p.add_run(str(val)); r.font.size=Pt(font_size); r.font.name='Arial'
    doc.add_paragraph().paragraph_format.space_after=Pt(1)
    return t


def caption(doc,text):
    p=doc.add_paragraph(); p.alignment=WD_ALIGN_PARAGRAPH.CENTER; p.paragraph_format.space_after=Pt(7)
    r=p.add_run(text); r.italic=True; r.font.size=Pt(8); r.font.color.rgb=RGBColor.from_string(MID)


def add_image(doc,path,width,caption_text=None):
    p=doc.add_paragraph(); p.alignment=WD_ALIGN_PARAGRAPH.CENTER; p.paragraph_format.space_after=Pt(2)
    p.add_run().add_picture(str(path),width=Inches(width))
    if caption_text: caption(doc,caption_text)


def callout(doc,title,text,color='FFF5F6'):
    t=doc.add_table(rows=1,cols=1); t.alignment=WD_TABLE_ALIGNMENT.CENTER
    c=t.cell(0,0); shade(c,color); set_cell_margins(c,150,190,150,190)
    p=c.paragraphs[0]; p.paragraph_format.space_after=Pt(3); r=p.add_run(title); r.bold=True; r.font.color.rgb=RGBColor.from_string(RED)
    p=c.add_paragraph(text); p.paragraph_format.space_after=Pt(0)
    doc.add_paragraph().paragraph_format.space_after=Pt(1)


def inline_markdown(p, text):
    text=re.sub(r'</?cite[^>]*>', '', text)
    pattern=re.compile(r'(\*\*.+?\*\*|`[^`]+`|\*[^*]+\*)')
    pos=0
    for m in pattern.finditer(text):
        if m.start()>pos: p.add_run(text[pos:m.start()])
        token=m.group(0)
        if token.startswith('**'):
            p.add_run(token[2:-2]).bold=True
        elif token.startswith('`'):
            r=p.add_run(token[1:-1]); r.font.name='Consolas'; r.font.size=Pt(8); r.font.color.rgb=RGBColor.from_string(RED)
        else:
            p.add_run(token[1:-1]).italic=True
        pos=m.end()
    if pos<len(text): p.add_run(text[pos:])


def markdown_table_cells(line):
    return [c.strip() for c in line.strip().strip('|').split('|')]


def append_markdown_source(doc,path):
    """Append the complete existing functional-spec draft while preserving its hierarchy and tables."""
    raw=Path(path).read_text(encoding='utf-8-sig')
    lines=raw.splitlines(); i=0; in_code=False; code=[]
    while i<len(lines):
        line=lines[i]
        if line.strip().startswith('```'):
            if not in_code:
                in_code=True; code=[]
            else:
                p=doc.add_paragraph(); p.paragraph_format.left_indent=Inches(.16); p.paragraph_format.space_after=Pt(6)
                pPr=p._p.get_or_add_pPr(); sh=OxmlElement('w:shd'); sh.set(qn('w:fill'),'F2F4F6'); pPr.append(sh)
                for n,code_line in enumerate(code):
                    r=p.add_run(code_line if code_line else ' '); r.font.name='Consolas'; r.font.size=Pt(7)
                    if n<len(code)-1: r.add_break()
                in_code=False
            i+=1; continue
        if in_code:
            code.append(line); i+=1; continue
        if not line.strip() or line.strip() in ('---','***'):
            i+=1; continue
        # Markdown table: keep original columns and row values.
        if line.strip().startswith('|') and i+1<len(lines) and re.match(r'^\s*\|?\s*:?-{2,}', lines[i+1]):
            rows=[markdown_table_cells(line)]; i+=2
            while i<len(lines) and lines[i].strip().startswith('|'):
                rows.append(markdown_table_cells(lines[i])); i+=1
            if rows:
                col_count=max(len(r) for r in rows); headers=(rows[0]+['']*col_count)[:col_count]
                body=[(r+['']*col_count)[:col_count] for r in rows[1:]]
                t=table(doc,headers,body,font_size=7.4)
                for row in t.rows:
                    for c in row.cells:
                        for p in c.paragraphs:
                            for run in p.runs:
                                run.font.size=Pt(7.4)
            continue
        h=re.match(r'^(#{1,6})\s+(.*)$',line)
        if h:
            level=max(2,min(3,len(h.group(1))))
            p=doc.add_paragraph(style=f'Heading {level}'); inline_markdown(p,h.group(2).strip())
            i+=1; continue
        if line.lstrip().startswith('>'):
            text=line.lstrip()[1:].strip()
            p=doc.add_paragraph(); p.paragraph_format.left_indent=Inches(.18); p.paragraph_format.right_indent=Inches(.12)
            pPr=p._p.get_or_add_pPr(); sh=OxmlElement('w:shd'); sh.set(qn('w:fill'),'FFF5F6'); pPr.append(sh)
            r=p.add_run(text); r.italic=True; r.font.size=Pt(9); r.font.color.rgb=RGBColor.from_string(MID)
            i+=1; continue
        b=re.match(r'^(\s*)[-*]\s+(.*)$',line)
        n=re.match(r'^(\s*)\d+[.)]\s+(.*)$',line)
        if b or n:
            m=b or n; level=1 if len(m.group(1))>=2 else 0
            p=doc.add_paragraph(style=('List Bullet' if b else 'List Number') if level==0 else ('List Bullet 2' if b else 'List Number 2'))
            p.paragraph_format.space_after=Pt(2); inline_markdown(p,m.group(2)); i+=1; continue
        # Preserve inline emphasis and references, without exposing raw HTML citation markers.
        p=doc.add_paragraph(); inline_markdown(p,line)
        i+=1


def make_contact_sheet(entries,path,title,columns=3):
    tile_w,tile_h,label_h=430,300,34
    rows=(len(entries)+columns-1)//columns
    sheet=Image.new('RGB',(columns*tile_w,rows*(tile_h+label_h)),'#E9EDF2')
    d=ImageDraw.Draw(sheet)
    try:
        font=ImageFont.truetype('C:/Windows/Fonts/arialbd.ttf',18)
    except OSError:
        font=ImageFont.load_default()
    for idx,(label,img_path) in enumerate(entries):
        x=(idx%columns)*tile_w; y=(idx//columns)*(tile_h+label_h)
        d.rectangle((x,y,x+tile_w-3,y+label_h-2),fill='#C8102E')
        d.text((x+10,y+7),label[:50],fill='white',font=font)
        src=Image.open(img_path).convert('RGB')
        src.thumbnail((tile_w-10,tile_h-10),Image.Resampling.LANCZOS)
        sx=x+(tile_w-src.width)//2; sy=y+label_h+(tile_h-src.height)//2
        sheet.paste(src,(sx,sy))
    sheet.save(path)


def main():
    # Evidence images included in the document are cropped from the original viewport captures.
    mobile_src=Image.open(SHOTS/'admin-dashboard-mobile-viewport.png').convert('RGB')
    mobile_src.crop((0,0,390,570)).save(ASSETS/'admin-mobile-top.png',quality=92)
    employee_src=Image.open(SHOTS/'employee-events-desktop.png').convert('RGB')
    employee_src.crop((0,0,1440,920)).save(ASSETS/'employee-desktop-top.png',quality=92)
    arch=ASSETS/'architecture.png'; make_architecture(arch)
    admin_names=['dashboard','accounts','employees','employee-detail','contracts','roles','files','documents','courses','quizzes','announcements','access-rules','security','settings']
    user_names=['events','onboarding','courses','progress','documents','support','profile','course-aml','quiz-aml']
    admin_sheet=ASSETS/'admin-screen-index.jpg'
    user_sheet=ASSETS/'user-screen-index.jpg'
    make_contact_sheet([(n.replace('-',' ').title(),SCREENSHOTS/f'admin-{n}.png') for n in admin_names],admin_sheet,'ADMIN')
    make_contact_sheet([(n.replace('-',' ').title(),SCREENSHOTS/f'user-{n}.png') for n in user_names],user_sheet,'USER')

    doc=Document(); setup(doc)
    # Cover
    p=doc.add_paragraph(); p.paragraph_format.space_before=Pt(36); p.alignment=WD_ALIGN_PARAGRAPH.LEFT
    r=p.add_run('TECHCOMBANK'); r.bold=True; r.font.name='Arial'; r.font.size=Pt(15); r.font.color.rgb=RGBColor.from_string(RED)
    p=doc.add_paragraph(); p.paragraph_format.space_before=Pt(42); p.paragraph_format.space_after=Pt(4)
    r=p.add_run('HỆ THỐNG ĐÀO TẠO\nNHÂN VIÊN'); r.bold=True; r.font.size=Pt(31); r.font.color.rgb=RGBColor.from_string(INK)
    p=doc.add_paragraph(); p.paragraph_format.space_after=Pt(16)
    r=p.add_run('BÁO CÁO GIỚI THIỆU & ĐẶC TẢ HỆ THỐNG'); r.bold=True; r.font.size=Pt(16); r.font.color.rgb=RGBColor.from_string(RED)
    p=doc.add_paragraph(); p.paragraph_format.space_after=Pt(22)
    p.add_run('Mô tả hệ thống theo mã nguồn hiện tại, các phân hệ, luồng nghiệp vụ, nền tảng kỹ thuật và kết quả rà soát giao diện.').font.size=Pt(12)
    add_image(doc,SHOTS/'admin-dashboard-desktop-viewport.png',6.35,'Ảnh giao diện quản trị lấy từ ứng dụng đang chạy trên môi trường cục bộ.')
    table(doc,['Thông tin tài liệu','Giá trị'],[
        ('Ngày khảo sát','07/10/2026'),('Phiên bản tài liệu','2.0 · Bản mở rộng'),('Phạm vi','Đặc tả hiện trạng, danh mục route/màn hình, validation và rà soát giao diện'),('Nguồn bằng chứng','Mã nguồn workspace, tài liệu đặc tả nguồn và giao diện chạy cục bộ')
    ],[2.0,4.6],9)
    callout(doc,'Lưu ý phạm vi','Đây là tài liệu mô tả hiện trạng có thể quan sát từ source và bản chạy cục bộ. Các đề xuất sản phẩm hoặc phần phụ thuộc cấu hình bên ngoài được ghi riêng, không xem là đã triển khai nếu chưa có bằng chứng.')

    doc.add_page_break()
    doc.add_heading('Mục lục',0)
    for item in [
        'Tóm tắt điều hành','1. Giới thiệu dự án','2. Người dùng và phân quyền','3. Kiến trúc tổng thể','4. Đặc tả phân hệ và danh mục màn hình','5. Luồng nghiệp vụ chính','6. Mô hình dữ liệu hiện trạng','7. Công nghệ và vận hành','8. Bảo mật và kiểm soát truy cập','9. Rà soát giao diện theo từng khối','10. Giới hạn và đề xuất ưu tiên','11. Ma trận route và quyền','Phụ lục A. Hướng dẫn và môi trường','Phụ lục B. Đặc tả chức năng nguồn v0.5'
    ]: bullet(doc,item)

    doc.add_heading('Tóm tắt điều hành',1)
    para(doc,'Techcombank LMS là ứng dụng web nội bộ phục vụ quản trị nhân sự và tổ chức hoạt động đào tạo cho nhân viên. Cùng một codebase cung cấp hai khu vực đăng nhập: Quản trị (/quan-tri) và Học tập (/hoc-tap). Dữ liệu được tổ chức quanh nhân viên, tài liệu, khóa học, bài học, bài kiểm tra và tiến độ.')
    table(doc,['Hạng mục','Kết quả khảo sát'],[
        ('Chức năng chính','Quản lý tài khoản và hồ sơ; tài liệu; khóa học; kiểm tra; tiến độ; onboarding; hỗ trợ; quản lý quyền và theo dõi bảo mật.'),
        ('Nền tảng','Laravel 11.56.0, PHP 8.2.27, Livewire 3, Tailwind CSS 3, Vite 6, MySQL 8.'),
        ('Máy tính 1440 px','9 màn quản trị đại diện được kiểm tra; chiều rộng tài liệu bằng viewport, không phát hiện tràn ngang.'),
        ('Điện thoại 390 px','13/13 route quản trị được kiểm tra có chiều rộng tài liệu 399–576 px; phần làm việc bị ép hẹp hoặc cắt ngang.'),
        ('Khu vực nhân viên','7 route chính và 2 trang chi tiết khóa học đều vừa viewport 390 px trong phép đo hình học.'),
    ],[1.5,5.1],9)
    callout(doc,'Kết luận về giao diện','Lỗi đáp ứng mobile tập trung ở site Quản trị. Site Học tập đã vừa khung trong các trang được kiểm tra. Bộ CSS xử lý chuỗi dài hiện có giúp nội dung văn bản thường xuống dòng, nhưng chưa giải quyết chiều rộng sidebar quản trị trên màn hình nhỏ.')

    doc.add_heading('1. Giới thiệu dự án',1)
    para(doc,'Mục tiêu của hệ thống là tập trung hóa việc giao khóa học, nội dung hướng dẫn và bài kiểm tra; theo dõi quá trình học; đồng thời cung cấp công cụ quản trị nhân sự và kiểm soát tài liệu đào tạo nội bộ.')
    para(doc,'Đối tượng sử dụng gồm quản trị viên/nhân sự/đào tạo và nhân viên. Ứng dụng hỗ trợ giao khóa học theo điều kiện nhân sự, khóa onboarding, tài liệu được giới hạn theo quyền và trợ lý AI chỉ truy xuất nguồn mà nhân viên hiện tại được phép xem.')
    doc.add_heading('Trong phạm vi hiện trạng',2)
    for x in ['Hai khu vực giao diện dùng chung ứng dụng Laravel và cơ sở dữ liệu.','Quản lý vòng đời tài liệu, phiên bản và chính sách xem/tải.','Tổ chức khóa học theo bài đọc, video hoặc bài kiểm tra; ghi nhận trạng thái học.','Ghi nhận một số hoạt động đăng nhập, truy cập tệp và cảnh báo bảo mật.']:
        bullet(doc,x)
    doc.add_heading('Ngoài phạm vi xác nhận',2)
    para(doc,'Báo cáo không xác nhận cấu hình sản xuất, nhà cung cấp email, thông tin API thật, thời gian đáp ứng tải lớn hoặc quy trình vận hành của môi trường triển khai. Cấu hình thực tế cần được đối chiếu với môi trường đích trước khi phát hành.')

    doc.add_heading('2. Người dùng và phân quyền',1)
    table(doc,['Vai trò hiện có','Quyền truy cập khái quát','Khu vực'],[
        ('Quản trị viên','Được cấp toàn bộ permission nghiệp vụ hiện tại; truy cập dữ liệu nhân sự, tài liệu, khóa học, bảo mật và cấu hình.','/quan-tri'),
        ('Nhân viên','Quyền learning.access; xem/học nội dung được giao, tài liệu được cấp quyền, tiến độ cá nhân, thông tin cá nhân và hỗ trợ.','/hoc-tap'),
    ],[1.35,3.65,1.6],9)
    para(doc,'Mã nguồn khai báo các permission theo nhóm accounts, employees, contracts, roles, settings, documents, courses, quizzes, announcements, security, reports, support và learning. Vai trò được cấp trong triển khai hiện tại gồm ADMIN và EMPLOYEE; quyền quản trị viên được cho phép qua cơ chế gate và đồng thời gán trong ma trận quyền để hiển thị.')
    callout(doc,'Lưu ý quản trị','Quản trị viên có phạm vi rộng, có thể xem hồ sơ và hợp đồng. Việc cấp vai trò này nên giới hạn theo trách nhiệm công việc và chính sách bảo vệ dữ liệu nội bộ.')

    doc.add_heading('3. Kiến trúc tổng thể',1)
    para(doc,'Kiến trúc triển khai là ứng dụng web phía máy chủ. Blade dựng trang, Livewire xử lý tương tác theo request, dịch vụ nghiệp vụ tập trung ở lớp Service, Eloquent truy cập MySQL. Tệp được lưu qua kho riêng; luồng AI dùng dịch vụ ngoài khi có cấu hình hợp lệ.')
    add_image(doc,arch,6.45,'Sơ đồ kiến trúc khái quát, tổng hợp từ cấu trúc route, lớp Livewire, Service, Model và cấu hình dự án.')
    doc.add_heading('Các lớp chính',2)
    table(doc,['Lớp','Trách nhiệm','Ví dụ trong dự án'],[
        ('Giao diện','Render layout theo vai trò; điều hướng; form; bảng và thành phần tương tác.','resources/views, Livewire components'),
        ('Nghiệp vụ','Quản lý gán khóa, tiến độ, kiểm tra, truy cập tài liệu, AI và lưu tệp.','app/Services'),
        ('Điều khiển truy cập','Xác thực, middleware quản trị, permission/gate và kiểm tra quyền trên thao tác dữ liệu.','routes/web.php, middleware, policies/gates'),
        ('Dữ liệu','Lưu nhân sự, tổ chức, khóa học, bài học, tài liệu, tiến độ, log và cấu hình.','app/Models, database/migrations'),
        ('Tích hợp','API AI có cấu hình khóa; xuất/import Excel; PDF và dấu watermark.','Groq HTTP client, Laravel Excel, FPDF/FPDI'),
    ],[1.3,2.45,2.85],8.5)

    doc.add_heading('4. Đặc tả phân hệ',1)
    table(doc,['Phân hệ','Chức năng quan sát từ source'],[
        ('Tổng quan quản trị','Thẻ việc cần xử lý, hoạt động học, biểu đồ, tiến độ và danh sách cần nhắc hạn.'),
        ('Tài khoản & nhân sự','Quản lý tài khoản, hồ sơ nhân viên, cấu trúc phòng ban/chức danh/cấp bậc, lịch sử điều chuyển và nhập dữ liệu Excel.'),
        ('Hợp đồng','Quản lý loại, thời hạn, file liên quan và nhắc hợp đồng sắp hết hạn.'),
        ('Kho tài nguyên','Tổ chức thư mục và file; hỗ trợ khôi phục; ngăn xóa vĩnh viễn khi file còn được tham chiếu.'),
        ('Thư viện tài liệu','Quản lý tài liệu, phiên bản, trạng thái xuất bản, cho phép tải bản gốc, watermark và rule truy cập.'),
        ('Khóa học','Tạo khóa và chương/bài; bài đọc, video, quiz; sắp xếp thứ tự; giao theo nhóm nhân sự hoặc onboarding.'),
        ('Bài kiểm tra','Câu hỏi một/nhiều lựa chọn, ngưỡng đạt, giới hạn thời gian/số lần, xáo trộn và nhập câu hỏi từ Excel.'),
        ('Học tập nhân viên','Sự kiện, onboarding, danh sách khóa, học bài, làm quiz, theo dõi tiến độ, tài liệu, hồ sơ và yêu cầu hỗ trợ.'),
        ('Trợ lý AI','Truy xuất nội dung đào tạo theo quyền nhân viên, phản hồi có nguồn; không trả lời thay đáp án quiz theo chỉ dẫn trong service.'),
        ('Bảo mật & cấu hình','Ma trận quyền, rule truy cập tài liệu, cảnh báo an ninh, phiên/nhật ký đăng nhập và một số thiết lập hệ thống.'),
    ],[1.55,5.05],8.5)

    doc.add_heading('4.1. Danh mục màn hình quản trị',2)
    table(doc,['Màn hình / route','Các khối trên màn hình','Dữ liệu và thao tác chính'],[
        ('Tổng quan · /quan-tri','Bộ chọn kỳ 7/30/90 ngày; thẻ khóa quá hạn, hợp đồng sắp hết hạn, cảnh báo bảo mật, phiếu hỗ trợ; biểu đồ hoàn thành; phân bố trạng thái; tiến độ phòng ban; khóa cần can thiệp; nhân viên cần nhắc; hợp đồng sắp hết hạn.','Đọc dữ liệu tổng hợp theo khoảng thời gian; liên kết đến hồ sơ, hợp đồng và bảo mật.'),
        ('Tài khoản · /quan-tri/tai-khoan','Tìm kiếm; lọc trạng thái/vai trò; bảng nhân viên, mã NV, phòng ban, vai trò, lần đăng nhập, trạng thái; phân trang; hộp thoại tài khoản.','Họ tên, email, mã nhân viên, mật khẩu, vai trò, trạng thái, buộc đổi mật khẩu. Tạo/sửa/khóa/mở khóa. accounts.view / accounts.manage.'),
        ('Nhân sự · /quan-tri/nhan-su','Tìm kiếm; lọc phòng ban/trạng thái; nhập Excel; bảng hồ sơ, tiến độ; lựa chọn nhiều nhân viên; lịch sử điều chuyển; hộp thoại hồ sơ/giao khóa.','Họ tên, mã nhân viên duy nhất, email/điện thoại, giới tính, phòng ban, chức danh, cấp bậc, quản lý, trạng thái việc làm, ngày vào làm. employees.view / employees.manage.'),
        ('Chi tiết nhân sự · /quan-tri/nhan-su/{id}','Thông tin cá nhân; vị trí công tác; dòng thời gian; tab tổng quan/khóa học/chi tiết bài học/kết quả kiểm tra/hợp đồng/đăng nhập; hộp thoại giao khóa.','Chọn khóa học và hạn hoàn thành; thời hạn 1–365 ngày nếu nhập. employees.view / employees.manage.'),
        ('Hợp đồng · /quan-tri/hop-dong','Tìm kiếm; lọc trạng thái; chỉ xem sắp hết hạn; đồng bộ trạng thái; bảng số hợp đồng, nhân viên, loại, hiệu lực, hết hạn, trạng thái; hộp thoại.','Nhân viên, loại hợp đồng, số hợp đồng duy nhất, ngày hiệu lực/hết hạn, trạng thái, cảnh báo ngày, ghi chú, file. contracts.view / contracts.manage.'),
        ('Phân quyền · /quan-tri/phan-quyen','Ma trận permission theo nhóm tài nguyên/hành động; lựa chọn vai trò; lưu thay đổi/trạng thái chưa lưu.','Xem/quản lý/tải/xuất bản hoặc cấu hình theo permission khai báo. roles.manage.'),
        ('Kho tài nguyên · /quan-tri/kho-tai-nguyen','Cây thư mục; breadcrumbs; tìm kiếm/lọc loại; danh sách file; upload; thư mục mới; đổi tên/chuyển thư mục/thùng rác/khôi phục.','Tên, mô tả thư mục; file tối đa 2 MB; tên đích khi đổi tên; target khi di chuyển. documents.view / quyền thao tác file.'),
        ('Thư viện tài liệu · /quan-tri/tai-lieu','Tìm kiếm/lọc loại/trạng thái/danh mục; bảng tài liệu, loại, danh mục, bảo mật, phiên bản, xuất bản; hộp thoại sửa/thêm; danh sách phiên bản/rollback.','Tiêu đề, mô tả, loại file/video, danh mục, đơn vị sở hữu, độ mật, cho tải bản gốc, watermark, URL video/file upload, thời lượng, ghi chú phiên bản. documents.view/manage/publish.'),
        ('Khóa học · /quan-tri/khoa-hoc','Tìm/lọc trạng thái; danh sách khóa; tiến độ; kiểu học; quản lý bài; hộp thoại khóa, bài học, quy tắc giao.','Mã, tiêu đề, mô tả, đơn vị sở hữu, thời hạn, điểm đạt; nội dung bài text/document/video/quiz; thời lượng; tuần tự/onboarding/chứng nhận; rule phòng ban/chức danh/cấp bậc/trạng thái/hạn.'),
        ('Bài kiểm tra · /quan-tri/bai-kiem-tra','Tìm kiếm; bảng quiz/khóa/số câu/điểm đạt/thời gian/trạng thái; ngân hàng câu hỏi; cấu hình đề; hộp thoại nhập Excel.','Tiêu đề/mô tả/khóa; số câu mỗi lượt; thời lượng 1–600 phút; điểm đạt 0–100; lượt làm tối đa 1–20; câu hỏi tối đa 2000 ký tự, tối thiểu 2 lựa chọn; file xlsx/xls/csv.'),
        ('Sự kiện · /quan-tri/su-kien','Bảng sự kiện/phạm vi/loại/thời gian/địa điểm/trạng thái; thêm/sửa/xóa/gỡ khỏi site người dùng.','Tiêu đề, nội dung, loại, phạm vi toàn công ty/cá nhân, nhân viên nhận, bắt đầu/kết thúc, địa điểm; kết thúc không trước bắt đầu.'),
        ('Phân quyền tài liệu · /quan-tri/phan-quyen-tai-lieu','Bộ lọc rule; bảng đối tượng/điều kiện/quyền/ưu tiên/hiệu lực; thử quyền; hộp thoại tạo/sửa.','Rule theo tài liệu/danh mục; phòng ban/chức danh/cấp bậc/trạng thái việc làm; bao gồm phòng con; xem/tải/in; allow/deny; ưu tiên; thử bằng nhân viên và tài liệu.'),
        ('Trung tâm bảo mật · /quan-tri/bao-mat','Tab cảnh báo, nhật ký tài liệu, đăng nhập, phiên thiết bị; lọc thời gian/trạng thái/hành động; tìm kiếm; xem chi tiết; ghi nhận/đóng cảnh báo; quét.','Đọc/điều tra nhật ký và quản lý trạng thái cảnh báo; security.view.'),
        ('Cấu hình · /quan-tri/cau-hinh','Tab phòng ban/chức danh/cấp bậc/loại hợp đồng/danh mục tài liệu; bảng danh mục; tham số hệ thống; tải hướng dẫn hai đối tượng.','Mã duy nhất, tên, quan hệ cha; cấp bậc 0–100; thời hạn hợp đồng 1–120 tháng; tên hiển thị/tên công ty; thời hạn báo trước 1–365 ngày; số thiết bị 1–10.'),
    ],[1.35,2.8,2.8],7.4)

    doc.add_heading('4.2. Danh mục màn hình nhân viên',2)
    table(doc,['Màn hình / route','Các khối trên màn hình','Dữ liệu / thao tác'],[
        ('Sự kiện · /hoc-tap/su-kien','Lời chào; bộ lọc tất cả/riêng tôi/sắp diễn ra; danh sách thẻ sự kiện có phạm vi, loại, thời gian, địa điểm, trạng thái.','Xem thông báo theo đối tượng, ưu tiên sự kiện cá nhân.'),
        ('Chào mừng · /hoc-tap/chao-mung','Giới thiệu công ty; lộ trình onboarding; quy trình cần biết; giá trị cốt lõi; đầu mối hỗ trợ.','Thông tin theo cấu hình; lối vào khóa onboarding được gán.'),
        ('Khóa học · /hoc-tap/khoa-hoc','Tóm tắt tiến độ và số khóa/chứng nhận; bộ lọc tất cả/bắt buộc/đang học/đã xong; thẻ khóa học; nút bắt đầu/học tiếp/xem lại; nút mở AI.','Tiến độ bài, bài còn lại, hạn và tính bắt buộc; chỉ hiện khóa được giao.'),
        ('Chi tiết khóa · /hoc-tap/khoa-hoc/{slug}','Tiến độ; danh mục bài; trạng thái mở khóa; vùng nội dung bài đọc/video/tài liệu; trước/tiếp theo; hoàn thành.','Học tuần tự; ghi thời gian xem; xác nhận hoàn thành; video nhúng, bài đọc HTML và bài tài liệu.'),
        ('Bài kiểm tra · /hoc-tap/khoa-hoc/{slug}/kiem-tra/{lesson}','Giới thiệu quiz/lịch sử; bắt đầu; đồng hồ; tiến trình/câu hỏi/đáp án; xác nhận nộp; điểm và giải thích.','Câu một hoặc nhiều đáp án; thời gian; số lần làm; xáo trộn; tự nộp khi hết giờ; điểm đạt theo cấu hình.'),
        ('Tiến độ · /hoc-tap/tien-do','Khối cần hoàn thành; danh sách khóa; kết quả bài kiểm tra; chứng nhận.','Xem tiến độ và kết quả cá nhân; tải chứng nhận nếu có.'),
        ('Tài liệu · /hoc-tap/tai-lieu','Tìm kiếm; lọc danh mục/loại; thẻ tài liệu; xem trực tuyến/tải khi có quyền; modal xem.','Quyền truy cập được kiểm tra phía server; watermark và log tùy tài liệu.'),
        ('Hỗ trợ · /hoc-tap/ho-tro','Liên kết hướng dẫn; form gửi yêu cầu; danh sách yêu cầu; đầu mối liên hệ.','Chủ đề/tiêu đề/nội dung/độ ưu tiên; nội dung tối đa 5000 ký tự; độ ưu tiên low/normal/high.'),
        ('Cá nhân · /hoc-tap/ca-nhan','Thông tin hồ sơ; đổi ảnh; đổi mật khẩu; thiết bị hiện đăng nhập; đăng nhập gần đây.','Ảnh JPG/JPEG/PNG/WEBP tối đa 2 MB; mật khẩu mới tối thiểu 8 ký tự, khác mật khẩu cũ và xác nhận khớp; thu hồi phiên.'),
        ('Trợ lý AI · popup tại các trang học','Khung chat; lời nhắc/gợi ý; hội thoại; nguồn tham khảo; trạng thái lỗi/cấu hình.','Câu hỏi gửi đến nguồn đào tạo mà nhân viên được phép xem; không tiết lộ câu trả lời quiz.'),
    ],[1.45,2.7,2.8],7.7)

    doc.add_heading('4.3. Trường hợp dữ liệu và trạng thái giao diện',2)
    table(doc,['Trạng thái','Hành vi'],[
        ('Nháp / đã xuất bản','Tài liệu và khóa học chỉ được đưa tới nhân viên sau khi xuất bản; bản nháp vẫn được quản trị sửa.'),
        ('Chưa bắt đầu / đang học / hoàn thành / quá hạn','Thẻ khóa học và dashboard thể hiện tiến độ; khóa quá hạn được ưu tiên nhắc.'),
        ('Bài khóa tuần tự','Bài sau bị khóa cho đến khi bài trước đáp ứng điều kiện hoàn thành.'),
        ('Có quyền / không có quyền','Trang tài liệu lọc nội dung; controller stream và download kiểm tra quyền trước khi trả file.'),
        ('Cấu hình thiếu / ngoại dịch vụ lỗi','Trợ lý AI báo chưa cấu hình hoặc bận; danh sách file xử lý file không còn tồn tại bằng 404.'),
        ('Lỗi validation','Livewire trả lỗi trường ngay trong biểu mẫu; dữ liệu không hợp lệ không được lưu.'),
    ],[1.7,4.9],8.5)

    doc.add_heading('5. Luồng nghiệp vụ chính',1)
    doc.add_heading('5.1. Giao và hoàn thành khóa học',2)
    for x in ['Quản trị viên tạo khóa, bài học và điều kiện hoàn thành.','Quản trị viên xuất bản và thiết lập đối tượng nhận khóa theo phòng ban, chức danh, cấp bậc hoặc điều kiện onboarding.','Nhân viên đăng nhập, mở khóa được giao và học theo trạng thái/mở khóa của bài.','Hệ thống ghi nhận tiến độ; bài kiểm tra chỉ hoàn thành khi đạt điều kiện của quiz.','Quản trị viên xem thống kê và danh sách cần nhắc hạn.']:
        numbered(doc,x)
    doc.add_heading('5.2. Xem hoặc tải tài liệu',2)
    para(doc,'Nhân viên yêu cầu mở tài liệu → hệ thống kiểm tra trạng thái xuất bản và rule truy cập → nếu được phép thì stream file hoặc tạo bản PDF có watermark tùy cấu hình → ghi log truy cập/tải. Cờ cho phép tải bản gốc là giới hạn cao nhất, rule riêng không được nới rộng cờ này.')
    doc.add_heading('5.3. Hỏi đáp với trợ lý AI',2)
    para(doc,'Câu hỏi → TrainingKnowledgeService tìm đoạn nội dung từ tài liệu/bài học được phép → AiAssistantService gửi ngữ cảnh đến API AI nếu đã cấu hình → trả lời kèm nguồn. Nếu không tìm thấy ngữ cảnh được phép, hệ thống trả lời rằng không tìm thấy trong nguồn được quyền xem.')
    doc.add_heading('5.4. Quản lý hồ sơ nhân viên',2)
    para(doc,'Quản trị viên cập nhật tài khoản và hồ sơ → dịch vụ nghiệp vụ đồng bộ một số thông tin điều kiện giao khóa → thay đổi vị trí/phòng ban có thể lưu lịch sử → tài khoản nhân viên truy cập khu vực học tập theo vai trò.')

    doc.add_heading('6. Mô hình dữ liệu khái quát',1)
    para(doc,'Các nhóm bảng chính suy ra từ migrations và Eloquent models hiện có:')
    table(doc,['Nhóm','Thực thể tiêu biểu','Quan hệ nghiệp vụ'],[
        ('Tổ chức & nhân sự','users, employees, departments, job_titles, job_grades, employee_assignment_histories','Tài khoản gắn hồ sơ; hồ sơ gắn phòng ban, chức danh, cấp bậc và lịch sử thay đổi.'),
        ('Đào tạo','courses, course_sections, lessons, course_assignment_rules, enrollments, lesson_progress, certificates','Khóa chứa bài/chương; điều kiện giao tạo enrollment; tiến độ liên kết enrollment và bài.'),
        ('Kiểm tra','quizzes, questions, question_options, quiz_attempts, quiz_attempt_answers','Quiz chứa câu hỏi và lựa chọn; mỗi lần làm lưu đáp án và kết quả.'),
        ('Tài liệu & tệp','documents, document_versions, document_categories, stored_files, file_folders','Tài liệu tham chiếu file và phiên bản; rule kiểm soát xem/tải.'),
        ('Truy cập & bảo mật','document_access_rules, document_access_logs, login_histories, device_sessions, security_alerts','Theo dõi hoạt động và thực thi quyền ở các luồng liên quan.'),
        ('Trao đổi & vận hành','announcements, support_requests, support_contacts, ai_conversations, ai_messages, settings','Thông báo, yêu cầu hỗ trợ, lịch sử chat và cấu hình.'),
    ],[1.35,2.7,2.55],8)
    para(doc,'Cơ sở dữ liệu MySQL cục bộ được kiểm tra có 53 bảng trong artisan db:show. Số bảng có thể thay đổi theo migrations; danh sách đầy đủ cần sinh lại từ database của môi trường đích khi bàn giao triển khai.')

    doc.add_heading('7. Công nghệ và vận hành',1)
    table(doc,['Thành phần','Phiên bản / ghi chú'],[
        ('PHP','8.2.27 trên môi trường khảo sát; Laravel 11 yêu cầu PHP 8.2 trở lên.'),
        ('Laravel','11.56.0'),('Livewire','3.x'),('Cơ sở dữ liệu','MySQL 8.0.30'),('Giao diện','Tailwind CSS 3.4.x, Vite 6.x, Blade'),
        ('Quyền','spatie/laravel-permission 6.x và cơ chế gate của Laravel.'),('File/Excel/PDF','Laravel Excel; FPDF và FPDI cho PDF/watermark.'),('AI','Groq API qua cấu hình môi trường; tính năng cần API key hợp lệ để bật.'),
    ],[1.55,5.05],9)
    doc.add_heading('Lệnh chạy môi trường phát triển',2)
    for x in ['Cài dependency PHP bằng Composer chạy với PHP 8.2.','Tạo/cập nhật .env và cấu hình kết nối MySQL; không đưa khóa API vào mã nguồn.','Chạy migration/seed theo môi trường cho phép.','Cài dependency frontend bằng npm khi cần build CSS/JS.','Khởi chạy Laravel bằng artisan serve; cấu hình queue/storage theo nhu cầu tích hợp.']:
        numbered(doc,x)
    callout(doc,'Cảnh báo cấu hình cục bộ','Môi trường được khảo sát đang báo APP_ENV=local và Debug Mode=ENABLED. Giá trị này phù hợp với phát triển; cần tắt debug và rà soát secrets trước khi cấu hình môi trường thật.')

    doc.add_heading('8. Bảo mật và kiểm soát truy cập',1)
    for x in ['Route quản trị dùng auth và middleware kiểm tra vai trò; nhiều chức năng có middleware can:permission.','Tải tài liệu đi qua controller để kiểm tra quyền, ghi log; kho tệp không công khai trực tiếp qua URL public.','Rule xem/tải tài liệu có thể giới hạn theo điều kiện nhân sự; watermark có thể gắn dấu nhận dạng người xem.','Mật khẩu được xử lý qua Laravel auth/hash; có theo dõi lịch sử đăng nhập/thiết bị và cảnh báo bảo mật.','Trợ lý AI nhận ngữ cảnh đã lọc theo quyền của nhân viên; khóa API lấy từ cấu hình, không nên lưu trong source.','Các công tắc như giới hạn thiết bị, tải file và watermark cần được xác thực tại môi trường triển khai thực tế.']:
        bullet(doc,x)
    para(doc,'Báo cáo này không thực hiện kiểm thử xâm nhập hoặc đánh giá tuân thủ. Mô tả bảo mật dựa trên cấu trúc code và luồng truy cập đã rà soát, không thay thế đánh giá chuyên trách.')

    doc.add_page_break()
    doc.add_heading('9. Rà soát giao diện và tràn khung',1)
    para(doc,'Kiểm tra thực hiện trên bản chạy cục bộ tại 127.0.0.1:8123 bằng trình duyệt Chromium/Playwright, sau khi đăng nhập bằng tài khoản demo đã có trong MOI-TRUONG.md. Đo viewport 1440×1000 và 390×844; ghi nhận documentElement.scrollWidth, chiều rộng main và phần tử nhìn thấy vượt mép phải. Không chỉnh sửa giao diện trong lượt khảo sát này.')
    table(doc,['Khu vực / kích thước','Phạm vi đã mở','Kết quả'],[
        ('Quản trị · 1440 px','14 màn gồm các route sidebar, chi tiết hồ sơ nhân sự và các bảng quản trị.','14/14 trả HTTP 200; document width bằng 1440 px, không thấy phần tử vượt mép phải.'),
        ('Quản trị · 390 px','14 màn gồm dashboard, biểu mẫu danh sách, bảng, cấu hình và chi tiết hồ sơ.','14/14 có scrollWidth lớn hơn 390 px; kết quả quan sát 399–576 px. Vùng main chỉ rộng khoảng 122 px.'),
        ('Nhân viên · 390 px','7 màn chính: sự kiện, chào mừng, khóa học, tiến độ, tài liệu, hỗ trợ, cá nhân.','7/7 route trả HTTP 200; document width bằng 390 px. Hai tên khóa ở trang tiến độ được rút gọn bằng ellipsis.'),
        ('Chi tiết khóa · 390 px','Hai khóa học thực tế, màn bài đọc/video và trợ lý AI dạng popup.','2/2 route khóa trả HTTP 200; nội dung vừa viewport. Một tiêu đề dài bị ellipsis; popup AI rộng 358 px và nằm trong viewport.'),
    ],[1.45,3.2,1.95],8.5)
    doc.add_heading('9.1. Vấn đề xác nhận được',2)
    bullet(doc,'Site Quản trị giữ sidebar rộng cố định ở màn hình nhỏ. Ở viewport 390 px, vùng main chỉ còn khoảng 122 px; tiêu đề, thẻ thống kê, bộ chọn thời gian, form tìm kiếm và hàng thao tác đều bị ép vào vùng hẹp. Dashboard có document width 501 px.')
    bullet(doc,'Trong màn tài khoản/nhân sự/hợp đồng/tài liệu/khóa học, min-width của ô tìm kiếm và select còn lớn hơn phần main. Ở các màn bài kiểm tra, bảo mật, cấu hình và phân quyền tài liệu, bảng rộng hơn khung; một số bảng cuộn trong vùng riêng nhưng vùng xem quá hẹp để sử dụng thoải mái.')
    bullet(doc,'Chi tiết nhân viên cũng bị ảnh hưởng: link quay lại, nút giao khóa học, nhãn chức danh và dòng email vượt mép phải. 14/14 màn quản trị đã kiểm tra đều có document width lớn hơn 390 px.')
    bullet(doc,'Site Học tập vừa viewport ở các route được kiểm tra. Riêng trang tiến độ rút gọn hai tên khóa dài bằng ellipsis và tiêu đề một khóa chi tiết cũng rút gọn; đây là cắt chữ có chủ ý theo CSS, không phải chữ tràn ra ngoài khung.')
    bullet(doc,'Ở desktop, một số email, mô tả sự kiện và tóm tắt cảnh báo được rút gọn bằng class truncate; chúng không làm tràn toàn trang nhưng người đọc không thấy toàn bộ chuỗi ngay trong hàng. Các trường này nên có cách xem đầy đủ khi cần.')
    bullet(doc,'Màn bài video có thể hiện thông báo “Video unavailable” từ YouTube với nội dung demo hiện tại. Đây là nguồn video ngoài không khả dụng trong lần khảo sát, không phải lỗi tràn bố cục.')
    doc.add_heading('9.2. Ảnh bằng chứng',2)
    add_image(doc,SHOTS/'admin-dashboard-desktop-viewport.png',6.25,'Hình 1. Dashboard quản trị ở desktop 1440×1000; vùng làm việc hiển thị đầy đủ trong viewport.')
    add_image(doc,ASSETS/'admin-mobile-top.png',3.3,'Hình 2. Dashboard quản trị tại 390 px; sidebar vẫn chiếm bề ngang cố định, khiến vùng nội dung chỉ còn một dải hẹp.')
    add_image(doc,ASSETS/'employee-desktop-top.png',6.25,'Hình 3. Trang học tập của nhân viên ở desktop; menu và thẻ sự kiện/khóa học hiển thị trong bố cục người dùng.')
    doc.add_heading('9.3. Phạm vi của bộ xử lý chuỗi dài',2)
    para(doc,'public/css/layout-overflow-fix.css đã đặt quy tắc xuống dòng cho nội dung dài, mã, pre/code và ô bảng; cũng giới hạn chiều rộng media. Rà soát các khối cho thấy quy tắc này không thể bù cho cấu trúc quản trị mobile: sidebar cố định + vùng main chỉ 122 px + các form có min-width. Cần bố cục đáp ứng riêng cho quản trị. Một số class truncate trên desktop/mobile đang cắt ngắn nhãn; đây là hành vi riêng, khác với nội dung vẽ tràn khỏi khung.')

    doc.add_heading('10. Giới hạn và đề xuất ưu tiên',1)
    table(doc,['Ưu tiên','Đề xuất','Tiêu chí nghiệm thu gợi ý'],[
        ('P1','Cho phép thu gọn sidebar quản trị thành drawer hoặc điều hướng mobile; nội dung main phải nhận đúng chiều rộng khả dụng.','Tại 390 px, documentElement.scrollWidth bằng viewport; main không bị ép xuống dưới 240–280 px cho nội dung làm việc.'),
        ('P1','Chuyển hàng tiêu đề/nút hành động thành bố cục wrap hoặc xếp dọc ở breakpoint nhỏ; đặt min-width:0 cho đúng các flex child.','Không có nút, bộ lọc hoặc form bị cắt khỏi viewport.'),
        ('P2','Bao bảng rộng trong vùng cuộn ngang riêng và giữ các cột/nhãn quan trọng đọc được.','Trang không cuộn ngang toàn cục; bảng có vùng scroll nội bộ dễ nhận biết.'),
        ('P2','Rà lại bản build CSS production sau khi sửa và kiểm tra text dài, URL, mã tham chiếu, tên tệp và nội dung HTML.','Không có text tràn; media vừa vùng cha; bảng rộng vẫn xem được.'),
        ('P3','Chụp kiểm tra lại các route quản trị và nhân viên tại 390, 768 và 1440 px.','Lưu kết quả viewport, route, console và ảnh trước/sau.'),
    ],[.75,3.0,2.9],8.2)
    callout(doc,'Trạng thái công việc','Đây là báo cáo kiểm tra và tài liệu hóa. Không có file giao diện hoặc nghiệp vụ nào bị sửa trong lượt này; các thay đổi chưa commit đã có sẵn trong workspace vẫn được giữ nguyên.')

    doc.add_heading('11. Ma trận route, thành phần và quyền',1)
    para(doc,'Danh mục dưới đây đối chiếu các route ứng dụng trong routes/web.php với component/controller và middleware hiện có. Tài nguyên PDF/ảnh/file trả về trực tiếp nên không tương ứng với một màn Blade thông thường.')
    route_rows=[
        ('GET /','Điều hướng theo phiên đăng nhập','Closure · Auth::check','Khách → login; admin → dashboard; nhân viên → events.'),
        ('GET /dang-nhap','Biểu mẫu đăng nhập','Livewire Auth\\Login','guest'),
        ('POST /dang-xuat','Hủy phiên, quay lại login','Closure · Auth::logout','auth'),
        ('GET /quan-tri','Dashboard đào tạo','Admin\\Dashboard','auth + admin role'),
        ('GET /quan-tri/tai-khoan','Quản lý tài khoản','Admin\\AccountManager','auth + admin + accounts.view; ghi cần accounts.manage'),
        ('GET /quan-tri/nhan-su','Danh sách nhân viên','Admin\\EmployeeManager','auth + admin + employees.view; ghi cần employees.manage'),
        ('GET /quan-tri/nhan-su/{employee}','Hồ sơ nhân viên','Admin\\EmployeeDetail','auth + admin + employees.view'),
        ('GET /quan-tri/hop-dong','Danh sách hợp đồng','Admin\\ContractManager','auth + admin + contracts.view'),
        ('GET /quan-tri/phan-quyen','Ma trận permission','Admin\\PermissionMatrix','auth + admin + roles.manage'),
        ('GET /quan-tri/cau-hinh','Cấu hình tổ chức/hệ thống','Admin\\SettingsManager','auth + admin + settings.manage'),
        ('GET /quan-tri/huong-dan/{audience?}','Tải PDF hướng dẫn quản trị','UserGuideController','auth + admin'),
        ('GET /quan-tri/su-kien','Quản trị sự kiện/thông báo','Admin\\AnnouncementManager','auth + admin + announcements.view'),
        ('GET /quan-tri/tai-lieu','Thư viện tài liệu','Admin\\DocumentLibrary','auth + admin + documents.view'),
        ('GET /quan-tri/bai-kiem-tra','Biên soạn quiz','Admin\\QuizBuilder','auth + admin + quizzes.view'),
        ('GET /quan-tri/khoa-hoc','Biên soạn khóa học','Admin\\CourseBuilder','auth + admin + courses.view'),
        ('GET /quan-tri/kho-tai-nguyen','Quản lý tệp/thư mục','Admin\\FileManager','auth + admin + documents.view'),
        ('GET /quan-tri/phan-quyen-tai-lieu','Quản lý rule tài liệu','Admin\\DocumentAccessManager','auth + admin + documents.manage-access'),
        ('GET /quan-tri/bao-mat','Cảnh báo/nhật ký/phiên','Admin\\SecurityCenter','auth + admin + security.view'),
        ('GET /hoc-tap/su-kien','Bảng tin sự kiện','User\\EventFeed','auth'),
        ('GET /hoc-tap/chao-mung','Onboarding','User\\Onboarding','auth'),
        ('GET /hoc-tap/anh-dai-dien/{employee}','Ảnh đại diện (binary)','AvatarController','auth'),
        ('GET /hoc-tap/khoa-hoc','Khóa học được giao','User\\MyCourses','auth'),
        ('GET /hoc-tap/khoa-hoc/{course:slug}','Học nội dung khóa','User\\CoursePlayer','auth'),
        ('GET /hoc-tap/khoa-hoc/{course:slug}/kiem-tra/{lesson}','Làm bài kiểm tra','User\\QuizPlayer','auth'),
        ('GET /hoc-tap/tien-do','Tiến độ/chứng nhận','User\\LearningProgress','auth'),
        ('GET /hoc-tap/tai-lieu','Danh mục tài liệu có quyền','User\\DocumentBrowser','auth'),
        ('GET /hoc-tap/ho-tro','Gửi/theo dõi hỗ trợ','User\\SupportRequestForm','auth'),
        ('GET /hoc-tap/huong-dan-su-dung','Tải PDF hướng dẫn nhân viên','UserGuideController','auth'),
        ('GET /hoc-tap/ca-nhan','Hồ sơ/đổi mật khẩu/thiết bị','User\\Profile','auth'),
        ('GET /hoc-tap/tai-lieu/{document}/xem','Stream tài liệu đã kiểm quyền','DocumentFileController@stream','auth + quyền xem + log'),
        ('GET /hoc-tap/tai-lieu/{document}/tai-xuong','Tải file đã kiểm quyền','DocumentFileController@download','auth + quyền tải + log'),
    ]
    table(doc,['HTTP / route','Mục đích','Component/controller','Middleware / quyền'],route_rows,[2.15,1.5,1.45,1.5],7.2)
    doc.add_heading('Phụ lục B. Môi trường phát triển',1)
    para(doc,'Các số liệu phiên bản được lấy từ composer.json, package.json, artisan about và artisan db:show ở môi trường local tại thời điểm 07/10/2026. Dữ liệu demo có thể được làm mới hoặc khác nhau ở môi trường khác.')
    table(doc,['Hạng mục','Giá trị quan sát'],[
        ('Laravel / PHP','11.56.0 / 8.2.27'),('MySQL','8.0.30, database techcombank_lms; 53 bảng'),('Timezone / locale','UTC / vi'),('Environment','local; Debug Mode đang bật'),('Node.js / npm','Có sẵn trên PATH; package frontend định nghĩa build bằng Vite'),('Ứng dụng cục bộ','http://127.0.0.1:8123'),
    ],[2.0,4.6],9)
    para(doc,'Nguồn trong workspace: routes/web.php; app/Livewire; app/Services; app/Models; database/migrations; resources/views; composer.json; package.json; public/css/layout-overflow-fix.css; và MOI-TRUONG.md.')

    doc.add_page_break()
    doc.add_heading('Phụ lục B. Ảnh chụp các màn hình chính',1)
    para(doc,'Các ảnh dưới đây được chụp từ ứng dụng đang chạy tại local, trong dữ liệu demo hiện hữu. Mỗi ô là một viewport desktop, giúp đối chiếu nhóm màn với tiêu đề và khối hiển thị đã nêu ở phần 4.')
    add_image(doc,admin_sheet,6.55,'B.1. Dashboard, danh sách nhân sự, tài liệu, khóa học, quiz, bảo mật và các màn quản trị khác.')
    add_image(doc,user_sheet,6.55,'B.2. Sự kiện, onboarding, khóa học, tiến độ, tài liệu, hỗ trợ, hồ sơ, chi tiết khóa và bài kiểm tra.')
    add_image(doc,SCREENSHOTS/'admin-dashboard-mobile.png',3.35,'B.3. Quản trị tại viewport 390×844; ảnh thể hiện vùng nội dung bị co hẹp bởi sidebar.')
    add_image(doc,SCREENSHOTS/'user-ai-mobile.png',3.35,'B.4. Trợ lý AI tại viewport 390×844; popup nằm trong khung nhìn.')

    doc.add_page_break()
    doc.add_heading('Phụ lục C. Đặc tả chức năng nguồn v0.5 (Draft)',1)
    para(doc,'Phần dưới đây lưu lại đầy đủ tài liệu he-thong-dao-tao-nhan-vien-LMS.md trong workspace để giữ các yêu cầu, cấu trúc bảng và câu hỏi mở chi tiết. Đây là đặc tả nguồn ngày 24/08/2026, được ghi nhận là bản Draft; các đoạn nói về phương án đề xuất hoặc câu hỏi mở không phải bằng chứng rằng chức năng đó đã có trong code.')
    append_markdown_source(doc,ROOT/'he-thong-dao-tao-nhan-vien-LMS.md')

    doc.save(DOCX_PATH)
    print(DOCX_PATH)


if __name__ == '__main__': main()
