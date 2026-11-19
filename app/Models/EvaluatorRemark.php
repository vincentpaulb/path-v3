<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluatorRemark extends Model
{
    protected $fillable = ['proposal_id', 'evaluator_id', 'user_id', 'action', 'remark'];

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(Evaluator::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class)->withTrashed();
    }

    public function replies(): HasMany
    {
        return $this->hasMany(EvaluatorRemarkReply::class)->orderBy('created_at')->orderBy('id');
    }
}
