<?php

namespace App\Models;

use App\Models\Concerns\HasAutoCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentCategory extends Model
{
    use HasAutoCode;

    /** Mã tự sinh khi tạo mới: DM-001, DM-002... */
    protected const CODE_COLUMN = 'code';
    protected const CODE_PREFIX = 'DM-';
    protected const CODE_PAD = 3;

    protected $fillable = ['code', 'name', 'parent_id', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function accessRules(): HasMany
    {
        return $this->hasMany(DocumentAccessRule::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function descendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->descendantIds());
        }

        return $ids;
    }
}
