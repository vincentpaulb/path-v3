<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiquidationTransaction extends Model
{
    protected $fillable = [
        'proposal_id', 'budget_line_id', 'acquisition_id', 'transaction_date', 'entry_number', 'reference_type',
        'reference_number', 'payee', 'particulars', 'transaction_type', 'obligation_amount', 'disbursement_amount',
        'adjustment_amount', 'refund_amount', 'funding_source', 'fund_account', 'status', 'remarks', 'encoded_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date', 'obligation_amount' => 'decimal:2', 'disbursement_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2', 'refund_amount' => 'decimal:2',
        ];
    }

    public function proposal(): BelongsTo { return $this->belongsTo(Proposal::class); }
    public function budgetLine(): BelongsTo { return $this->belongsTo(ProposalBudgetLine::class, 'budget_line_id'); }
    public function acquisition(): BelongsTo { return $this->belongsTo(ProposalAcquisition::class, 'acquisition_id'); }
    public function encoder(): BelongsTo { return $this->belongsTo(User::class, 'encoded_by')->withTrashed(); }
    public function documents(): HasMany { return $this->hasMany(LiquidationDocument::class, 'transaction_id'); }
}
