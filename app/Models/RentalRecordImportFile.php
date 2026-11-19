<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalRecordImportFile extends Model
{
    protected $fillable = [
        'rental_record_import_batch_id', 'proposal_id', 'original_filename',
        'project_title_snapshot', 'proponent_snapshot', 'status', 'total_rows',
        'valid_rows', 'imported_rows', 'skipped_rows', 'failed_rows', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RentalRecordImportBatch::class, 'rental_record_import_batch_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class)->withTrashed();
    }
}
