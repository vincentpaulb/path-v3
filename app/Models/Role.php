<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'data_scope', 'is_system', 'is_protected',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_protected' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    public function hasPermission(string $key): bool
    {
        if ($this->slug === 'admin') {
            return true;
        }

        if (! $this->relationLoaded('permissions')) {
            $this->load('permissions');
        }

        return $this->permissions->contains('key', $key);
    }
}
