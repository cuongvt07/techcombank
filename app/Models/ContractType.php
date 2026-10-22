<?php

namespace App\Models;

use App\Models\Concerns\HasAutoCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractType extends Model
{
    use HasAutoCode;

    /** Mã tự sinh khi tạo mới: LHD-01, LHD-02... */
    protected const CODE_COLUMN = 'code';
    protected const CODE_PREFIX = 'LHD-';
    protected const CODE_PAD = 2;

    protected $fillable = ['code', 'name', 'default_duration_months', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_duration_months' => 'integer',
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
