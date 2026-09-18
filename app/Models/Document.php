<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Học liệu trong thư viện nội bộ: file (spec 3.2.1) hoặc video (spec 3.2.2).
 *
 * File thật nằm trong kho tài nguyên chung — mỗi phiên bản (document_versions)
 * trỏ tới một bản ghi stored_files. Cấp độ bảo mật quyết định chính sách
 * hiển thị/tải xuống/watermark (spec 3.3.2).
 */
class Document extends Model
{
    use HasFactory, SoftDeletes;

    public const KIND_FILE = 'file';
    public const KIND_VIDEO = 'video';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    /** Cấp độ bảo mật, xếp từ thấp đến cao (spec 3.3.2). */
    public const CONF_PUBLIC = 'public';
    public const CONF_INTERNAL = 'internal';
    public const CONF_CONFIDENTIAL = 'confidential';
    public const CONF_RESTRICTED = 'restricted';

    protected $fillable = [
        'title', 'slug', 'description', 'kind', 'document_category_id',
        'owner_department_id', 'confidentiality', 'allow_download',
        'enable_watermark', 'status', 'published_at', 'published_by',
        'current_version_id', 'duration_seconds', 'created_by',
        'video_url', 'video_provider', 'video_embed_id',
    ];

    protected function casts(): array
    {
        return [
            'allow_download' => 'boolean',
            'enable_watermark' => 'boolean',
            'published_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    public function ownerDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'owner_department_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /** Phần mở rộng của file đang dùng, chữ thường. */
    public function fileExtension(): ?string
    {
        $ext = $this->currentVersion?->storedFile?->extension;

        return $ext ? mb_strtolower($ext) : null;
    }

    /**
     * File PDF xem thẳng được trong trang.
     *
     * Trình duyệt có sẵn trình đọc PDF nên chỉ cần nhúng iframe, không phải cài
     * thư viện ngoài — quan trọng với mạng nội bộ ngân hàng vốn hay chặn CDN.
     */
    public function isPdf(): bool
    {
        return $this->fileExtension() === 'pdf'
            || $this->currentVersion?->storedFile?->mime_type === 'application/pdf';
    }

    /**
     * File Word.
     *
     * Trình duyệt KHÔNG đọc được DOCX. Muốn xem thẳng trong trang thì phải qua
     * dịch vụ chuyển đổi bên ngoài (Office Online, Google Docs) — nghĩa là đẩy
     * tài liệu nội bộ ra Internet, không chấp nhận được với tài liệu ngân hàng.
     * Vì vậy DOCX chỉ mở bằng ứng dụng trên máy, đúng như tầng quyền cho phép.
     */
    public function isWord(): bool
    {
        return in_array($this->fileExtension(), ['doc', 'docx'], true);
    }

    /** Xem trực tiếp trong trang được hay không. */
    public function isInlineViewable(): bool
    {
        return $this->isPdf();
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(VideoChapter::class)->orderBy('start_second');
    }

    public function subtitles(): HasMany
    {
        return $this->hasMany(VideoSubtitle::class);
    }

    public function accessRules(): HasMany
    {
        return $this->hasMany(DocumentAccessRule::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(DocumentAccessLog::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeVideos(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_VIDEO);
    }

    public function scopeFiles(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_FILE);
    }

    public function isVideo(): bool
    {
        return $this->kind === self::KIND_VIDEO;
    }

    /** Link nhúng iframe cho video từ YouTube/Vimeo. */
    public function embedUrl(): ?string
    {
        return app(\App\Services\VideoEmbedService::class)
            ->embedUrl($this->video_provider, $this->video_embed_id, $this->video_url);
    }

    /** Video phát bằng thẻ <video> thay vì iframe (file trên hạ tầng riêng). */
    public function isDirectVideo(): bool
    {
        return $this->video_provider === \App\Services\VideoEmbedService::PROVIDER_DIRECT;
    }

    /**
     * Video đã sẵn sàng phát chưa.
     * Video nhúng chỉ cần có link, không cần file trong kho.
     */
    public function hasPlayableVideo(): bool
    {
        return $this->isVideo() && filled($this->video_url);
    }

    /**
     * Tài liệu nhạy cảm: buộc xem online có watermark, không phát hành bản gốc.
     * Dùng để quyết định route xem (stream có watermark) thay vì link tải trực tiếp.
     */
    public function isSensitive(): bool
    {
        return in_array($this->confidentiality, [self::CONF_CONFIDENTIAL, self::CONF_RESTRICTED], true);
    }

    /** Tạo phiên bản mới và trỏ current_version_id sang bản vừa tạo (spec 3.2.1). */
    public function newVersion(array $attributes, ?int $userId = null): DocumentVersion
    {
        $version = $this->versions()->create(array_merge($attributes, [
            'version_no' => ($this->versions()->max('version_no') ?? 0) + 1,
            'created_by' => $userId,
        ]));

        $this->forceFill(['current_version_id' => $version->id])->save();

        return $version;
    }

    /** Rollback về một phiên bản cũ mà không xoá lịch sử (spec 3.2.1). */
    public function rollbackTo(DocumentVersion $version): void
    {
        if ($version->document_id !== $this->id) {
            throw new \InvalidArgumentException('Phiên bản không thuộc tài liệu này.');
        }

        $this->forceFill(['current_version_id' => $version->id])->save();
    }
}
