<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalBudgetLine extends Model
{
    protected $fillable = [
        'proposal_id', 'budget_code', 'category', 'item_name', 'description', 'quantity', 'unit',
        'approved_unit_cost', 'approved_amount', 'funding_source', 'fund_account', 'sort_order', 'created_by',
        'realigned_amount', 'added_by_realignment', 'removed_by_realignment', 'realignment_source_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'approved_unit_cost' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'realigned_amount' => 'decimal:2',
            'added_by_realignment' => 'boolean',
            'removed_by_realignment' => 'boolean',
        ];
    }

    public function currentApprovedAmount(): float
    {
        return $this->removed_by_realignment ? 0.0 : (float) ($this->realigned_amount ?? $this->approved_amount);
    }

    public function canRealign(bool $hasAcquisition): bool
    {
        return ! $this->added_by_realignment && ! $this->removed_by_realignment && ! $hasAcquisition;
    }

    public function realignmentStatus(): ?string
    {
        return match (true) {
            $this->removed_by_realignment => 'Removed by realignment',
            $this->added_by_realignment => 'Added by realignment',
            $this->realigned_amount !== null => 'Revised amount',
            default => null,
        };
    }

    public function proposal(): BelongsTo { return $this->belongsTo(Proposal::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
    public function acquisitions(): HasMany { return $this->hasMany(ProposalAcquisition::class, 'budget_line_id'); }
    public function transactions(): HasMany { return $this->hasMany(LiquidationTransaction::class, 'budget_line_id'); }
}
