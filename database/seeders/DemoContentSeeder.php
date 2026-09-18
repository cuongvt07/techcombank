<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAccessRule;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Tài liệu nội bộ và sự kiện mẫu ở quy mô dùng được ngay.
 *
 * Tài liệu tạo dạng "chỉ xem trực tuyến" hoặc "cho tải" xen kẽ, kèm rule phân
 * quyền theo phòng ban — để màn hình phân quyền tài liệu và nhật ký truy cập có
 * dữ liệu thật thay vì bảng trống.
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->value('id');
        $departments = Department::where('code', '!=', 'HQ')->get()->keyBy('code');
        $categories = $this->ensureCategories();

        $this->createDocuments($adminId, $departments, $categories);
        $this->createAnnouncements($adminId);
    }

    /** @return \Illuminate\Support\Collection<string, DocumentCategory> */
    private function ensureCategories(): \Illuminate\Support\Collection
    {
        $rows = [
            ['QT', 'Quy trình nghiệp vụ'],
            ['BM', 'Biểu mẫu'],
            ['CS', 'Chính sách & quy định'],
            ['SP', 'Tài liệu sản phẩm'],
            ['ĐT', 'Tài liệu đào tạo'],
        ];

        foreach ($rows as [$code, $name]) {
            DocumentCategory::firstOrCreate(['code' => $code], ['name' => $name]);
        }

        return DocumentCategory::all()->keyBy('code');
    }

    private function createDocuments(?int $adminId, $departments, $categories): void
    {
        foreach ($this->documentDefinitions() as $def) {
            if (Document::where('title', $def['title'])->exists()) {
                continue;
            }

            $document = Document::create([
                'title' => $def['title'],
                'slug' => Str::slug($def['title']) . '-' . Str::random(5),
                'description' => $def['description'],
                'document_category_id' => $categories->get($def['category'])?->id,
                'owner_department_id' => $departments->get($def['owner'] ?? 'TRN')?->id,
                'kind' => Document::KIND_FILE,
                'confidentiality' => $def['confidentiality'],
                // Tài liệu mật chỉ cho xem trực tuyến, không cho tải bản gốc
                'allow_download' => $def['allow_download'],
                'enable_watermark' => $def['watermark'],
                'status' => Document::STATUS_PUBLISHED,
                'published_at' => now()->subDays(random_int(5, 200)),
                'published_by' => $adminId,
                'created_by' => $adminId,
            ]);

            $this->makeAccessRule($document, $def, $departments);
        }
    }

    /**
     * Rule cho phép xem.
     *
     * Hệ thống mặc định từ chối: không có rule nào thì không ai xem được tài
     * liệu, kể cả tài liệu công khai.
     */
    private function makeAccessRule(Document $document, array $def, $departments): void
    {
        // Tài liệu nội bộ chung: mọi nhân viên xem được
        if (empty($def['restrict_to'])) {
            DocumentAccessRule::create([
                'document_id' => $document->id,
                'effect' => DocumentAccessRule::EFFECT_ALLOW,
                'can_view' => true,
                'can_download' => $def['allow_download'],
                'can_print' => $def['allow_download'],
                'is_active' => true,
            ]);

            return;
        }

        foreach ((array) $def['restrict_to'] as $deptCode) {
            $dept = $departments->get($deptCode);

            if (! $dept) {
                continue;
            }

            DocumentAccessRule::create([
                'document_id' => $document->id,
                'department_id' => $dept->id,
                'effect' => DocumentAccessRule::EFFECT_ALLOW,
                'can_view' => true,
                'can_download' => $def['allow_download'],
                'can_print' => false,
                'is_active' => true,
            ]);
        }
    }

    private function createAnnouncements(?int $adminId): void
    {
        $employees = Employee::where('employment_status', '!=', Employee::STATUS_RESIGNED)
            ->inRandomOrder()
            ->limit(12)
            ->get();

        foreach ($this->announcementDefinitions() as $index => $def) {
            if (Announcement::where('title', $def['title'])->exists()) {
                continue;
            }

            // Một phần sự kiện gán riêng để thấy rõ thứ tự ưu tiên bên site người dùng
            $employee = ($def['personal'] ?? false) ? $employees->get($index % max(1, $employees->count())) : null;

            Announcement::create([
                'title' => $def['title'],
                'content' => $def['content'],
                'type' => $def['type'],
                'employee_id' => $employee?->id,
                'starts_at' => $def['starts_at'] ?? null,
                'ends_at' => $def['ends_at'] ?? null,
                'location' => $def['location'] ?? null,
                'published_at' => $def['draft'] ?? false ? null : now()->subDays(random_int(1, 20)),
                'created_by' => $adminId,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function documentDefinitions(): array
    {
        return [
            [
                'title' => 'Quy trình mở tài khoản khách hàng cá nhân',
                'description' => 'Hướng dẫn đầy đủ các bước tiếp nhận hồ sơ, xác minh danh tính, '
                    . 'nhập liệu hệ thống và bàn giao thẻ cho khách hàng cá nhân.',
                'category' => 'QT', 'owner' => 'OPS',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => true, 'watermark' => true,
            ],
            [
                'title' => 'Quy trình xử lý giao dịch chuyển tiền nhầm',
                'description' => 'Các bước tra soát, liên hệ chủ tài khoản nhận và mốc thời gian '
                    . 'phản hồi khách hàng theo quy định.',
                'category' => 'QT', 'owner' => 'OPS',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => true, 'watermark' => true,
            ],
            [
                'title' => 'Chính sách tín dụng khách hàng cá nhân 2026',
                'description' => 'Điều kiện cho vay, hạn mức theo từng nhóm sản phẩm, tỉ lệ cho vay '
                    . 'trên giá trị tài sản bảo đảm và thẩm quyền phê duyệt.',
                'category' => 'CS', 'owner' => 'CRD',
                'confidentiality' => Document::CONF_CONFIDENTIAL,
                'allow_download' => false, 'watermark' => true,
                'restrict_to' => ['CRD', 'RSK'],
            ],
            [
                'title' => 'Biểu phí dịch vụ áp dụng từ 01/2026',
                'description' => 'Biểu phí đầy đủ các dịch vụ: tài khoản, thẻ, chuyển tiền, '
                    . 'ngân hàng điện tử và dịch vụ khách hàng doanh nghiệp.',
                'category' => 'CS',
                'confidentiality' => Document::CONF_PUBLIC,
                'allow_download' => true, 'watermark' => false,
            ],
            [
                'title' => 'Sổ tay nhân viên mới',
                'description' => 'Thông tin cần biết trong tháng đầu: quy định nội bộ, quyền lợi, '
                    . 'đầu mối liên hệ và lộ trình phát triển.',
                'category' => 'ĐT', 'owner' => 'HR',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => true, 'watermark' => false,
            ],
            [
                'title' => 'Mẫu tờ trình thẩm định tín dụng',
                'description' => 'Biểu mẫu chuẩn dùng cho hồ sơ vay khách hàng cá nhân, có hướng dẫn điền.',
                'category' => 'BM', 'owner' => 'CRD',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => true, 'watermark' => false,
                'restrict_to' => ['CRD'],
            ],
            [
                'title' => 'Mẫu biên bản bàn giao công việc',
                'description' => 'Biểu mẫu dùng khi nhân viên nghỉ việc hoặc chuyển công tác.',
                'category' => 'BM', 'owner' => 'HR',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => true, 'watermark' => false,
            ],
            [
                'title' => 'Hướng dẫn sản phẩm thẻ tín dụng 2026',
                'description' => 'Đặc điểm từng dòng thẻ, hạn mức, ưu đãi và đối tượng khách hàng phù hợp.',
                'category' => 'SP', 'owner' => 'RTL',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => true, 'watermark' => true,
            ],
            [
                'title' => 'Quy định an toàn thông tin nội bộ',
                'description' => 'Nguyên tắc sử dụng thiết bị, quản lý mật khẩu, xử lý sự cố '
                    . 'và các hành vi bị cấm.',
                'category' => 'CS', 'owner' => 'IT',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => false, 'watermark' => true,
            ],
            [
                'title' => 'Báo cáo phân tích rủi ro danh mục quý III/2026',
                'description' => 'Đánh giá chất lượng danh mục tín dụng, tỉ lệ nợ xấu theo phân khúc '
                    . 'và khuyến nghị điều chỉnh khẩu vị rủi ro.',
                'category' => 'CS', 'owner' => 'RSK',
                'confidentiality' => Document::CONF_RESTRICTED,
                'allow_download' => false, 'watermark' => true,
                'restrict_to' => ['RSK'],
            ],
            [
                'title' => 'Quy trình tiếp nhận và xử lý khiếu nại',
                'description' => 'Phân loại khiếu nại, mốc thời gian phản hồi bắt buộc và '
                    . 'thẩm quyền xử lý theo từng cấp.',
                'category' => 'QT', 'owner' => 'RTL',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => true, 'watermark' => true,
            ],
            [
                'title' => 'Hướng dẫn sử dụng hệ thống core banking',
                'description' => 'Thao tác cơ bản trên hệ thống: tra cứu, hạch toán, đối chiếu cuối ngày.',
                'category' => 'ĐT', 'owner' => 'IT',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => false, 'watermark' => true,
            ],
            [
                'title' => 'Quy định về quản lý và sử dụng con dấu',
                'description' => 'Thẩm quyền sử dụng, quy trình đăng ký và trách nhiệm bảo quản con dấu.',
                'category' => 'CS', 'owner' => 'LEG',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => false, 'watermark' => true,
            ],
            [
                'title' => 'Tài liệu sản phẩm tiền gửi tiết kiệm',
                'description' => 'Các kỳ hạn, lãi suất tham chiếu, điều kiện rút trước hạn '
                    . 'và cách tư vấn theo nhu cầu khách hàng.',
                'category' => 'SP', 'owner' => 'RTL',
                'confidentiality' => Document::CONF_INTERNAL,
                'allow_download' => true, 'watermark' => false,
            ],
            [
                'title' => 'Mẫu đơn đề nghị cấp lại thẻ',
                'description' => 'Biểu mẫu khách hàng điền khi mất thẻ hoặc thẻ hỏng.',
                'category' => 'BM', 'owner' => 'OPS',
                'confidentiality' => Document::CONF_PUBLIC,
                'allow_download' => true, 'watermark' => false,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function announcementDefinitions(): array
    {
        return [
            [
                'title' => 'Hội nghị sơ kết hoạt động 9 tháng đầu năm',
                'content' => "Toàn thể cán bộ nhân viên tham dự hội nghị sơ kết hoạt động 9 tháng "
                    . "và triển khai nhiệm vụ quý IV.\n\nCác khối chuẩn bị báo cáo gửi Ban Tổng hợp "
                    . "trước ngày họp 3 ngày.",
                'type' => Announcement::TYPE_GENERAL,
                'starts_at' => now()->addDays(9)->setTime(8, 0),
                'ends_at' => now()->addDays(9)->setTime(11, 30),
                'location' => 'Hội trường tầng 12 — Trụ sở chính',
            ],
            [
                'title' => 'Đang diễn ra: Tháng cao điểm an toàn thông tin',
                'content' => 'Chuỗi hoạt động nâng cao nhận thức an toàn thông tin trên toàn hệ thống. '
                    . 'Có phần thi trắc nghiệm trực tuyến, ba giải thưởng cho người đạt điểm cao nhất.',
                'type' => Announcement::TYPE_SYSTEM,
                'starts_at' => now()->subDays(4),
                'ends_at' => now()->addDays(10),
                'location' => 'Trực tuyến — Microsoft Teams',
            ],
            [
                'title' => 'Ban hành quy trình xử lý khiếu nại phiên bản 2026',
                'content' => 'Quy trình tiếp nhận và xử lý khiếu nại đã được cập nhật, áp dụng từ đầu tháng sau. '
                    . 'Thay đổi chính: rút ngắn thời gian phản hồi khiếu nại thẻ từ 7 ngày xuống 5 ngày làm việc. '
                    . 'Nhân viên quầy đọc kỹ tài liệu trong mục Tài liệu nội bộ.',
                'type' => Announcement::TYPE_DOCUMENT,
            ],
            [
                'title' => 'Khóa đào tạo phòng chống rửa tiền — bắt buộc hoàn thành trong tháng',
                'content' => 'Khóa AML là yêu cầu bắt buộc theo quy định của Ngân hàng Nhà nước. '
                    . 'Nhân viên chưa hoàn thành cần sắp xếp thời gian học trước hạn.',
                'type' => Announcement::TYPE_COURSE,
            ],
            [
                'title' => 'Lịch bảo trì hệ thống cuối tuần',
                'content' => "Hệ thống core banking tạm ngưng để nâng cấp.\n\n"
                    . "Thời gian: 23h00 thứ Bảy đến 5h00 Chủ nhật.\n"
                    . "Trong thời gian này, giao dịch qua ứng dụng và ATM tạm dừng. "
                    . "Các đơn vị thông báo trước cho khách hàng thường xuyên.",
                'type' => Announcement::TYPE_SYSTEM,
                'starts_at' => now()->addDays(5)->setTime(23, 0),
                'ends_at' => now()->addDays(6)->setTime(5, 0),
            ],
            [
                'title' => 'Thông báo nghỉ lễ và bố trí trực',
                'content' => 'Lịch nghỉ lễ theo quy định. Các đơn vị lập danh sách trực và gửi về '
                    . 'Phòng Nhân sự trước ngày 25 hàng tháng.',
                'type' => Announcement::TYPE_GENERAL,
            ],
            [
                'title' => 'Buổi phỏng vấn đánh giá cuối kỳ thử việc',
                'content' => "Bạn được mời tham dự buổi đánh giá kết quả thử việc cùng quản lý trực tiếp "
                    . "và đại diện Phòng Nhân sự.\n\nVui lòng chuẩn bị bản tự đánh giá theo mẫu đã gửi qua email.",
                'type' => Announcement::TYPE_GENERAL,
                'personal' => true,
                'starts_at' => now()->addDays(3)->setTime(14, 0),
                'ends_at' => now()->addDays(3)->setTime(15, 0),
                'location' => 'Phòng họp Sông Hồng — Tầng 5',
            ],
            [
                'title' => 'Nhắc hoàn thành khóa đào tạo sắp đến hạn',
                'content' => 'Bạn còn khóa đào tạo bắt buộc chưa hoàn thành, sắp đến hạn. '
                    . 'Vui lòng vào mục Khóa học của tôi để tiếp tục.',
                'type' => Announcement::TYPE_COURSE,
                'personal' => true,
            ],
            [
                'title' => 'Hợp đồng lao động của bạn sắp hết hạn',
                'content' => 'Hợp đồng lao động của bạn sẽ hết hiệu lực trong thời gian tới. '
                    . 'Phòng Nhân sự sẽ liên hệ để trao đổi về việc gia hạn. '
                    . 'Nếu cần biết thêm, liên hệ đầu mối Nhân sự ở mục Hỗ trợ.',
                'type' => Announcement::TYPE_CONTRACT,
                'personal' => true,
            ],
            [
                'title' => 'Mời tham gia chương trình đào tạo quản lý cấp trung',
                'content' => 'Bạn được đề cử tham gia chương trình phát triển năng lực quản lý cấp trung. '
                    . 'Chương trình kéo dài 3 tháng, học vào thứ Bảy.',
                'type' => Announcement::TYPE_GENERAL,
                'personal' => true,
                'starts_at' => now()->addDays(20)->setTime(8, 30),
                'location' => 'Trung tâm Đào tạo — Chi nhánh Hà Nội',
            ],
            [
                'title' => 'Khảo sát mức độ hài lòng nhân viên quý III',
                'content' => 'Khảo sát ẩn danh, kết quả dùng để cải thiện môi trường làm việc. '
                    . 'Thời gian trả lời khoảng 10 phút.',
                'type' => Announcement::TYPE_GENERAL,
                'ends_at' => now()->addDays(14),
            ],
            [
                'title' => '[Nháp] Kế hoạch đào tạo quý IV',
                'content' => 'Nội dung đang hoàn thiện, chưa phát hành.',
                'type' => Announcement::TYPE_COURSE,
                'draft' => true,
            ],
        ];
    }
}
