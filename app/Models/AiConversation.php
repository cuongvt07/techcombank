<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một phiên hội thoại với trợ lý AI (spec 4.3). */
class AiConversation extends Model
{
    protected $fillable = ['employee_id', 'title'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }
}
