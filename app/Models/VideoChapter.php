<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mốc chương trong video bài giảng (spec 3.2.2). */
class VideoChapter extends Model
{
    protected $fillable = ['document_id', 'title', 'start_second', 'sort_order'];

    protected function casts(): array
    {
        return ['start_second' => 'integer', 'sort_order' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** Hiển thị mốc thời gian dạng mm:ss hoặc h:mm:ss. */
    public function getTimestampLabelAttribute(): string
    {
        $seconds = $this->start_second;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }
}
