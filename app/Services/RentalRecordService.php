<?php

namespace App\Services;

use App\Models\Proposal;
use App\Models\RentalRecord;
use App\Models\RentalScheduleAdjustment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RentalRecordService
{
    public const TERM_MONTHS = 36;

    /**
     * Build or synchronize the original 36-month schedule.
     *
     * Once rental activity or a schedule adjustment exists, PATH preserves the
     * operational schedule. This is especially important after a Revision that
     * changes the remaining Amount Due and therefore the number of future rows.
     */
    public function ensureSchedule(Proposal $proposal): array
    {
        if (! $proposal->date_funded || $this->toCents((string) $proposal->amount) <= 0) {
            return [
                'ready' => false,
                'locked_warning' => false,
                'created' => false,
            ];
        }

        $desired = $this->desiredSchedule($proposal);
        $existing = $proposal->rentalRecords()->orderBy('installment_no')->get();
        $hasOperationalData = $existing->contains(fn (RentalRecord $record) => $this->hasOperationalData($record));
        $hasAdjustments = $proposal->rental_schedule_customized || $proposal->rentalScheduleAdjustments()->exists();
        $historyLocked = $hasOperationalData || $hasAdjustments;
        $created = false;

        if ($existing->isEmpty() && ! $hasAdjustments) {
            foreach ($desired as $row) {
                $proposal->rentalRecords()->create($row);
            }
            $created = true;
            $existing = $proposal->rentalRecords()->orderBy('installment_no')->get();
        } elseif (! $historyLocked) {
            foreach ($desired as $row) {
                $proposal->rentalRecords()->updateOrCreate(
                    ['installment_no' => $row['installment_no']],
                    $row
                );
            }
            $existing = $proposal->rentalRecords()->orderBy('installment_no')->get();
        } elseif (! $hasAdjustments) {
            // Repair an accidentally missing installment only while the schedule
            // still follows the original 36-month structure. After a schedule
            // adjustment, missing/extra rows may be intentional because the
            // remaining repayment term can be shorter or longer than 36 months.
            $existingByInstallment = $existing->keyBy('installment_no');
            foreach ($desired as $row) {
                if (! $existingByInstallment->has($row['installment_no'])) {
                    $proposal->rentalRecords()->create($row);
                }
            }
            $existing = $proposal->rentalRecords()->orderBy('installment_no')->get();
        }

        $lockedWarning = false;
        if ($historyLocked && $existing->isNotEmpty()) {
            $firstExpected = $desired[0]['due_month'];
            $firstActual = optional($existing->first()->due_month)->format('Y-m-d');
            $firstMonthMismatch = $firstActual !== $firstExpected;

            if ($hasAdjustments && $firstMonthMismatch) {
                $earliestAdjustmentMonth = $proposal->rentalScheduleAdjustments()
                    ->orderBy('effective_month')
                    ->value('effective_month');
                $earliestAdjustmentMonth = $earliestAdjustmentMonth
                    ? Carbon::parse($earliestAdjustmentMonth)->startOfMonth()->format('Y-m-d')
                    : null;

                // If installment #1 was intentionally deferred, the adjustment's
                // effective month is the original first payment month. That is
                // not a warning condition.
                $firstMonthMismatch = $earliestAdjustmentMonth !== $firstExpected;
            }

            // A revised Amount Due intentionally changes the sum/count of the
            // future schedule, so total Amount Due is no longer a safe signal for
            // detecting an edited Proposal Amount after adjustments exist.
            $amountMismatch = false;
            if (! $hasAdjustments) {
                $expectedTotal = collect($desired)->sum(fn ($row) => $this->toCents($row['amount_due']));
                $actualTotal = $existing->sum(fn (RentalRecord $record) => $this->toCents((string) $record->amount_due));
                $amountMismatch = $actualTotal !== $expectedTotal;
            }

            $lockedWarning = $firstMonthMismatch || $amountMismatch;
        }

        return [
            'ready' => true,
            'locked_warning' => $lockedWarning,
            'created' => $created,
        ];
    }

    public function metrics(Proposal $proposal, Collection $records): array
    {
        // Schedule changes do not change principal. Explicit unexpended credits
        // reduce the balance, while refund rate continues to measure payments.
        $projectCents = $this->toCents((string) $proposal->amount);
        $paidCents = $records->sum(fn (RentalRecord $record) => $this->toCents((string) ($record->rental_payment ?? 0)));
        $credits = $proposal->rentalScheduleAdjustments()->where('mode', 'credit')->orderBy('effective_month')->get();
        $creditCents = $credits->sum(fn ($credit) => $this->toCents((string) $credit->unexpended_amount));
        $balanceCents = $projectCents - $paidCents - $creditCents;
        $penalties = app(RentalPenaltyService::class)->schedule($proposal, $records);
        $rowOutstanding = [];
        $running = $projectCents;

        foreach ($records->sortBy('installment_no') as $record) {
            $running -= $this->toCents((string) ($record->rental_payment ?? 0));
            $deductions = $credits->filter(fn ($credit) => $credit->effective_month->lte($record->due_month))->sum(fn ($credit) => $this->toCents((string) $credit->unexpended_amount));
            $rowOutstanding[$record->id] = $this->fromCents($running - $deductions);
        }

        $refundRate = $projectCents > 0
            ? round(($paidCents / $projectCents) * 100, 2)
            : 0.0;

        $remaining = $this->eligibleAdjustmentStarts($records);
        $currentDue = $remaining->first()?->amount_due
            ?? $records->last()?->amount_due
            ?? 0;

        return [
            'penalties' => $penalties,
            'total_penalty' => $penalties->last()['amount'] ?? 0,
            'project_amount' => $this->fromCents($projectCents),
            'total_paid' => $this->fromCents($paidCents),
            'unexpended_balance' => $this->fromCents($creditCents),
            'outstanding_balance' => $this->fromCents($balanceCents),
            'refund_rate' => $refundRate,
            'row_outstanding' => $rowOutstanding,
            'standard_monthly_due' => (float) $currentDue,
            'current_monthly_due' => (float) $currentDue,
            'first_payment_month' => $records->first()?->due_month,
            'last_payment_month' => $records->last()?->due_month,
            'scheduled_payment_count' => $records->count(),
            'remaining_payment_count' => $remaining->count(),
        ];
    }

    /**
     * Return the clean unpaid tail where a restructuring may begin. A schedule
     * adjustment never shifts or rebuilds a row that already contains payment,
     * OR, or remarks data.
     */
    public function eligibleAdjustmentStarts(Collection $records): Collection
    {
        $lastOperationalInstallment = $records
            ->filter(fn (RentalRecord $record) => $this->hasOperationalData($record))
            ->max('installment_no') ?? 0;

        return $records
            ->filter(fn (RentalRecord $record) => $record->installment_no > $lastOperationalInstallment)
            ->values();
    }

    /** Apply a pause, temporary monthly due, or explicit balance deduction. */
    public function applyScheduleAdjustment(Proposal $proposal, array $data, ?int $userId): RentalScheduleAdjustment
    {
        return app(RentalScheduleEditor::class)->save($proposal, $data, $userId);
    }

    /**
     * Build a display timeline containing actual payment rows plus virtual
     * suspended-month rows. Suspended rows have no Amount Due and therefore do
     * not affect outstanding balance or refund rate.
     */
    public function timeline(Proposal $proposal, Collection $records, Collection $adjustments): Collection
    {
        $items = collect();

        foreach ($records as $record) {
            $items->push([
                'kind' => 'record',
                'date' => $record->due_month->copy()->startOfMonth(),
                'record' => $record,
                'sort_order' => 20,
            ]);
        }

        foreach ($adjustments as $adjustment) {
            if ($adjustment->mode === 'credit') {
                $items->push(['kind' => 'credit', 'date' => $adjustment->effective_month->copy(), 'adjustment' => $adjustment, 'sort_order' => 5]);
            }
            for ($i = 0; $i < $adjustment->suspension_months; $i++) {
                $items->push([
                    'kind' => 'pause',
                    'date' => $adjustment->effective_month->copy()->addMonthsNoOverflow($i)->startOfMonth(),
                    'adjustment' => $adjustment,
                    'is_first' => $i === 0,
                    'sort_order' => 10,
                ]);
            }
        }

        $runningCents = $this->toCents((string) $proposal->amount);

        return $items
            ->sortBy(fn (array $item) => $item['date']->format('Y-m-d').'-'.str_pad((string) $item['sort_order'], 2, '0', STR_PAD_LEFT))
            ->values()
            ->map(function (array $item) use (&$runningCents) {
                if ($item['kind'] === 'credit') {
                    $runningCents -= $this->toCents((string) $item['adjustment']->unexpended_amount);
                }
                if ($item['kind'] === 'record') {
                    $runningCents -= $this->toCents((string) ($item['record']->rental_payment ?? 0));
                }

                $item['outstanding'] = $this->fromCents($runningCents);

                return $item;
            });
    }

    public function hasOperationalData(RentalRecord $record): bool
    {
        return $record->rental_payment !== null
            || filled($record->or_number)
            || $record->or_date !== null
            || $record->amount_in_or !== null
            || filled($record->remarks);
    }

    private function desiredSchedule(Proposal $proposal): array
    {
        $start = $proposal->date_funded->copy()->addYear()->startOfMonth();
        $totalCents = $this->toCents((string) $proposal->amount);
        $standardCents = (int) round($totalCents / self::TERM_MONTHS);
        $finalCents = $totalCents - ($standardCents * (self::TERM_MONTHS - 1));
        $rows = [];

        for ($i = 1; $i <= self::TERM_MONTHS; $i++) {
            $amountCents = $i === self::TERM_MONTHS ? $finalCents : $standardCents;
            $rows[] = [
                'installment_no' => $i,
                'due_month' => $start->copy()->addMonths($i - 1)->format('Y-m-d'),
                'amount_due' => number_format($this->fromCents($amountCents), 2, '.', ''),
            ];
        }

        return $rows;
    }

    private function toCents(string $value): int
    {
        $normalized = preg_replace('/[^0-9.\-]/', '', $value) ?: '0';
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');
        $decimal = substr(str_pad($decimal, 2, '0'), 0, 2);
        $cents = ((int) ($whole ?: 0) * 100) + (int) $decimal;

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): float
    {
        return $cents / 100;
    }
}
