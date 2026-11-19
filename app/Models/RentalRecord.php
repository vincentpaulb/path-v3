<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalRecord extends Model
{
    protected $fillable = [
        'proposal_id',
        'installment_no',
        'due_month',
        'amount_due',
        'rental_payment',
        'or_number',
        'or_date',
        'amount_in_or',
        'remarks',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'due_month' => 'date',
            'or_date' => 'date',
            'amount_due' => 'decimal:2',
            'rental_payment' => 'decimal:2',
            'amount_in_or' => 'decimal:2',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }
}
