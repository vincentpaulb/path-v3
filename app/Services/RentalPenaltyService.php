<?php

namespace App\Services;

use App\Models\Proposal;
use App\Models\RentalRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RentalPenaltyService
{
    public function calculate(Proposal $proposal, RentalRecord $record, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= now();
        $eligible = $proposal->date_funded && $proposal->date_funded->year <= 2018;
        // Charge each unpaid installment once at its own month-end. Never age
        // an old installment through today or repeatedly charge earlier arrears.
        $due = $record->due_month?->copy()->endOfMonth()->startOfDay();
        $months = $eligible && $due && $asOf->copy()->startOfDay()->gte($due) ? 1 : 0;
        $dueCents = max(0, (int) round((float) $record->amount_due * 100));
        $paidCents = (int) round((float) $record->rental_payment * 100);
        // Payment determines whether the installment is unpaid, not the penalty base.
        $penalty = $paidCents < $dueCents ? round($dueCents * 0.02 * $months) / 100 : 0;
        return ['months' => $months, 'amount' => $penalty];
    }

    public function schedule(Proposal $proposal, Collection $records, ?CarbonInterface $asOf = null): Collection
    {
        $runningCents = 0;
        $unpaidMonths = 0;
        return $records->sortBy('due_month')->mapWithKeys(function (RentalRecord $record) use ($proposal, $asOf, &$runningCents, &$unpaidMonths) {
            $charge = $this->calculate($proposal, $record, $asOf);
            $runningCents += (int) round($charge['amount'] * 100);
            if ($charge['amount'] > 0) $unpaidMonths++;
            return [$record->id => ['months' => $unpaidMonths, 'amount' => $runningCents / 100, 'monthly_charge' => $charge['amount']]];
        });
    }
}
