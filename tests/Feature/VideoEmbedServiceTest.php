<?php

namespace Tests\Feature;

use App\Services\VideoEmbedService;
use Tests\TestCase;

/** Kiểm chứng nhận diện link video và dựng link nhúng. */
class VideoEmbedServiceTest extends TestCase
{
    private VideoEmbedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VideoEmbedService::class);
    }

    // ---- YouTube ------------------------------------------------------------

    public function test_nhan_dien_link_youtube_thong_thuong(): void
    {
        $result = $this->service->parse('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertSame(VideoEmbedService::PROVIDER_YOUTUBE, $result['provider']);
        $this->assertSame('dQw4w9WgXcQ', $result['id']);
    }

    public function test_nhan_dien_link_rut_gon_youtu_be(): void
    {
        $result = $this->service->parse('https://youtu.be/dQw4w9WgXcQ');

        $this->assertSame('dQw4w9WgXcQ', $result['id']);
    }

    public function test_nhan_dien_link_youtube_co_tham_so_phu(): void
    {
        // Link copy từ nút Share thường có &t=, &list=
        $result = $this->service->parse('https://www.youtube.com/watch?list=PL123&v=dQw4w9WgXcQ&t=30s');

        $this->assertSame('dQw4w9WgXcQ', $result['id']);
    }

    public function test_nhan_dien_link_embed_va_shorts(): void
    {
        $embed = $this->service->parse('https://www.youtube.com/embed/dQw4w9WgXcQ');
        $shorts = $this->service->parse('https://www.youtube.com/shorts/dQw4w9WgXcQ');

        $this->assertSame('dQw4w9WgXcQ', $embed['id']);
        $this->assertSame('dQw4w9WgXcQ', $shorts['id']);
    }

    public function test_link_nhung_youtube_dung_domain_khong_cookie(): void
    {
        $url = $this->service->embedUrl(VideoEmbedService::PROVIDER_YOUTUBE, 'dQw4w9WgXcQ');

        // youtube-nocookie giảm theo dõi người học
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $url);
        // rel=0 hạn chế video gợi ý dẫn người học ra ngoài luồng đào tạo
        $this->assertStringContainsString('rel=0', $url);
    }

    public function test_lay_duoc_anh_dai_dien_youtube(): void
    {
        $thumb = $this->service->thumbnailUrl(VideoEmbedService::PROVIDER_YOUTUBE, 'dQw4w9WgXcQ');

        $this->assertStringContainsString('dQw4w9WgXcQ', $thumb);
    }

    // ---- Vimeo --------------------------------------------------------------

    public function test_nhan_dien_link_vimeo(): void
    {
        $result = $this->service->parse('https://vimeo.com/123456789');

        $this->assertSame(VideoEmbedService::PROVIDER_VIMEO, $result['provider']);
        $this->assertSame('123456789', $result['id']);
    }

    public function test_link_nhung_vimeo(): void
    {
        $url = $this->service->embedUrl(VideoEmbedService::PROVIDER_VIMEO, '123456789');

        $this->assertStringContainsString('player.vimeo.com/video/123456789', $url);
    }

    // ---- Video trực tiếp ----------------------------------------------------

    public function test_nhan_dien_file_video_truc_tiep(): void
    {
        foreach (['mp4', 'webm', 'm3u8'] as $ext) {
            $result = $this->service->parse("https://cdn.noi-bo.local/video/bai-giang.{$ext}");

            $this->assertSame(VideoEmbedService::PROVIDER_DIRECT, $result['provider'], "Định dạng {$ext}");
            $this->assertNull($result['id']);
        }
    }

    public function test_video_truc_tiep_khong_co_link_nhung(): void
    {
        // Loại này dùng thẻ <video>, không phải iframe
        $this->assertNull($this->service->embedUrl(VideoEmbedService::PROVIDER_DIRECT, null));
    }

    // ---- Trường hợp không hợp lệ --------------------------------------------

    public function test_url_khong_nhan_dien_duoc_tra_ve_null(): void
    {
        $this->assertNull($this->service->parse('https://example.com/trang-nao-do'));
        $this->assertNull($this->service->parse('không phải url'));
        $this->assertNull($this->service->parse(''));
        $this->assertNull($this->service->parse('   '));
    }

    public function test_ma_video_youtube_sai_do_dai_khong_khop(): void
    {
        // Mã YouTube luôn 11 ký tự
        $this->assertNull($this->service->parse('https://youtu.be/abc'));
    }

    public function test_nhan_dang_ten_nha_cung_cap(): void
    {
        $this->assertSame('YouTube', $this->service->providerLabel(VideoEmbedService::PROVIDER_YOUTUBE));
        $this->assertSame('Vimeo', $this->service->providerLabel(VideoEmbedService::PROVIDER_VIMEO));
        $this->assertSame('Không xác định', $this->service->providerLabel(null));
    }
}
