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
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'approved_unit_cost' => 'decimal:2', 'approved_amount' => 'decimal:2'];
    }

    public function proposal(): BelongsTo { return $this->belongsTo(Proposal::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
    public function acquisitions(): HasMany { return $this->hasMany(ProposalAcquisition::class, 'budget_line_id'); }
    public function transactions(): HasMany { return $this->hasMany(LiquidationTransaction::class, 'budget_line_id'); }
}
