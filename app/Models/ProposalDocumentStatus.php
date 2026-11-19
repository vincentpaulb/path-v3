<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalDocumentStatus extends Model
{
    public const SUBMITTED = 'Submitted';
    public const LACKING = 'Lacking';
    public const NOT_APPLICABLE = 'Not Applicable';
    public const PENDING = 'Pending';

    protected $fillable = [
        'proposal_id', 'required_document_id', 'status', 'source',
    ];

    public static function values(): array
    {
        return [self::SUBMITTED, self::LACKING, self::NOT_APPLICABLE, self::PENDING];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class)->withTrashed();
    }

    public function requiredDocument(): BelongsTo
    {
        return $this->belongsTo(RequiredDocument::class)->withTrashed();
    }
}
