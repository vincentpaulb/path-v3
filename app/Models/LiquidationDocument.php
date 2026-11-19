<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidationDocument extends Model
{
    protected $fillable = ['proposal_id', 'acquisition_id', 'transaction_id', 'document_type', 'name', 'path', 'mime_type', 'size', 'uploaded_by'];

    public function proposal(): BelongsTo { return $this->belongsTo(Proposal::class); }
    public function acquisition(): BelongsTo { return $this->belongsTo(ProposalAcquisition::class, 'acquisition_id'); }
    public function transaction(): BelongsTo { return $this->belongsTo(LiquidationTransaction::class, 'transaction_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by')->withTrashed(); }
}
