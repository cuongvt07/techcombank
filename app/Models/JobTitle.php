<?php

namespace App\Models;

use App\Models\Concerns\HasAutoCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobTitle extends Model
{
    use HasAutoCode, HasFactory, SoftDeletes;

    /** Mã tự sinh khi tạo mới: CD-001, CD-002... */
    protected const CODE_COLUMN = 'code';
    protected const CODE_PREFIX = 'CD-';
    protected const CODE_PAD = 3;

    protected $fillable = [
        'code', 'name', 'department_id', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
