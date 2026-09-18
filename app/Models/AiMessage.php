<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lượt hỏi hoặc đáp trong hội thoại AI. */
class AiMessage extends Model
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = ['ai_conversation_id', 'role', 'content', 'sources'];

    protected function casts(): array
    {
        return ['sources' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }
}
