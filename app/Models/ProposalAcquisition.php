<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalAcquisition extends Model
{
    protected $fillable = [
        'proposal_id', 'budget_line_id', 'supplier_name', 'actual_item_name', 'specifications_liquidated',
        'brand', 'model', 'serial_number', 'quantity', 'unit', 'unit_cost', 'realigned_amount', 'actual_cost',
        'date_acquired', 'date_liquidated', 'purchase_date', 'delivery_date', 'inspection_date', 'installation_date',
        'po_number', 'invoice_number', 'property_number', 'location', 'status', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'realigned_amount' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'date_acquired' => 'date',
            'date_liquidated' => 'date',
            'purchase_date' => 'date',
            'delivery_date' => 'date',
            'inspection_date' => 'date',
            'installation_date' => 'date',
        ];
    }

    public function proposal(): BelongsTo { return $this->belongsTo(Proposal::class); }
    public function budgetLine(): BelongsTo { return $this->belongsTo(ProposalBudgetLine::class, 'budget_line_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
    public function transactions(): HasMany { return $this->hasMany(LiquidationTransaction::class, 'acquisition_id'); }
    public function documents(): HasMany { return $this->hasMany(LiquidationDocument::class, 'acquisition_id'); }
}
