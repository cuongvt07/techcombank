<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video bài giảng nhúng từ nguồn ngoài thay vì tải lên hệ thống.
 *
 * Lý do: giới hạn upload là 2MB, không đủ cho video. Nhúng từ dịch vụ chuyên
 * dụng (YouTube/Vimeo nội bộ, hoặc hạ tầng stream riêng của ngân hàng) còn
 * giải quyết luôn việc chuyển mã nhiều độ phân giải và băng thông.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('video_url', 500)->nullable()->after('duration_seconds')
                ->comment('Link video gốc do người dùng dán vào');
            $table->string('video_provider', 30)->nullable()->after('video_url')
                ->comment('youtube | vimeo | direct — nhận diện từ URL');
            $table->string('video_embed_id', 100)->nullable()->after('video_provider')
                ->comment('Mã video tách từ URL, dùng dựng link nhúng');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['video_url', 'video_provider', 'video_embed_id']);
        });
    }
};
