<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidationRecord extends Model
{
    protected $fillable = [
        'proposal_id',
        'amount_released',
        'amount_liquidated',
        'reported_unexpended_balance',
        'refunded_unexpended_amount',
        'proof_of_refund_received',
        'liquidation_report_status',
        'workflow_status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'attachments_complete',
        'status_report_submitted',
        'remarks',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_released' => 'decimal:2',
            'amount_liquidated' => 'decimal:2',
            'reported_unexpended_balance' => 'decimal:2',
            'refunded_unexpended_amount' => 'decimal:2',
            'proof_of_refund_received' => 'boolean',
            'attachments_complete' => 'boolean',
            'status_report_submitted' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }
}
