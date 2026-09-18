<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractType extends Model
{
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
