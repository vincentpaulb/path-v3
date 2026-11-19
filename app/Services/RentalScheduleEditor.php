<?php

namespace App\Services;

use App\Models\Proposal;
use App\Models\RentalScheduleAdjustment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Rebuild only the affected, unsaved tail; retain payments and earlier dues. */
class RentalScheduleEditor
{
    public function save(Proposal $proposal, array $data, ?int $userId, ?RentalScheduleAdjustment $adjustment = null): RentalScheduleAdjustment
    {
        return DB::transaction(function () use ($proposal, $data, $userId, $adjustment) {
            $proposal = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($adjustment) {
                $adjustment = $proposal->rentalScheduleAdjustments()->lockForUpdate()->findOrFail($adjustment->id);
            }
            $effective = Carbon::createFromFormat('!Y-m', $data['effective_month']);
            $mode = $data['mode'] ?? 'pause';
            if ($mode === 'pause' && isset($data['revised_amount_due']) && $this->cents($data['revised_amount_due']) <= 0) {
                $this->invalid('revised_amount_due', 'The regular monthly due must be greater than zero. Use a temporary date range for a zero-due holiday.');
            }
            $resume = $mode === 'credit' ? $effective->copy() : Carbon::createFromFormat('!Y-m', $data['resume_month']);
            // The range end supplied by the form is inclusive. Stored resume is exclusive.
            if ($mode === 'range') {
                $resume->addMonth();
            }
            if ($mode !== 'credit' && $resume->lessThanOrEqualTo($effective)) {
                $this->invalid('resume_month', 'The end month must not precede the start month; a pause must resume after its start.');
            }
            $start = $adjustment && $adjustment->effective_month->lt($effective) ? $adjustment->effective_month->copy() : $effective->copy();
            $records = $proposal->rentalRecords()->orderBy('installment_no')->lockForUpdate()->get();
            $events = $proposal->rentalScheduleAdjustments()->orderBy('effective_month')->orderBy('id')->get();
            $baseDue = $events->first()?->previous_amount_due ?? $records->first()?->amount_due ?? round((float) $proposal->amount / 36, 2);
            $firstMonth = $records->first()?->due_month;
            if ($events->first() && (! $firstMonth || $events->first()->effective_month->lt($firstMonth))) {
                $firstMonth = $events->first()->effective_month;
            }
            if (! $firstMonth || $effective->lt($firstMonth)) {
                $this->invalid('effective_month', 'The effective month cannot precede the rental schedule.');
            }
            if (! $adjustment && ! $records->contains(fn ($row) => $row->due_month->isSameMonth($effective))) {
                $this->invalid('effective_month', 'Choose a month in the current unpaid schedule.');
            }
            $this->assertClean($records, $start);
            foreach ($events as $other) {
                if ($other->id === $adjustment?->id) continue;
                if ($mode === 'credit' || $other->mode === 'credit') {
                    // A credit may precede a range but cannot reset its base rate midway.
                    if ($effective->eq($other->effective_month)
                        || ($mode === 'credit' && $effective->between($other->effective_month, $other->resume_month->copy()->subDay()))
                        || ($other->mode === 'credit' && $other->effective_month->gte($effective) && $other->effective_month->lt($resume))) {
                        $this->invalid('effective_month', 'Choose a credit month outside other adjustment periods.');
                    }
                } elseif ($effective->lt($other->resume_month) && $resume->gt($other->effective_month)) {
                    $this->invalid('effective_month', 'The selected dates overlap an existing adjustment.');
                }
            }
            $paid = $records->sum(fn ($row) => $this->cents($row->rental_payment));
            $credits = $events->where('id', '!=', $adjustment?->id)->sum(fn ($event) => $this->cents($event->unexpended_amount));
            $balance = $this->cents($proposal->amount) - $paid - $credits;
            $credit = $mode === 'credit' ? $this->cents($data['unexpended_amount'] ?? 0) : 0;
            if ($balance <= 0 || $credit > $balance || ($mode === 'credit' && $credit <= 0)) {
                $this->invalid('unexpended_amount', 'Enter an amount greater than zero and no greater than the outstanding balance.');
            }
            $adjustment ??= new RentalScheduleAdjustment(['proposal_id' => $proposal->id, 'created_by' => $userId]);
            $adjustment->fill([
                'mode' => $mode,
                'adjustment_type' => $mode === 'credit' ? 'Unexpended Balance' : $data['adjustment_type'],
                'effective_month' => $effective, 'resume_month' => $resume,
                'suspension_months' => $mode === 'pause' ? (int) $effective->diffInMonths($resume) : 0,
                'approved_date' => $data['approved_date'] ?? null,
                'outstanding_balance' => $balance / 100,
                'previous_amount_due' => $adjustment->previous_amount_due ?? $baseDue,
                'revised_amount_due' => $mode === 'credit' ? null : ($data['revised_amount_due'] ?? null),
                'unexpended_amount' => $credit / 100,
                'repayment_option' => $mode === 'credit' ? $data['repayment_option'] : null,
                'remarks' => $data['remarks'] ?? null,
            ])->save();
            $this->rebuild($proposal, $start, $baseDue, $adjustment);
            return $adjustment->refresh();
        });
    }

    public function remove(Proposal $proposal, RentalScheduleAdjustment $adjustment): void
    {
        DB::transaction(function () use ($proposal, $adjustment) {
            $proposal = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $adjustment = $proposal->rentalScheduleAdjustments()->lockForUpdate()->findOrFail($adjustment->id);
            $events = $proposal->rentalScheduleAdjustments()->orderBy('effective_month')->orderBy('id')->get();
            $baseDue = $events->first()?->previous_amount_due ?? round((float) $proposal->amount / 36, 2);
            $start = $adjustment->effective_month->copy();
            $this->assertClean($proposal->rentalRecords()->lockForUpdate()->get(), $start);
            $adjustment->delete();
            $this->rebuild($proposal, $start, $baseDue);
        });
    }

    private function assertClean($records, Carbon $start): void
    {
        foreach ($records as $record) {
            if ($record->due_month->gte($start) && app(RentalRecordService::class)->hasOperationalData($record)) {
                $this->invalid('effective_month', 'This change would rebuild a period with saved payments, receipts or remarks. Choose a later unpaid period. Saved rental history is preserved.');
            }
        }
    }

    private function rebuild(Proposal $proposal, Carbon $start, $baseDue, ?RentalScheduleAdjustment $changed = null): void
    {
        $records = $proposal->rentalRecords()->orderBy('installment_no')->get();
        $prefix = $records->filter(fn ($row) => $row->due_month->lt($start));
        // Preserve arrears on earlier rows rather than allocating the same debt twice.
        $remaining = $this->cents($proposal->amount) - $prefix->sum(fn ($row) => max($this->cents($row->amount_due), $this->cents($row->rental_payment)));
        $events = $proposal->rentalScheduleAdjustments()->orderBy('effective_month')->orderBy('id')->get();
        $rate = max(1, $this->cents($baseDue));
        foreach ($events as $event) {
            if ($event->mode === 'credit' && $event->effective_month->lt($start)) {
                $remaining -= $this->cents($event->unexpended_amount);
                if ($event->repayment_option === '36_months') $rate = max(1, $this->cents($event->revised_amount_due));
            }
            if ($event->mode === 'pause' && $event->resume_month->lte($start) && $event->revised_amount_due !== null) {
                $rate = $this->cents($event->revised_amount_due);
            }
        }
        $month = $start->copy();
        $number = (int) ($prefix->max('installment_no') ?? 0);
        $rows = [];
        $lastEvent = $events->max('resume_month');
        for ($step = 0; $remaining > 0 || ($lastEvent && $month->lte($lastEvent)); $step++, $month->addMonth()) {
            if ($step > 1200 || $number >= 65000) $this->invalid('revised_amount_due', 'The resulting schedule exceeds 100 years. Increase the monthly due or shorten the adjustment.');
            foreach ($events as $event) {
                if ($event->mode === 'credit' && $event->effective_month->eq($month)) {
                    $remaining -= $this->cents($event->unexpended_amount);
                    if ($remaining < 0) $this->invalid('unexpended_amount', 'The deduction exceeds the balance available at its effective month. Choose an earlier month or a smaller amount.');
                    if ($event->repayment_option === '36_months' && $remaining > 0) {
                        $rate = (int) ceil($remaining / 36);
                    }
                    $event->update(['revised_amount_due' => $rate / 100]);
                }
                if ($event->mode === 'pause' && $event->resume_month->eq($month) && $event->revised_amount_due !== null) {
                    $rate = $this->cents($event->revised_amount_due);
                }
            }
            if ($remaining <= 0) continue;
            $active = $events->first(fn ($event) => $event->mode !== 'credit' && $month->gte($event->effective_month) && $month->lt($event->resume_month));
            if ($active?->mode === 'pause') continue;
            $due = $active?->mode === 'range' ? $this->cents($active->revised_amount_due) : $rate;
            $due = min($remaining, $due);
            // Original 36-month schedules may round down by a few centavos.
            // Restore that final rounding remainder without adding a whole month.
            if ($events->isEmpty() && $due > 0 && $remaining > $due && $remaining - $due <= 35) {
                $due = $remaining;
            }
            $rows[] = ['installment_no' => ++$number, 'due_month' => $month->toDateString(), 'amount_due' => $due / 100];
            $remaining -= $due;
        }
        $proposal->rentalRecords()->whereDate('due_month', '>=', $start->toDateString())->delete();
        foreach ($rows as $row) $proposal->rentalRecords()->create($row);
        $proposal->rental_schedule_customized = true;
        $proposal->save();
        foreach ($events as $event) {
            if ($event->effective_month->gte($start)) {
                $tail = $proposal->rentalRecords()->whereDate('due_month', '>=', $event->effective_month)->get();
                $event->update([
                    'revised_remaining_installments' => $tail->count(),
                    'revised_final_payment_month' => $tail->max('due_month'),
                ]);
            }
        }
        if ($changed) $changed->update(['previous_remaining_installments' => $records->filter(fn ($row) => $row->due_month->gte($changed->effective_month))->count()]);
    }

    private function cents($value): int { return (int) round((float) $value * 100); }
    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
