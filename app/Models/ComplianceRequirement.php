<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ComplianceRequirement extends Model
{
    public const STATUSES = ['pending', 'under_review', 'approved', 'rejected'];

    protected $fillable = [
        'status', 'responsible_office', 'reviewed_at', 'approved_at', 'remarks', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'date', 'approved_at' => 'date'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ComplianceEvidence::class);
    }

    public function latestEvidence(): HasOne
    {
        return $this->hasOne(ComplianceEvidence::class)->latestOfMany();
    }

    public function history(): HasMany
    {
        return $this->hasMany(ComplianceRequirementHistory::class)->latest('created_at');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('title');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'under_review' => 'Under Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => 'Pending',
        };
    }
}
