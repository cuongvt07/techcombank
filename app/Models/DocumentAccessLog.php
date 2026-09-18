<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Log truy cập tài liệu (spec 3.3.2: ai xem, lúc nào, thiết bị/IP nào). */
class DocumentAccessLog extends Model
{
    public const ACTION_VIEW = 'view';
    public const ACTION_DOWNLOAD = 'download';
    public const ACTION_PRINT = 'print';
    public const ACTION_STREAM = 'stream';
    public const ACTION_DENIED = 'denied';

    protected $fillable = [
        'document_id', 'user_id', 'employee_id', 'action', 'ip_address',
        'user_agent', 'watermark_token', 'duration_seconds', 'deny_reason', 'accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'accessed_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
