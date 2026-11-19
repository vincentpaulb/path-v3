<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalScheduleAdjustment extends Model
{
    protected $fillable = [
        'mode', 'unexpended_amount', 'repayment_option',
        'proposal_id',
        'adjustment_type',
        'effective_month',
        'resume_month',
        'suspension_months',
        'approved_date',
        'outstanding_balance',
        'previous_amount_due',
        'revised_amount_due',
        'previous_remaining_installments',
        'revised_remaining_installments',
        'revised_final_payment_month',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'unexpended_amount' => 'decimal:2',
            'effective_month' => 'date',
            'resume_month' => 'date',
            'approved_date' => 'date',
            'outstanding_balance' => 'decimal:2',
            'previous_amount_due' => 'decimal:2',
            'revised_amount_due' => 'decimal:2',
            'previous_remaining_installments' => 'integer',
            'revised_remaining_installments' => 'integer',
            'revised_final_payment_month' => 'date',
            'suspension_months' => 'integer',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
