<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidationFinding extends Model
{
    protected $fillable = ['proposal_id', 'acquisition_id', 'transaction_id', 'finding', 'status', 'resolution_notes', 'raised_by', 'resolved_by', 'resolved_at'];

    protected function casts(): array { return ['resolved_at' => 'datetime']; }

    public function proposal(): BelongsTo { return $this->belongsTo(Proposal::class); }
    public function acquisition(): BelongsTo { return $this->belongsTo(ProposalAcquisition::class, 'acquisition_id'); }
    public function transaction(): BelongsTo { return $this->belongsTo(LiquidationTransaction::class, 'transaction_id'); }
    public function raisedBy(): BelongsTo { return $this->belongsTo(User::class, 'raised_by')->withTrashed(); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by')->withTrashed(); }
}
