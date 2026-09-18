<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Một file trong kho tài nguyên.
 *
 * Bản ghi này là tầng lưu trữ dùng chung — tài liệu đào tạo, hợp đồng, ảnh
 * đều trỏ vào đây thay vì mỗi nơi tự giữ một bản sao.
 */
class StoredFile extends Model
{
    use HasFactory, SoftDeletes;

    /** Nhóm loại file để lọc và chọn icon hiển thị. */
    public const KIND_DOCUMENT = 'document';
    public const KIND_SPREADSHEET = 'spreadsheet';
    public const KIND_PRESENTATION = 'presentation';
    public const KIND_PDF = 'pdf';
    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KIND_AUDIO = 'audio';
    public const KIND_ARCHIVE = 'archive';
    public const KIND_OTHER = 'other';

    protected $fillable = [
        'folder_id', 'name', 'original_name', 'disk', 'path',
        'mime_type', 'extension', 'size_bytes', 'checksum', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        // Xoá file vật lý khi bản ghi bị xoá vĩnh viễn.
        // Chỉ làm ở forceDelete: soft delete vẫn phải khôi phục được.
        static::forceDeleted(function (self $file) {
            if ($file->path && Storage::disk($file->disk)->exists($file->path)) {
                Storage::disk($file->disk)->delete($file->path);
            }
        });
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FileFolder::class, 'folder_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeInFolder(Builder $query, ?int $folderId): Builder
    {
        return $folderId === null
            ? $query->whereNull('folder_id')
            : $query->where('folder_id', $folderId);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where(function (Builder $q) use ($kind) {
            foreach (self::mimePrefixesFor($kind) as $prefix) {
                $q->orWhere('mime_type', 'like', $prefix . '%');
            }

            foreach (self::extensionsFor($kind) as $ext) {
                $q->orWhere('extension', $ext);
            }
        });
    }

    public function kind(): string
    {
        $mime = (string) $this->mime_type;
        $ext = strtolower((string) $this->extension);

        return match (true) {
            str_starts_with($mime, 'image/') => self::KIND_IMAGE,
            str_starts_with($mime, 'video/') => self::KIND_VIDEO,
            str_starts_with($mime, 'audio/') => self::KIND_AUDIO,
            $mime === 'application/pdf' || $ext === 'pdf' => self::KIND_PDF,
            in_array($ext, ['doc', 'docx', 'odt', 'rtf'], true) => self::KIND_DOCUMENT,
            in_array($ext, ['xls', 'xlsx', 'ods', 'csv'], true) => self::KIND_SPREADSHEET,
            in_array($ext, ['ppt', 'pptx', 'odp'], true) => self::KIND_PRESENTATION,
            in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'], true) => self::KIND_ARCHIVE,
            default => self::KIND_OTHER,
        };
    }

    /** Icon Heroicon tương ứng loại file. */
    public function icon(): string
    {
        return match ($this->kind()) {
            self::KIND_IMAGE => 'heroicon-o-photo',
            self::KIND_VIDEO => 'heroicon-o-film',
            self::KIND_AUDIO => 'heroicon-o-musical-note',
            self::KIND_PDF => 'heroicon-o-document-text',
            self::KIND_SPREADSHEET => 'heroicon-o-table-cells',
            self::KIND_PRESENTATION => 'heroicon-o-presentation-chart-bar',
            self::KIND_ARCHIVE => 'heroicon-o-archive-box',
            self::KIND_DOCUMENT => 'heroicon-o-document',
            default => 'heroicon-o-paper-clip',
        };
    }

    /** Dung lượng ở đơn vị người đọc được. */
    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 1) . ' GB',
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024 => round($bytes / 1024) . ' KB',
            default => $bytes . ' B',
        };
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /** @return array<int, string> */
    private static function mimePrefixesFor(string $kind): array
    {
        return match ($kind) {
            self::KIND_IMAGE => ['image/'],
            self::KIND_VIDEO => ['video/'],
            self::KIND_AUDIO => ['audio/'],
            self::KIND_PDF => ['application/pdf'],
            default => [],
        };
    }

    /** @return array<int, string> */
    private static function extensionsFor(string $kind): array
    {
        return match ($kind) {
            self::KIND_DOCUMENT => ['doc', 'docx', 'odt', 'rtf'],
            self::KIND_SPREADSHEET => ['xls', 'xlsx', 'ods', 'csv'],
            self::KIND_PRESENTATION => ['ppt', 'pptx', 'odp'],
            self::KIND_PDF => ['pdf'],
            self::KIND_ARCHIVE => ['zip', 'rar', '7z', 'tar', 'gz'],
            default => [],
        };
    }
}
