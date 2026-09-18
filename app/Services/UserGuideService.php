<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Setting;
use FPDF;

/**
 * Sinh tài liệu hướng dẫn sử dụng hệ thống dưới dạng PDF.
 *
 * Nội dung soạn theo vai trò: quản trị viên và nhân viên dùng hai bộ chức năng
 * khác nhau, gộp một bản sẽ khiến người đọc phải lọc phần không liên quan.
 *
 * Sinh tại thời điểm tải chứ không lưu file cố định: nội dung tự cập nhật theo
 * cấu hình hiện tại (tên công ty, giới hạn thiết bị), và đóng được watermark
 * tên người tải.
 */
class UserGuideService
{
    public const AUDIENCE_ADMIN = 'admin';
    public const AUDIENCE_EMPLOYEE = 'employee';

    /** Lề trang, đơn vị mm. */
    private const MARGIN = 18;

    /**
     * Tạo file PDF hướng dẫn, trả về đường dẫn file tạm.
     *
     * @param  bool  $watermark  Đóng dấu tên người tải lên từng trang
     */
    public function generate(string $audience, ?Employee $employee = null, bool $watermark = true): string
    {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $appName = (string) Setting::get('app.display_name', config('app.name'));
        $companyName = (string) Setting::get('company.name', 'Techcombank');

        $this->coverBlock($pdf, $appName, $companyName, $audience, $employee);

        foreach ($this->sections($audience) as $section) {
            $this->sectionBlock($pdf, $section['title'], $section['body']);
        }

        $this->footerNote($pdf);

        $path = tempnam(sys_get_temp_dir(), 'guide_') . '.pdf';
        $pdf->Output('F', $path);

        // Đóng watermark bằng chính service dùng cho tài liệu đào tạo, để dấu
        // trên mọi file tải về có cùng một dạng
        if ($watermark && $employee) {
            $stamped = app(PdfWatermarkService::class)->stamp($path, [
                app(DocumentAccessService::class)->watermarkText($employee),
                'Ban huong dan tai luc ' . now()->format('d/m/Y H:i'),
            ]);

            if ($stamped) {
                @unlink($path);

                return $stamped;
            }
        }

        return $path;
    }

    public function fileName(string $audience): string
    {
        return $audience === self::AUDIENCE_ADMIN
            ? 'Huong-dan-su-dung-Quan-tri.pdf'
            : 'Huong-dan-su-dung-Nhan-vien.pdf';
    }

    private function coverBlock(FPDF $pdf, string $appName, string $companyName, string $audience, ?Employee $employee): void
    {
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->MultiCell(0, 9, $this->latin('HƯỚNG DẪN SỬ DỤNG'), 0, 'L');

        $pdf->SetFont('Helvetica', '', 13);
        $pdf->MultiCell(0, 7, $this->latin($appName . ' — ' . $companyName), 0, 'L');

        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(192, 0, 22);
        $pdf->MultiCell(0, 6, $this->latin(
            $audience === self::AUDIENCE_ADMIN
                ? 'Dành cho Quản trị viên'
                : 'Dành cho Nhân viên'
        ), 0, 'L');

        $pdf->SetTextColor(110, 110, 110);
        $pdf->SetFont('Helvetica', '', 9);

        $meta = 'Ban in ngay ' . now()->format('d/m/Y H:i');

        if ($employee) {
            $meta .= ' — Nguoi tai: ' . $this->latin($employee->full_name);
        }

        $pdf->MultiCell(0, 5, $meta, 0, 'L');

        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Cell(0, 0, '', 'T');
        $pdf->Ln(5);
    }

    /** Một mục: tiêu đề đậm + nội dung. */
    private function sectionBlock(FPDF $pdf, string $title, array $body): void
    {
        // Còn ít hơn 40mm thì sang trang mới, tránh tiêu đề đứng lẻ cuối trang
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
        }

        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(20, 24, 33);
        $pdf->MultiCell(0, 7, $this->latin($title), 0, 'L');
        $pdf->Ln(1);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(60, 60, 60);

        foreach ($body as $line) {
            $pdf->MultiCell(0, 5.5, $this->latin($line), 0, 'L');
            $pdf->Ln(1);
        }

        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
    }

    private function footerNote(FPDF $pdf): void
    {
        if ($pdf->GetY() > 240) {
            $pdf->AddPage();
        }

        $pdf->Ln(4);
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Cell(0, 0, '', 'T');
        $pdf->Ln(4);

        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell(0, 5, $this->latin(
            'Tài liệu này được hệ thống sinh tự động, nội dung phản ánh cấu hình '
            . 'tại thời điểm tải. Gặp vướng mắc chưa có trong tài liệu, vui lòng '
            . 'gửi phiếu hỗ trợ trong mục Hỗ trợ.'
        ), 0, 'L');
    }

    /**
     * Nội dung hướng dẫn theo vai trò.
     *
     * @return array<int, array{title: string, body: array<int, string>}>
     */
    private function sections(string $audience): array
    {
        return $audience === self::AUDIENCE_ADMIN
            ? $this->adminSections()
            : $this->employeeSections();
    }

    /** @return array<int, array{title: string, body: array<int, string>}> */
    private function employeeSections(): array
    {
        $maxDevices = (int) Setting::get('security.max_concurrent_devices', 2);

        return [
            [
                'title' => '1. Đăng nhập hệ thống',
                'body' => [
                    'Sử dụng email công ty và mật khẩu do quản trị viên cấp. Lần đầu đăng nhập, '
                        . 'bạn nên đổi mật khẩu ngay trong mục Thông tin cá nhân.',
                    'Hệ thống cho phép đăng nhập tối đa ' . $maxDevices . ' thiết bị cùng lúc. '
                        . 'Vượt quá, thiết bị đăng nhập lâu nhất sẽ tự bị đăng xuất.',
                    'Quên mật khẩu: liên hệ Phòng Nhân sự hoặc bộ phận IT để được cấp lại. '
                        . 'Hệ thống không gửi email đặt lại mật khẩu tự động.',
                ],
            ],
            [
                'title' => '2. Trang Sự kiện',
                'body' => [
                    'Đây là trang đầu tiên khi bạn đăng nhập. Danh sách sự kiện và thông báo '
                        . 'được sắp theo mức độ cần chú ý.',
                    'Sự kiện dành riêng cho bạn có viền đỏ và nhãn "Dành riêng cho bạn", luôn '
                        . 'nằm trên đầu. Tiếp đến là sự kiện đang diễn ra, rồi sự kiện sắp tới.',
                    'Ba bộ lọc: Tất cả, Riêng tôi, Sắp diễn ra. Số bên cạnh "Riêng tôi" là số '
                        . 'sự kiện dành riêng cho bạn.',
                ],
            ],
            [
                'title' => '3. Khóa học của tôi',
                'body' => [
                    'Danh sách khóa học được giao cho bạn. Mỗi thẻ hiển thị tiến độ, số bài còn '
                        . 'lại và hạn hoàn thành.',
                    'Khóa "Bắt buộc" phải hoàn thành trước hạn. Khóa quá hạn có viền đỏ và nhãn '
                        . '"Quá hạn", được đẩy lên đầu danh sách.',
                    'Vòng tròn tiến độ ở phần đầu trang là trung bình tiến độ của tất cả khóa, '
                        . 'không phải tỉ lệ khóa đã xong.',
                    'Chuỗi ngày học liên tiếp hiện cạnh tên bạn — học đều mỗi ngày để giữ chuỗi.',
                ],
            ],
            [
                'title' => '4. Học bài',
                'body' => [
                    'Một khóa gồm nhiều bài với ba dạng: bài đọc, video, và bài kiểm tra trắc nghiệm.',
                    'Khóa đánh dấu "Học tuần tự" bắt buộc hoàn thành bài trước mới mở được bài sau. '
                        . 'Bài chưa mở khóa hiện màu xám và không bấm được.',
                    'Bài đọc và bài tài liệu: đọc xong bấm "Đánh dấu hoàn thành".',
                    'Video: xem hết rồi bấm "Tôi đã xem xong". Video nhúng từ YouTube/Vimeo không '
                        . 'đo được phần trăm đã xem, nên hệ thống dựa vào xác nhận của bạn.',
                    'Bài kiểm tra: hoàn thành khi đạt điểm tối thiểu. Không bấm tay để hoàn thành được.',
                    'Xong hết bài, nút "Bài sau" đổi thành "Thoát khóa học" để về danh sách.',
                ],
            ],
            [
                'title' => '5. Bài kiểm tra trắc nghiệm',
                'body' => [
                    'Mỗi bài có điểm đạt tối thiểu và số lần làm lại giới hạn. Thông tin này hiện '
                        . 'trước khi bắt đầu.',
                    'Câu hỏi và đáp án có thể được xáo trộn thứ tự mỗi lần làm.',
                    'Có giới hạn thời gian: đồng hồ đếm ngược hiện ở đầu trang. Hết thời gian, bài '
                        . 'tự nộp với các câu đã trả lời.',
                    'Sau khi nộp, bạn xem được điểm và phần giải thích đáp án cho từng câu.',
                ],
            ],
            [
                'title' => '6. Tài liệu nội bộ',
                'body' => [
                    'Chỉ hiện tài liệu bạn được cấp quyền xem. Không thấy tài liệu nào đó nghĩa là '
                        . 'bạn chưa được cấp quyền, không phải lỗi hệ thống.',
                    'Tài liệu có thể cho xem trực tuyến mà không cho tải bản gốc. Nút tải chỉ hiện '
                        . 'khi bạn có quyền.',
                    'Tài liệu bật watermark sẽ hiện tên và email của bạn đè lên nội dung khi xem. '
                        . 'File PDF tải về cũng được đóng dấu tương tự.',
                    'Mọi lần xem và tải đều được ghi nhật ký. Đây là yêu cầu bảo mật, không phải '
                        . 'theo dõi cá nhân.',
                ],
            ],
            [
                'title' => '7. Tiến độ học tập',
                'body' => [
                    'Xem tổng quan tiến độ, danh sách khóa đã xong, đang học và quá hạn.',
                    'Chứng nhận hoàn thành khóa học (nếu khóa có cấp) hiện ở mục này, tải về được.',
                ],
            ],
            [
                'title' => '8. Trợ lý AI đào tạo',
                'body' => [
                    'Nút tròn góc dưới phải mở hộp chat. Trợ lý trả lời dựa trên nội dung bài giảng '
                        . 'và tài liệu mà chính bạn được phép xem.',
                    'Trợ lý không trả lời câu hỏi ngoài phạm vi tài liệu. Hỏi những việc không có '
                        . 'trong tài liệu, trợ lý sẽ nói không tìm thấy chứ không suy đoán.',
                    'Trợ lý không đọc đáp án bài kiểm tra hộ bạn. Hỏi về phần kiến thức liên quan, '
                        . 'trợ lý sẽ giảng lại để bạn tự làm.',
                    'Mỗi câu trả lời kèm nguồn tham khảo, bấm vào để mở bài giảng gốc.',
                ],
            ],
            [
                'title' => '9. Hỗ trợ',
                'body' => [
                    'Danh sách đầu mối liên hệ theo chủ đề: hỏi về hợp đồng liên hệ Nhân sự, hỏi về '
                        . 'nội dung bài giảng liên hệ phòng Đào tạo.',
                    'Đầu mối của chính phòng ban bạn được xếp trước đầu mối chung.',
                    'Gửi phiếu hỗ trợ khi cần giải đáp trực tiếp. Bạn theo dõi được trạng thái xử lý '
                        . 'của phiếu đã gửi.',
                    'Tài liệu hướng dẫn này tải được ở mục Hỗ trợ, bản cập nhật theo cấu hình hiện tại.',
                ],
            ],
            [
                'title' => '10. Thông tin cá nhân',
                'body' => [
                    'Xem thông tin hồ sơ: mã nhân viên, phòng ban, chức danh, cấp bậc. Thông tin này '
                        . 'do Phòng Nhân sự quản lý, cần sửa thì liên hệ họ.',
                    'Đổi ảnh đại diện: bấm "Đổi ảnh", chọn ảnh JPG/PNG/WEBP dưới 2MB, xem trước rồi '
                        . 'bấm Lưu. Chưa upload thì hệ thống hiện vòng tròn chữ viết tắt tên bạn.',
                    'Đổi mật khẩu và xem danh sách thiết bị đang đăng nhập. Thấy thiết bị lạ, thu hồi '
                        . 'ngay và báo bộ phận IT.',
                ],
            ],
        ];
    }

    /** @return array<int, array{title: string, body: array<int, string>}> */
    private function adminSections(): array
    {
        return [
            [
                'title' => '1. Tổng quan hệ thống',
                'body' => [
                    'Hệ thống gồm hai site chạy trên cùng mã nguồn: site Quản trị (/quan-tri) và '
                        . 'site Học tập (/hoc-tap). Nhân viên thường vào site quản trị sẽ được chuyển '
                        . 'về site học tập.',
                    'Có hai vai trò: Quản trị viên (toàn quyền) và Nhân viên (chỉ học tập).',
                    'Lưu ý quan trọng: Quản trị viên có toàn quyền, bao gồm xem hợp đồng và lương của '
                        . 'mọi nhân viên. Chỉ cấp vai trò này cho người được phép tiếp cận dữ liệu nhân sự.',
                ],
            ],
            [
                'title' => '2. Màn hình Tổng quan',
                'body' => [
                    'Bốn thẻ đầu là việc cần xử lý: khóa học quá hạn, hợp đồng sắp hết hạn, cảnh báo '
                        . 'bảo mật, phiếu hỗ trợ chờ. Bấm vào để mở màn hình tương ứng.',
                    'Chọn khoảng thời gian 7/30/90 ngày để xem số liệu theo kỳ.',
                    'Con số màu vàng là việc chưa hoàn thành, cần chú ý.',
                    'Mục "Khóa học cần can thiệp" liệt kê khóa có tiến độ thấp nhất — dấu hiệu nội dung '
                        . 'khó hiểu hoặc thời hạn quá gấp.',
                ],
            ],
            [
                'title' => '3. Quản lý tài khoản và nhân sự',
                'body' => [
                    'Tài khoản: tạo, sửa, khóa tài khoản đăng nhập. Tài khoản mới đánh dấu "nhân viên mới" '
                        . 'sẽ tự được gán lộ trình onboarding.',
                    'Hồ sơ nhân sự: thông tin nhân viên, phòng ban, chức danh, cấp bậc, quản lý trực tiếp. '
                        . 'Nhập khẩu hàng loạt từ file Excel được.',
                    'Chuyển phòng ban hoặc đổi chức danh sẽ ghi lại lịch sử điều chuyển, xem được ở trang '
                        . 'chi tiết nhân viên.',
                    'Hợp đồng: quản lý loại hợp đồng, thời hạn hiệu lực, file scan. Hệ thống cảnh báo hợp '
                        . 'đồng sắp hết hạn theo số ngày cấu hình được.',
                ],
            ],
            [
                'title' => '4. Kho tài nguyên',
                'body' => [
                    'Mọi file trong hệ thống đều nằm ở đây: tài liệu đào tạo, hợp đồng, ảnh đại diện, biểu mẫu.',
                    'Tạo thư mục, kéo thả file từ máy để tải lên. Giới hạn 2MB mỗi file.',
                    'Bộ thư mục hệ thống được tạo tự động và không xóa được, để các phân hệ khác luôn có '
                        . 'chỗ lưu file.',
                    'File xóa vào thùng rác, khôi phục được. File đang được tài liệu hoặc hợp đồng tham chiếu '
                        . 'thì không xóa vĩnh viễn được.',
                ],
            ],
            [
                'title' => '5. Thư viện tài liệu',
                'body' => [
                    'Tạo tài liệu dạng file (PDF, Word, Excel) hoặc video nhúng từ YouTube/Vimeo.',
                    'Mỗi lần tải file mới lên là một phiên bản riêng. Phiên bản cũ vẫn giữ, khôi phục được '
                        . 'bằng nút Rollback.',
                    'Cờ "Cho phép tải bản gốc" là trần cứng: rule phân quyền không nới rộng được. Tắt cờ này, '
                        . 'không ai tải được bản gốc dù có quyền tải.',
                    'Cờ "Bật watermark": khi xem, tên và email người xem hiện đè lên tài liệu. Khi tải file PDF, '
                        . 'dấu được ghi thẳng vào từng trang.',
                    'Tài liệu phải Xuất bản mới hiện với nhân viên. Chưa xuất bản là bản nháp.',
                ],
            ],
            [
                'title' => '6. Bộ tài liệu ban hành (khóa học)',
                'body' => [
                    'Một khóa gồm nhiều bài. Bài có ba dạng: bài đọc (soạn nội dung trực tiếp), video (dán link '
                        . 'YouTube/Vimeo), và bài kiểm tra (chọn từ danh sách bài kiểm tra đã tạo).',
                    'Kéo thả để sắp thứ tự bài. Chia bài thành chương nếu khóa dài.',
                    'Bật "Học tuần tự" để bắt buộc hoàn thành bài trước mới mở bài sau.',
                    'Gán khóa học theo điều kiện: phòng ban, chức danh, cấp bậc, hoặc nhân viên mới. Nhân viên '
                        . 'khớp điều kiện sẽ tự được gán khi hồ sơ thay đổi.',
                    'Đánh dấu "Khóa onboarding" để khóa tự gán cho nhân viên mới và hiện ở màn Chào mừng.',
                ],
            ],
            [
                'title' => '7. Bài kiểm tra trắc nghiệm',
                'body' => [
                    'Tạo bài kiểm tra với điểm đạt, số lần làm lại, giới hạn thời gian, và tùy chọn xáo trộn '
                        . 'câu hỏi/đáp án.',
                    'Hai dạng câu hỏi: chọn một đáp án và chọn nhiều đáp án. Câu chọn một chỉ được đánh dấu '
                        . 'đúng một đáp án.',
                    'Nhập câu hỏi hàng loạt từ file Excel. Tải file mẫu từ trong màn hình. File có dòng lỗi thì '
                        . 'toàn bộ không được ghi, để tránh nhập nửa vời.',
                    'Trường "Giải thích đáp án" rất quan trọng: đây là nội dung trợ lý AI dùng để giảng lại cho '
                        . 'người ôn tập. Bỏ trống thì AI không có gì để hỗ trợ.',
                ],
            ],
            [
                'title' => '8. Sự kiện và thông báo',
                'body' => [
                    'Gửi sự kiện cho toàn công ty hoặc riêng một nhân viên. Sự kiện gán riêng được đẩy lên đầu '
                        . 'danh sách bên site người dùng.',
                    'Điền thời gian bắt đầu/kết thúc và địa điểm nếu là sự kiện có lịch. Để trống nếu chỉ là '
                        . 'thông báo chung.',
                    'Để trống ngày phát hành để lưu nháp. Bấm nút phát hành khi muốn hiện với nhân viên.',
                    'Gỡ sự kiện khỏi site người dùng mà vẫn giữ nội dung để đăng lại.',
                ],
            ],
            [
                'title' => '9. Phân quyền tài liệu',
                'body' => [
                    'Nguyên tắc: mặc định từ chối. Tài liệu không có rule cho phép thì không ai xem được.',
                    'Rule từ chối luôn thắng rule cho phép. Một nhân viên khớp cả hai loại rule sẽ bị từ chối.',
                    'Rule gán theo phòng ban, chức danh, cấp bậc, hoặc khóa học. Gán rule cho danh mục tài liệu '
                        . 'thì áp dụng cho mọi tài liệu trong danh mục và danh mục con.',
                    'Kiểm tra quyền thực tế bằng chức năng thử: chọn một nhân viên và một tài liệu để xem hệ '
                        . 'thống quyết định thế nào.',
                ],
            ],
            [
                'title' => '10. Trung tâm bảo mật',
                'body' => [
                    'Nhật ký truy cập tài liệu: ai xem, ai tải, lúc nào, từ thiết bị nào.',
                    'Lịch sử đăng nhập và danh sách thiết bị đang hoạt động. Thu hồi được phiên đăng nhập bất kỳ.',
                    'Cảnh báo bảo mật: đăng nhập sai nhiều lần, tải tài liệu bất thường, truy cập bị từ chối.',
                    'Giới hạn quan trọng cần nêu rõ với đội bảo mật: watermark là biện pháp truy vết, không chặn '
                        . 'được việc quay/chụp màn hình bằng thiết bị khác.',
                ],
            ],
            [
                'title' => '11. Cấu hình chung',
                'body' => [
                    'Danh mục dùng chung: phòng ban, chức danh, cấp bậc, loại hợp đồng, danh mục tài liệu.',
                    'Tham số hệ thống: tên hiển thị, số ngày cảnh báo hợp đồng, số thiết bị đăng nhập tối đa, '
                        . 'bật/tắt watermark toàn hệ thống.',
                    'Nội dung màn Chào mừng nhân viên mới: giới thiệu công ty, giá trị cốt lõi, quy trình cần biết. '
                        . 'Để trống mục nào thì mục đó không hiện.',
                    'Đầu mối hỗ trợ theo chủ đề và phòng ban, hiện ở màn Hỗ trợ và màn Chào mừng.',
                ],
            ],
            [
                'title' => '12. Trợ lý AI',
                'body' => [
                    'Trợ lý dùng Groq API, cấu hình khóa trong biến môi trường GROQ_API_KEY. Không đặt khóa trong '
                        . 'mã nguồn.',
                    'Có danh sách model dự phòng: model chính hỏng thì hệ thống tự chuyển. Cấu hình qua biến '
                        . 'GROQ_FALLBACK_MODELS.',
                    'Trợ lý chỉ trả lời dựa trên nội dung nhân viên đó được phép xem. Nhân viên chưa được gán khóa '
                        . 'học thì AI không thấy bài giảng của khóa đó.',
                    'Chất lượng trả lời phụ thuộc nội dung bài giảng và phần giải thích đáp án. Nội dung sơ sài thì '
                        . 'trợ lý không có gì để trả lời.',
                    'Lịch sử hội thoại được lưu. Đây là dữ liệu cá nhân, cần đưa vào danh mục khi rà soát tuân thủ.',
                ],
            ],
        ];
    }

    /**
     * Bỏ dấu tiếng Việt.
     *
     * FPDF dùng font core Latin-1, chữ có dấu sẽ ra ký tự lỗi. Bỏ dấu vẫn đọc
     * hiểu được toàn bộ nội dung.
     */
    private function latin(string $text): string
    {
        static $map = null;

        if ($map === null) {
            $from = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ'
                . 'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴÈÉẸẺẼÊỀẾỆỂỄÌÍỊỈĨÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠÙÚỤỦŨƯỪỨỰỬỮỲÝỴỶỸĐ';
            $to = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyyd'
                . 'AAAAAAAAAAAAAAAAAEEEEEEEEEEEIIIIIOOOOOOOOOOOOOOOOOUUUUUUUUUUUYYYYYD';

            $map = array_combine(
                preg_split('//u', $from, -1, PREG_SPLIT_NO_EMPTY),
                preg_split('//u', $to, -1, PREG_SPLIT_NO_EMPTY)
            );
        }

        $text = strtr($text, $map);

        // Ký tự đặc biệt trong nội dung: đổi sang dạng Latin-1 vẽ được
        return str_replace(['—', '–', '•', '“', '”', '’'], ['-', '-', '*', '"', '"', "'"], $text);
    }
}
