<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Thư mục trong kho tài nguyên.
 *
 * Dùng materialized path (cột `path` dạng "/1/5/12/") để lấy cả nhánh con bằng
 * một câu LIKE thay vì đệ quy — cây thư mục đọc nhiều hơn ghi rất nhiều.
 */
class FileFolder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'parent_id', 'path', 'depth',
        'owner_department_id', 'description', 'is_system', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Tự tính path/depth từ thư mục cha, không để nơi gọi tự nhớ
        static::saving(function (self $folder) {
            $parent = $folder->parent_id ? self::find($folder->parent_id) : null;

            $folder->depth = $parent ? $parent->depth + 1 : 0;
            $folder->path = $parent ? $parent->path . $parent->id . '/' : '/';
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'folder_id');
    }

    public function ownerDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'owner_department_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /** Toàn bộ thư mục con ở mọi cấp — dùng path nên chỉ một truy vấn. */
    public function descendants(): Builder
    {
        return self::where('path', 'like', $this->path . $this->id . '/%');
    }

    /**
     * Chuỗi thư mục từ gốc đến đây, dùng dựng breadcrumb.
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $ids = collect(explode('/', trim($this->path, '/')))
            ->filter()
            ->map(fn ($id) => (int) $id);

        if ($ids->isEmpty()) {
            return collect();
        }

        // Giữ đúng thứ tự từ gốc xuống, không phụ thuộc thứ tự trả về của CSDL
        return self::whereIn('id', $ids)
            ->get()
            ->sortBy(fn ($folder) => $ids->search($folder->id))
            ->values();
    }

    public function fullPath(): string
    {
        return $this->ancestors()->push($this)->pluck('name')->join(' / ');
    }

    /** Tổng dung lượng file trong thư mục này và mọi thư mục con. */
    public function totalSize(): int
    {
        $folderIds = $this->descendants()->pluck('id')->push($this->id);

        return (int) StoredFile::whereIn('folder_id', $folderIds)->sum('size_bytes');
    }

    /**
     * Không cho di chuyển thư mục vào chính nhánh con của nó — sẽ tạo vòng lặp
     * và cắt rời cả nhánh khỏi cây.
     */
    public function canMoveTo(?int $targetFolderId): bool
    {
        if ($targetFolderId === null) {
            return true;
        }

        if ($targetFolderId === $this->id) {
            return false;
        }

        $target = self::find($targetFolderId);

        if (! $target) {
            return false;
        }

        return ! str_starts_with($target->path, $this->path . $this->id . '/');
    }
}
