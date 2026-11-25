<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;

/**
 * Chọn số dòng mỗi trang cho data grid (fb7 mục 6: "Mỗi trang [15 ▾]").
 *
 * - Giá trị nằm trên URL (?so-dong=50) để F5 / chia sẻ link vẫn giữ lựa chọn;
 *   bằng mặc định thì không hiện trên URL.
 * - Chỉ nhận giá trị trong perPageOptions(); giá trị lạ (gõ tay trên URL,
 *   request giả mạo) quay về mặc định — không để ai xin 100.000 dòng một lần.
 * - Đổi số dòng thì về trang 1, tránh đứng ở trang không còn tồn tại.
 *
 * View phân trang dùng chung (vendor/livewire/tailwind) tự hiện ô "Mỗi trang"
 * khi component có trait này, nên component chỉ cần paginate($this->perPage()).
 */
trait HasPerPage
{
    /**
     * Không khai báo kiểu: giá trị từ URL / select là chuỗi bất kỳ,
     * kiểu int sẽ ném TypeError trước khi kịp chuẩn hóa.
     *
     * @var int|string
     */
    #[Url(as: 'so-dong', except: 15)]
    public $perPage = 15;

    /** Các lựa chọn hiển thị trong ô "Mỗi trang"; phần tử đầu là mặc định. */
    public function perPageOptions(): array
    {
        return [15, 20, 50, 100];
    }

    /** Số dòng hợp lệ dùng cho paginate(). protected: không cho gọi từ trình duyệt. */
    protected function perPage(): int
    {
        $options = $this->perPageOptions();
        $value = filter_var($this->perPage, FILTER_VALIDATE_INT);

        return in_array($value, $options, true) ? $value : $options[0];
    }

    /** Chuẩn hóa trước mỗi lần render để ô chọn luôn khớp một lựa chọn có thật. */
    public function renderingHasPerPage(): void
    {
        $this->perPage = $this->perPage();
    }

    /** Hook updated{Trait}: chạy cho mọi thuộc tính, chỉ xử lý perPage. */
    public function updatedHasPerPage($property): void
    {
        if ($property !== 'perPage') {
            return;
        }

        $this->perPage = $this->perPage();

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
