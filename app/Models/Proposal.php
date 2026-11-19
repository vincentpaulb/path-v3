<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proposal extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'funded_project_status', 'owner_contact_sex', 'project_code', 'proposal_code', 'approved_sequence', 'code_generated_at', 'project_title', 'proponent', 'address', 'latitude', 'longitude', 'location_source', 'geocoded_at', 'contact_person', 'contact_phone', 'contact_email', 'province', 'sector', 'industry_sector_id', 'amount', 'proposal_link',
        'status', 'document_status', 'funding_status', 'moa_status', 'moa_status_updated_at',
        'check_status', 'check_status_updated_at', 'quarter_submitted', 'date_submitted',
        'date_updated', 'quarter_approved', 'quarter_funded', 'date_approved', 'date_funded', 'rental_otp_enabled', 'user_id', 'deleted_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $proposal) {
            if ($proposal->date_funded) {
                $proposal->funded_project_status = $proposal->funded_project_status
                    ?: (in_array($proposal->status, \App\Enums\FundedProjectStatus::values(), true) ? $proposal->status : 'New');
                $proposal->status = 'Approved';
            } else {
                $proposal->funded_project_status = null;
                if (!in_array($proposal->status, \App\Enums\ProposalStatus::values(), true)) {
                    $proposal->status = $proposal->status === 'Funded' ? 'Approved' : 'Ongoing Evaluation';
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geocoded_at' => 'datetime',
            'date_submitted' => 'date',
            'date_updated' => 'date',
            'date_approved' => 'date',
            'date_funded' => 'date',
            'code_generated_at' => 'datetime',
            'rental_otp_enabled' => 'boolean',
            'moa_status_updated_at' => 'datetime',
            'check_status_updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function scopeFunded($query)
    {
        return $query->whereNotNull('date_funded');
    }

    public function isFunded(): bool
    {
        return $this->date_funded !== null;
    }

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'user_id')->withTrashed(); }
    public function industrySector(): BelongsTo { return $this->belongsTo(IndustrySector::class); }
    public function publicProfile(): HasOne { return $this->hasOne(PublicProjectProfile::class); }
    public function deletedBy(): BelongsTo { return $this->belongsTo(User::class, 'deleted_by')->withTrashed(); }

    public function evaluators(): BelongsToMany
    {
        return $this->belongsToMany(Evaluator::class)
            ->withPivot('evaluation_status', 'evaluated_at')
            ->withTimestamps();
    }

    public function evaluatorRemarks(): HasMany { return $this->hasMany(EvaluatorRemark::class); }
    public function missingDocuments(): HasMany { return $this->hasMany(MissingDocument::class); }
    public function documentStatuses(): HasMany { return $this->hasMany(ProposalDocumentStatus::class); }
    public function comments(): HasMany { return $this->hasMany(Comment::class); }
    public function activities(): HasMany { return $this->hasMany(ActivityLog::class); }
    public function files(): HasMany { return $this->hasMany(ProposalFile::class); }
    public function rentalRecords(): HasMany { return $this->hasMany(RentalRecord::class); }
    public function rentalScheduleAdjustments(): HasMany { return $this->hasMany(RentalScheduleAdjustment::class); }
    public function budgetLines(): HasMany { return $this->hasMany(ProposalBudgetLine::class); }
    public function acquisitions(): HasMany { return $this->hasMany(ProposalAcquisition::class); }
    public function liquidationTransactions(): HasMany { return $this->hasMany(LiquidationTransaction::class); }
    public function liquidationDocuments(): HasMany { return $this->hasMany(LiquidationDocument::class); }
    public function liquidationFindings(): HasMany { return $this->hasMany(LiquidationFinding::class); }
    public function liquidationRecord(): HasOne { return $this->hasOne(LiquidationRecord::class); }

    public function scopeVisibleTo($query, User $user)
    {
        if ($user->dataScope() === 'province') {
            return $query->where('province', $user->province);
        }

        if ($user->isEvaluator()) {
            return $query->whereHas('evaluators', fn ($evaluatorQuery) =>
                $evaluatorQuery->where('evaluators.email', $user->email)
            );
        }

        return $query;
    }
}
