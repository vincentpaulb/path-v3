<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalImportBatch extends Model
{
    protected $fillable = [
        'batch_ref', 'original_filename', 'status', 'total_rows', 'valid_rows',
        'imported_rows', 'skipped_rows', 'failed_rows', 'metadata', 'created_by',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
