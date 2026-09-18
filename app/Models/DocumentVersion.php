<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một phiên bản của tài liệu, phục vụ versioning + rollback (spec 3.2.1). */
class DocumentVersion extends Model
{
    protected $fillable = [
        'document_id', 'stored_file_id', 'version_no', 'change_note',
        'original_filename', 'mime_type', 'size_bytes', 'checksum', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** File thật trong kho tài nguyên. */
    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCurrent(): bool
    {
        return $this->document?->current_version_id === $this->id;
    }
}
