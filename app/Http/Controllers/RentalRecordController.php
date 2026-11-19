<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\RentalRecord;
use App\Models\RentalScheduleAdjustment;
use App\Services\RentalScheduleEditor;
use App\Services\ActivityService;
use App\Services\RentalRecordService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RentalRecordController extends Controller
{
    public function __construct(
        private RentalRecordService $rentalRecords,
        private ActivityService $activity,
    ) {}

    public function index(Request $request, Proposal $proposal)
    {
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('rental_records.view'), 403);

        $schedule = $this->rentalRecords->ensureSchedule($proposal);
        $records = $proposal->rentalRecords()
            ->with('updatedBy')
            ->orderBy('installment_no')
            ->get();
        $adjustments = $proposal->rentalScheduleAdjustments()
            ->with('createdBy')
            ->orderBy('effective_month')
            ->orderBy('id')
            ->get();
        $metrics = $this->rentalRecords->metrics($proposal, $records);
        $timeline = $this->rentalRecords->timeline($proposal, $records, $adjustments);
        $eligibleAdjustmentStarts = $this->rentalRecords->eligibleAdjustmentStarts($records);

        if ($schedule['created']) {
            $this->activity->record($proposal, $request->user(), 'Generated 36-month rental schedule', [
                'first_payment_month' => $metrics['first_payment_month']?->format('Y-m'),
                'term_months' => RentalRecordService::TERM_MONTHS,
                'project_amount' => $metrics['project_amount'],
            ]);
        }

        return view('rental-records.index', compact(
            'proposal',
            'records',
            'adjustments',
            'metrics',
            'schedule',
            'timeline',
            'eligibleAdjustmentStarts'
        ));
    }

    public function export(Request $request, Proposal $proposal)
    {
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('rental_records.view'), 403);
        $schedule = $this->rentalRecords->ensureSchedule($proposal);
        abort_unless($schedule['ready'], 422, 'Funding date and proposal amount are required.');
        $records = $proposal->rentalRecords()->orderBy('installment_no')->get();
        $adjustments = $proposal->rentalScheduleAdjustments()->orderBy('effective_month')->orderBy('id')->get();
        $metrics = $this->rentalRecords->metrics($proposal, $records);
        $rows = [];
        foreach ($this->rentalRecords->timeline($proposal, $records, $adjustments) as $item) {
            if ($item['kind'] === 'record') {
                $record = $item['record'];
                $rows[] = [$record->installment_no, $record->due_month->format('M Y'), (float) $record->amount_due,
                    $record->rental_payment === null ? null : (float) $record->rental_payment,
                    $item['outstanding'], $metrics['penalties'][$record->id]['amount'], $record->or_number,
                    $record->or_date?->format('Y-m-d'), $record->amount_in_or === null ? null : (float) $record->amount_in_or, $record->remarks];
            } else {
                $adjustment = $item['adjustment'];
                $description = $item['kind'] === 'credit'
                    ? 'Unexpended balance deduction: PHP '.number_format((float) $adjustment->unexpended_amount, 2)
                    : $adjustment->adjustment_type.' - payment deferred';
                $rows[] = [null, $item['date']->format('M Y'), null, null, $item['outstanding'], null, null, null, null, $description];
            }
        }
        // Accumulated penalties and outstanding balance are snapshots, not sums.
        $rows[] = [null, 'TOTAL', (float) $records->sum('amount_due'), $metrics['total_paid'], $metrics['outstanding_balance'], $metrics['total_penalty'], null, null, null, null];
        if ($proposal->rental_otp_enabled) {
            $rows[] = [null, 'OTP (optional)', round((float) $proposal->amount * 0.005, 2), null, null, null, null, null, null, 'Excluded from rental obligation and totals'];
        }
        $report = [
            'title' => 'Rental Records',
            'subtitle' => 'Saved records as of '.now()->format('Y-m-d'),
            'sections' => [
                ['title' => 'Project details', 'headers' => ['Field', 'Value'], 'types' => ['text', 'text'], 'rows' => [
                    ['Project Code', $proposal->project_code], ['Proposal Code', $proposal->proposal_code],
                    ['Project title', $proposal->project_title], ['Proponent', $proposal->proponent],
                    ['Province', $proposal->province], ['Program', $proposal->sector], ['Funding date', $proposal->date_funded->format('Y-m-d')],
                    ['Proposal amount (PHP)', number_format((float) $proposal->amount, 2)],
                    ['Unexpended balance deducted (PHP)', number_format($metrics['unexpended_balance'], 2)],
                ]],
                ['title' => 'Payment schedule', 'headers' => ['#', 'Month', 'Amount Due', 'Rental Payment', 'Outstanding Balance', 'Accumulated Penalty', 'OR Number', 'OR Date', 'Amount in OR', 'Remarks'],
                    'types' => ['integer', 'text', 'money', 'money', 'money', 'money', 'text', 'text', 'money', 'text'], 'rows' => $rows],
                ['title' => 'Schedule adjustments', 'headers' => ['Type', 'Method', 'Effective from', 'Through / resume month', 'Monthly due', 'Unexpended amount', 'Repayment option', 'Approved date', 'Final month', 'Remarks'],
                    'types' => ['text', 'text', 'text', 'text', 'money', 'money', 'text', 'text', 'text', 'text'],
                    'rows' => $adjustments->map(fn ($a) => [$a->adjustment_type, $a->mode,
                        $a->effective_month?->format('M Y'), ($a->mode === 'range' ? $a->resume_month?->copy()->subMonth() : $a->resume_month)?->format('M Y'),
                        $a->revised_amount_due === null ? null : (float) $a->revised_amount_due, (float) $a->unexpended_amount,
                        $a->repayment_option === '36_months' ? '36 months' : ($a->repayment_option === 'keep_due' ? 'Keep monthly due' : ''),
                        $a->approved_date?->format('Y-m-d'), $a->revised_final_payment_month?->format('M Y'), $a->remarks])->all()],
            ],
        ];
        $path = app(\App\Services\MonitoringSpreadsheetExportService::class)->createXlsx($report);
        $filename = 'Rental-Records-'.$proposal->id.'-'.now()->format('Y-m-d').'.xlsx';
        return response()->download($path, $filename, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    public function update(Request $request, Proposal $proposal)
    {
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('rental_records.manage'), 403);

        $schedule = $this->rentalRecords->ensureSchedule($proposal);
        abort_unless($schedule['ready'], 422, 'A Funding Date and Proposal Amount are required before rental records can be updated.');

        $data = $request->validate([
            'records' => ['nullable', 'array'],
            'records.*.force_edit' => ['nullable', 'boolean'],
            'records.*.rental_payment' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'records.*.or_number' => ['nullable', 'string', 'max:100'],
            'records.*.or_date' => ['nullable', 'date'],
            'records.*.amount_in_or' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'records.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $records = $proposal->rentalRecords()->get()->keyBy('id');
        $changedMonths = [];
        $fields = ['rental_payment', 'or_number', 'or_date', 'amount_in_or', 'remarks'];

        foreach (($data['records'] ?? []) as $id => $input) {
            /** @var RentalRecord|null $record */
            $record = $records->get((int) $id);
            if (! $record) {
                continue;
            }

            $forceEdit = filter_var($input['force_edit'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $updates = [];

            foreach ($fields as $field) {
                if (! array_key_exists($field, $input)) {
                    continue;
                }

                if ($this->hasSavedValue($record->{$field}) && ! $forceEdit) {
                    continue;
                }

                $updates[$field] = $this->blankToNull($input[$field]);
            }

            if (array_key_exists('rental_payment', $updates)
                && $updates['rental_payment'] !== null
                && array_key_exists('amount_in_or', $updates)
                && $updates['amount_in_or'] === null) {
                $updates['amount_in_or'] = $updates['rental_payment'];
            }

            if (! $updates) {
                continue;
            }

            $record->fill($updates);

            if ($record->isDirty()) {
                $record->updated_by = $request->user()->id;
                $changedMonths[] = $record->due_month?->format('M-Y') ?? ('#'.$record->installment_no);
                $record->save();
            }
        }

        if ($changedMonths) {
            $freshRecords = $proposal->rentalRecords()->orderBy('installment_no')->get();
            $metrics = $this->rentalRecords->metrics($proposal, $freshRecords);

            $this->activity->record($proposal, $request->user(), 'Updated rental records', [
                'months' => $changedMonths,
                'total_paid' => $metrics['total_paid'],
                'outstanding_balance' => $metrics['outstanding_balance'],
                'refund_rate' => $metrics['refund_rate'],
            ]);
        }

        return back()->with('success', $changedMonths ? 'Rental records updated successfully.' : 'No rental-record changes were detected.');
    }

    public function restructure(Request $request, Proposal $proposal)
    {
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('rental_records.manage'), 403);

        $schedule = $this->rentalRecords->ensureSchedule($proposal);
        abort_unless($schedule['ready'], 422, 'A Funding Date and Proposal Amount are required before the rental schedule can be restructured.');

        $adjustment = app(RentalScheduleEditor::class)->save($proposal, $this->adjustmentData($request), $request->user()->id);
        $this->activity->record($proposal, $request->user(), 'Added rental schedule adjustment', $adjustment->toArray());
        return back()->with('success', 'Adjustment applied. The unpaid schedule and outstanding balance have been recalculated.');
    }

    public function updateAdjustment(Request $request, Proposal $proposal, RentalScheduleAdjustment $adjustment)
    {
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('rental_records.manage'), 403);
        abort_unless($adjustment->proposal_id === $proposal->id, 404);
        $before = $adjustment->toArray();
        $updated = app(RentalScheduleEditor::class)->save($proposal, $this->adjustmentData($request), $request->user()->id, $adjustment);
        $this->activity->record($proposal, $request->user(), 'Edited rental schedule adjustment', ['before' => $before, 'after' => $updated->toArray()]);
        return back()->with('success', 'Adjustment updated and unpaid schedule recalculated.');
    }

    public function destroyAdjustment(Request $request, Proposal $proposal, RentalScheduleAdjustment $adjustment)
    {
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('rental_records.manage'), 403);
        abort_unless($adjustment->proposal_id === $proposal->id, 404);
        $before = $adjustment->toArray();
        app(RentalScheduleEditor::class)->remove($proposal, $adjustment);
        $this->activity->record($proposal, $request->user(), 'Removed rental schedule adjustment', $before);
        return back()->with('success', 'Adjustment removed and unpaid schedule recalculated.');
    }

    private function adjustmentData(Request $request): array
    {
        return $request->validate([
            'mode' => ['required', Rule::in(['range', 'pause', 'credit'])],
            'adjustment_type' => ['required_unless:mode,credit', Rule::in(['Reconstruction', 'Revision', 'Moratorium', 'Other', 'Unexpended Balance'])],
            'effective_month' => ['required', 'date_format:Y-m'],
            'resume_month' => ['required_unless:mode,credit', 'nullable', 'date_format:Y-m'],
            'approved_date' => ['nullable', 'date'],
            'revised_amount_due' => ['required_if:mode,range', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'unexpended_amount' => ['required_if:mode,credit', 'nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'repayment_option' => ['required_if:mode,credit', 'nullable', Rule::in(['36_months', 'keep_due'])],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    public function toggleOtp(Request $request, Proposal $proposal)
    {
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('rental_records.manage'), 403);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN);
        $proposal->rental_otp_enabled = $enabled;
        $proposal->save();

        $otpAmount = round(((float) $proposal->amount) * 0.005, 2);

        $this->activity->record(
            $proposal,
            $request->user(),
            $enabled ? 'Added OTP option to rental records' : 'Hidden OTP option from rental records',
            [
                'otp_enabled' => $enabled,
                'otp_rate' => 0.005,
                'otp_amount' => $otpAmount,
            ]
        );

        return back()->with(
            'success',
            $enabled
                ? 'OTP (Option to Purchase) has been added to the Rental Records schedule.'
                : 'OTP (Option to Purchase) has been hidden from the Rental Records schedule.'
        );
    }

    private function hasSavedValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    private function guard(Request $request, Proposal $proposal): void
    {
        if ($request->user()->dataScope() === 'province') {
            abort_unless($proposal->province === $request->user()->province, 403);
        }

        if ($request->user()->isEvaluator()) {
            abort_unless(
                $proposal->evaluators()->where('email', $request->user()->email)->exists(),
                403
            );
        }
    }

    private function blankToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
