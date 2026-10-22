<?php

namespace App\Livewire\Concerns;

use App\Support\Reports\ReportPeriod;
use Livewire\Attributes\Url;

/**
 * Bộ chọn kỳ báo cáo dùng chung (Hôm nay / 7 ngày / 30 ngày / Quý này / Tùy chọn).
 * Kỳ nằm trên URL để chia sẻ link báo cáo giữ nguyên bộ lọc.
 *
 * Component dùng trait có thể khai báo REPORT_MAX_DAYS để giới hạn độ dài kỳ.
 */
trait HasReportPeriod
{
    #[Url(as: 'ky')]
    public string $preset = '30d';

    #[Url(as: 'tu')]
    public ?string $from = null;

    #[Url(as: 'den')]
    public ?string $to = null;

    public function setPreset(string $preset): void
    {
        $this->preset = array_key_exists($preset, ReportPeriod::PRESETS) ? $preset : '30d';

        if ($this->preset === 'custom' && (! $this->from || ! $this->to)) {
            // Mở "Tùy chọn" thì điền sẵn kỳ đang xem để người dùng chỉ việc sửa
            $current = ReportPeriod::make('30d');
            [$this->from, $this->to] = $current->dateRange();
        }

        $this->onPeriodChanged();
    }

    public function updatedFrom(): void
    {
        $this->preset = 'custom';
        $this->onPeriodChanged();
    }

    public function updatedTo(): void
    {
        $this->preset = 'custom';
        $this->onPeriodChanged();
    }

    public function period(): ReportPeriod
    {
        $max = defined(static::class . '::REPORT_MAX_DAYS') ? static::REPORT_MAX_DAYS : 366;

        return ReportPeriod::make($this->preset, $this->from, $this->to, $max);
    }

    /** Ghi đè nếu cần làm gì khi đổi kỳ (mặc định về trang 1 nếu có phân trang). */
    protected function onPeriodChanged(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
