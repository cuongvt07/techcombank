<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Thông báo trong ứng dụng gửi tới một tài khoản (chuông thông báo).
 *
 * Chỉ dùng kênh database và gửi đồng bộ (không ShouldQueue): người nhận phải
 * thấy ngay trên chuông, không phụ thuộc queue worker có chạy hay không.
 * Nội dung dựng sẵn ở NotificationCenter để mọi loại thông báo cùng một khuôn.
 */
class AppNotification extends Notification
{
    /**
     * @param  string  $category  learning | support | events (tab của chuông)
     * @param  string  $url  đường dẫn tương đối, mở khi bấm thông báo
     */
    public function __construct(
        public string $category,
        public string $title,
        public string $body,
        public string $url,
        public string $icon = 'bell',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon,
        ];
    }
}
