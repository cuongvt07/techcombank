<?php

namespace App\Models;

use App\Models\Concerns\HasAutoCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasAutoCode, HasFactory, SoftDeletes;

    /** Mã tự sinh khi tạo mới: PB-001, PB-002... */
    protected const CODE_COLUMN = 'code';
    protected const CODE_PREFIX = 'PB-';
    protected const CODE_PAD = 3;

    protected $fillable = [
        'code', 'name', 'parent_id', 'description', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function jobTitles(): HasMany
    {
        return $this->hasMany(JobTitle::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Trả về id của phòng ban này và toàn bộ phòng ban con (đệ quy).
     * Dùng cho các rule phân quyền/gán khoá học có bật include_sub_departments.
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->descendantIds());
        }

        return $ids;
    }

    /**
     * Đường dẫn đầy đủ trong cây tổ chức, vd "Khối Vận hành / Phòng Nhân sự".
     */
    public function getFullPathAttribute(): string
    {
        $names = [$this->name];
        $node = $this->parent;

        while ($node) {
            array_unshift($names, $node->name);
            $node = $node->parent;
        }

        return implode(' / ', $names);
    }
}
