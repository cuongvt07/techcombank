<?php

namespace App\Models;

use App\Models\Concerns\HasAutoCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobGrade extends Model
{
    use HasAutoCode, HasFactory, SoftDeletes;

    /** Mã tự sinh khi tạo mới: CB-01, CB-02... */
    protected const CODE_COLUMN = 'code';
    protected const CODE_PREFIX = 'CB-';
    protected const CODE_PAD = 2;

    protected $fillable = ['code', 'name', 'level', 'is_active'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_active' => 'boolean',
        ];
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
