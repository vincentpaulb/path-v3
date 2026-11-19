<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'region', 'proposal_code', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function activeValues(): array
    {
        return static::query()->active()->ordered()->pluck('name')->all();
    }

    public static function allValues(): array
    {
        return static::query()->ordered()->pluck('name')->all();
    }

    public static function selectionValues(?string $currentValue = null): array
    {
        return static::query()
            ->where(function (Builder $query) use ($currentValue) {
                $query->where('is_active', true);
                if (filled($currentValue)) {
                    $query->orWhere('name', $currentValue);
                }
            })
            ->ordered()
            ->pluck('name')
            ->all();
    }

    public static function isActiveValue(?string $value): bool
    {
        if (blank($value)) {
            return false;
        }

        return static::query()
            ->where('name', $value)
            ->where('is_active', true)
            ->exists();
    }
}
