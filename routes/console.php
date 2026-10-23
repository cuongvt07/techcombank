<?php

use App\Services\SecurityMonitorService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Việc chạy nền, thay cho các nút "Đồng bộ trạng thái" / "Quét ngay" bấm tay.
 *
 * Hệ thống đã tự làm các việc này ngay lúc phát sinh (đăng nhập sai, xem/tải
 * tài liệu). Lịch dưới đây là lớp dự phòng cho những gì không ai thao tác tới.
 *
 * Chỉ chạy khi máy chủ có cron gọi mỗi phút:
 *   * * * * * cd /duong-dan-du-an && php artisan schedule:run >> /dev/null 2>&1
 */
Schedule::call(function () {
    $monitor = app(SecurityMonitorService::class);
    $monitor->detectMassDownload();
    $monitor->detectBruteForce();
})
    ->name('quet-canh-bao-bao-mat')
    ->everyTenMinutes();
