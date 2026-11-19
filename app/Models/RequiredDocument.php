<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RequiredDocument extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'deleted_by'];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }
}
