<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch sử hội thoại với trợ lý AI (spec 4.3).
 *
 * Lưu để nhân viên xem lại và để đội đào tạo biết mọi người hay vướng ở đâu mà
 * bổ sung tài liệu. Đây là dữ liệu cá nhân nên xóa nhân viên thì xóa theo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable()->comment('Lấy từ câu hỏi đầu tiên');
            $table->timestamps();

            $table->index(['employee_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->text('content');

            // Nguồn tài liệu AI đã dùng để trả lời — cần khi rà soát xem AI có
            // trả lời vượt quyền hay không
            $table->json('sources')->nullable();

            $table->timestamps();

            $table->index(['ai_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
