<?php

namespace App\Models;

use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'username', 'first_name', 'middle_initial', 'last_name', 'company', 'mobile_number',
        'email', 'job_desc', 'role', 'role_id', 'province', 'avatar_path', 'password', 'deleted_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function accessRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    public function getFullNameAttribute(): string
    {
        $middle = trim((string) $this->middle_initial);
        $middle = $middle !== '' ? rtrim($middle, '.').'.' : '';

        return trim("{$this->first_name} {$middle} {$this->last_name}");
    }

    public function roleSlug(): string
    {
        $role = $this->resolvedAccessRole();

        return $role?->slug ?: strtolower((string) $this->role);
    }

    public function dataScope(): string
    {
        $role = $this->resolvedAccessRole();

        if ($role?->data_scope) {
            return $role->data_scope;
        }

        return match ($this->role) {
            'PSTO' => 'province',
            'Evaluator' => 'assigned',
            default => 'global',
        };
    }

    public function hasPermission(string $key): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        // PSTO is a province-scoped observation and collaboration role. Keep
        // record mutations blocked even if an older database still has a
        // management permission attached to the role.
        if ($this->isPsto() && in_array($key, PermissionCatalog::pstoRestrictedPermissions(), true)) {
            return false;
        }

        return $this->resolvedAccessRole()?->hasPermission($key) ?? false;
    }

    public function hasAnyPermission(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->hasPermission($key)) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'Admin' || $this->roleSlug() === 'admin';
    }

    public function isEvaluator(): bool
    {
        return $this->role === 'Evaluator' || $this->roleSlug() === 'evaluator';
    }

    public function isPsto(): bool
    {
        return $this->role === 'PSTO' || $this->roleSlug() === 'psto' || $this->dataScope() === 'province';
    }

    public function canReplyToEvaluatorRemarks(): bool
    {
        return in_array($this->roleSlug(), ['psto', 'tod'], true) && $this->hasPermission('proposals.comment');
    }

    public function homeRouteName(): string
    {
        if ($this->hasPermission('dashboard.view')) {
            return 'dashboard';
        }

        if ($this->isEvaluator() && $this->hasPermission('evaluator_portal.view')) {
            return 'evaluator.proposals.index';
        }

        $routes = [
            'proposals.view' => 'proposals.index',
            'funded.view' => 'funded.index',
            'analytics.view' => 'analytics.index',
            'monitoring.rtec.view' => 'monitoring.rtec',
            'monitoring.rental.view' => 'monitoring.rental',
            'monitoring.refund_rate.view' => 'monitoring.refund-rate',
            'monitoring.liquidation.view' => 'monitoring.liquidation',
            'provinces.view' => 'provinces.index',
            'required_documents.view' => 'required-documents.index',
            'industry_sectors.view' => 'industry-sectors.index',
            'evaluators.view' => 'evaluators.index',
            'users.view' => 'users.index',
            'archives.view' => 'archives.index',
        ];

        foreach ($routes as $permission => $route) {
            if ($this->hasPermission($permission)) {
                return $route;
            }
        }

        return 'profile.edit';
    }

    private function resolvedAccessRole(): ?Role
    {
        if ($this->relationLoaded('accessRole')) {
            $role = $this->getRelation('accessRole');
            if ($role && ! $role->relationLoaded('permissions')) {
                $role->load('permissions');
            }

            return $role;
        }

        if ($this->role_id) {
            $role = $this->accessRole()->with('permissions')->first();
            $this->setRelation('accessRole', $role);

            return $role;
        }

        // Backward-compatible fallback for a database that has legacy role text
        // but has not yet been synchronized to role_id.
        $role = Role::query()->where('name', $this->role)->with('permissions')->first();
        if ($role) {
            $this->setRelation('accessRole', $role);
        }

        return $role;
    }
}
