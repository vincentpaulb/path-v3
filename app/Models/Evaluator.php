<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evaluator extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'first_name', 'middle_initial', 'last_name', 'sex', 'classification', 'email',
        'contact_number', 'designation', 'organization', 'deleted_by',
    ];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    public function proposals(): BelongsToMany
    {
        return $this->belongsToMany(Proposal::class)
            ->withPivot('evaluation_status', 'evaluated_at')
            ->withTimestamps();
    }

    public function remarks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EvaluatorRemark::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'email', 'email')
            ->where('role', 'Evaluator')
            ->withTrashed();
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    public function getFullNameAttribute(): string
    {
        $middle = trim((string) $this->middle_initial);
        $middle = $middle !== '' ? rtrim($middle, '.') . '.' : '';

        return trim("{$this->first_name} {$middle} {$this->last_name}");
    }
}
