<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phụ đề video (spec 3.2.2). */
class VideoSubtitle extends Model
{
    protected $fillable = ['document_id', 'locale', 'label', 'path', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
