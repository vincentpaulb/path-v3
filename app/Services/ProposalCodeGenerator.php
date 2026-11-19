<?php

namespace App\Services;

use App\Models\Proposal;
use App\Models\Province;
use Illuminate\Support\Facades\DB;

class ProposalCodeGenerator
{
    public function generateIfReady(Proposal $proposal): ?string
    {
        if ($proposal->proposal_code || !$proposal->date_approved || !$proposal->industry_sector_id || (!$proposal->isFunded() && !in_array($proposal->status, ['Approved', 'Funded'], true))) {
            return $proposal->proposal_code;
        }

        return DB::transaction(function () use ($proposal) {
            $lockedProposal = Proposal::query()->with('industrySector')->lockForUpdate()->findOrFail($proposal->id);
            if ($lockedProposal->proposal_code || !$lockedProposal->date_approved || !$lockedProposal->industrySector || (!$lockedProposal->isFunded() && !in_array($lockedProposal->status, ['Approved', 'Funded'], true))) {
                return $lockedProposal->proposal_code;
            }

            $provinceCode = $this->provinceCode($lockedProposal->province);
            if (!$provinceCode) {
                return null;
            }

            $approvalYear = (int) $lockedProposal->date_approved->year;
            $regionCode = (string) config('proposal_codes.region_code', '07');
            $now = now();

            DB::table('proposal_code_sequences')->insertOrIgnore([
                'approval_year' => $approvalYear,
                'region_code' => $regionCode,
                'next_number' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $counter = DB::table('proposal_code_sequences')
                ->where('approval_year', $approvalYear)
                ->where('region_code', $regionCode)
                ->lockForUpdate()
                ->first();

            $sequence = (int) $counter->next_number;
            DB::table('proposal_code_sequences')->where('id', $counter->id)->update([
                'next_number' => $sequence + 1,
                'updated_at' => $now,
            ]);

            $width = max(2, (int) config('proposal_codes.sequence_width', 4));
            $code = implode('-', [
                $lockedProposal->date_approved->format('y'),
                config('proposal_codes.agency_code', '1824'),
                str_pad((string) $sequence, $width, '0', STR_PAD_LEFT),
                $regionCode,
                $lockedProposal->industrySector->code,
                config('proposal_codes.intervention_code', '01'),
                $provinceCode,
            ]);

            $lockedProposal->forceFill([
                'proposal_code' => $code,
                'approved_sequence' => $sequence,
                'code_generated_at' => $now,
            ])->saveQuietly();

            $proposal->forceFill($lockedProposal->only(['proposal_code', 'approved_sequence', 'code_generated_at']));

            return $code;
        }, 3);
    }

    private function provinceCode(string $province): ?string
    {
        $normalized = strtolower(trim($province));
        $query = Province::query();
        if (in_array($normalized, ['negros', 'negros oriental'], true)) {
            $query->whereIn('name', ['Negros', 'Negros Oriental']);
        } else {
            $query->whereRaw('LOWER(TRIM(name)) = ?', [$normalized]);
        }

        return $query->whereNotNull('proposal_code')->value('proposal_code');
    }
}
