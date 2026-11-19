<div class="rental-adjustment-controls">
    @php
        $editData = [
            '_adjustment_id' => $adjustment->id,
            'mode' => $adjustment->mode,
            'adjustment_type' => $adjustment->adjustment_type,
            'effective_month' => $adjustment->effective_month?->format('Y-m'),
            'resume_month' => ($adjustment->mode === 'range' ? $adjustment->resume_month?->copy()->subMonth() : $adjustment->resume_month)?->format('Y-m'),
            'approved_date' => $adjustment->approved_date?->format('Y-m-d'),
            'revised_amount_due' => $adjustment->revised_amount_due,
            'unexpended_amount' => $adjustment->unexpended_amount,
            'repayment_option' => $adjustment->repayment_option,
            'remarks' => $adjustment->remarks,
        ];
    @endphp
    <button type="button" class="btn rental-adjustment-icon" data-edit-adjustment="{{ json_encode($editData) }}" title="Edit adjustment" aria-label="Edit adjustment"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
    <form method="POST" action="{{ route('rental-records.adjustments.destroy', [$proposal, $adjustment]) }}" data-remove-adjustment>
        @csrf @method('DELETE')
        <button type="submit" class="btn rental-adjustment-icon rental-adjustment-icon-remove" title="Remove adjustment" aria-label="Remove adjustment"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
    </form>
</div>
