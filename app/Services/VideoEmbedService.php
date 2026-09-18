<?php

namespace App\Services;

/**
 * Nhận diện nhà cung cấp video từ URL và dựng link nhúng.
 *
 * Người dùng chỉ cần dán link như bình thường (chép từ thanh địa chỉ, nút Share,
 * hay link rút gọn) — hệ thống tự tách mã video. Bắt họ tự tìm "embed URL" là
 * việc không ai muốn làm và dễ dán nhầm.
 */
class VideoEmbedService
{
    public const PROVIDER_YOUTUBE = 'youtube';
    public const PROVIDER_VIMEO = 'vimeo';
    public const PROVIDER_DIRECT = 'direct';

    /**
     * Phân tích URL thành nhà cung cấp + mã video.
     *
     * @return array{provider: string, id: ?string}|null null nếu URL không nhận diện được
     */
    public function parse(string $url): ?array
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if ($id = $this->youtubeId($url)) {
            return ['provider' => self::PROVIDER_YOUTUBE, 'id' => $id];
        }

        if ($id = $this->vimeoId($url)) {
            return ['provider' => self::PROVIDER_VIMEO, 'id' => $id];
        }

        // File video đặt trên hạ tầng riêng: phát thẳng bằng thẻ <video>
        if (preg_match('/\.(mp4|webm|ogg|m3u8)(\?.*)?$/i', $url)) {
            return ['provider' => self::PROVIDER_DIRECT, 'id' => null];
        }

        return null;
    }

    /**
     * Link để nhúng vào iframe.
     * Trả null với video phát trực tiếp — loại đó dùng thẻ <video>, không phải iframe.
     */
    public function embedUrl(?string $provider, ?string $embedId, ?string $originalUrl = null): ?string
    {
        return match ($provider) {
            // rel=0 hạn chế video gợi ý của kênh khác hiện lên sau khi xem xong;
            // modestbranding giảm logo — nội dung đào tạo nội bộ không nên
            // dẫn người học sang nội dung ngoài luồng
            self::PROVIDER_YOUTUBE => $embedId
                ? "https://www.youtube-nocookie.com/embed/{$embedId}?rel=0&modestbranding=1"
                : null,

            self::PROVIDER_VIMEO => $embedId
                ? "https://player.vimeo.com/video/{$embedId}?dnt=1"
                : null,

            default => null,
        };
    }

    public function providerLabel(?string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_YOUTUBE => 'YouTube',
            self::PROVIDER_VIMEO => 'Vimeo',
            self::PROVIDER_DIRECT => 'Link trực tiếp',
            default => 'Không xác định',
        };
    }

    /** Ảnh đại diện video, dùng cho danh sách khi chưa có ảnh riêng. */
    public function thumbnailUrl(?string $provider, ?string $embedId): ?string
    {
        return match ($provider) {
            self::PROVIDER_YOUTUBE => $embedId
                ? "https://i.ytimg.com/vi/{$embedId}/hqdefault.jpg"
                : null,
            default => null,
        };
    }

    /**
     * Tách mã video YouTube. Nhận cả bốn dạng link người dùng hay dán:
     * watch?v=, youtu.be/, /embed/, /shorts/
     */
    private function youtubeId(string $url): ?string
    {
        $patterns = [
            '~youtube\.com/watch\?(?:.*&)?v=([\w-]{11})~i',
            '~youtu\.be/([\w-]{11})~i',
            '~youtube(?:-nocookie)?\.com/embed/([\w-]{11})~i',
            '~youtube\.com/shorts/([\w-]{11})~i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function vimeoId(string $url): ?string
    {
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
