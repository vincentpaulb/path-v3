<dialog id="rentalAdjustmentDialog" class="rental-adjust-dialog">
    <form method="POST" action="{{ route('rental-records.restructure', $proposal) }}" class="rental-adjust-form" id="rentalAdjustmentForm"
          data-create-url="{{ route('rental-records.restructure', $proposal) }}"
          data-edit-base="{{ url('/proposals/'.$proposal->id.'/rental-records/adjustments') }}">
        @csrf
        <input type="hidden" name="_method" value="POST">
        <input type="hidden" name="_adjustment_id" value="">
        <div class="rental-adjust-dialog-head">
            <div><span class="eyebrow">RENTAL SCHEDULE</span><h2 id="rentalAdjustmentTitle">Adjust schedule</h2>
                <p>Saved payments, receipts and remarks are preserved. Save rental record changes before adjusting the schedule.</p></div>
            <button type="button" class="modal-x" data-close-rental-adjustment aria-label="Close">&times;</button>
        </div>
        @if($errors->any())
            <div class="form-errors">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
        @endif
        <div class="rental-adjust-grid">
            <label><span>Adjustment method</span>
                <select name="mode" required>
                    <option value="range">Temporary monthly due (date range)</option>
                    <option value="pause">Pause payments / revise regular due</option>
                    <option value="credit">Unexpended balance deduction</option>
                </select>
            </label>
            <label data-schedule-field><span>Adjustment type</span>
                <select name="adjustment_type">
                    @foreach(['Reconstruction', 'Revision', 'Moratorium', 'Other'] as $type)<option>{{ $type }}</option>@endforeach
                </select>
            </label>
            <label><span>Effective from</span><input type="month" name="effective_month" required>
                <small>First available unpaid month: {{ $eligibleAdjustmentStarts->first()?->due_month?->format('F Y') ?? 'None' }}.</small>
            </label>
            <label data-schedule-field><span id="rentalRangeEndLabel">Through month (inclusive)</span><input type="month" name="resume_month"></label>
            <label data-schedule-field><span id="rentalDueLabel">Monthly due during range</span>
                <input type="number" name="revised_amount_due" min="0" step="0.01" max="9999999999.99">
                <small id="rentalDueHelp">Enter 0 for a payment holiday. The regular due resumes after the range.</small>
            </label>
            <label data-credit-field hidden><span>Unexpended balance amount</span>
                <input type="number" name="unexpended_amount" min="0.01" step="0.01" max="9999999999.99">
                <small>Outstanding balance: {{ $formatMoney($metrics['outstanding_balance']) }}. This deduction is recorded separately from rental payments.</small>
            </label>
            <label data-credit-field hidden><span>Recalculate payments</span>
                <select name="repayment_option">
                    <option value="36_months">Spread remaining balance over 36 months</option>
                    <option value="keep_due">Keep regular monthly due; reduce months</option>
                </select>
                <small>The new term starts in the effective month. Earlier unpaid dues remain on their original rows. Other dated adjustments still apply.</small>
            </label>
            <label><span>Date approved</span><input type="date" name="approved_date"></label>
            <label class="rental-adjust-remarks-field"><span>Remarks / basis</span><textarea name="remarks" rows="3" maxlength="2000"></textarea></label>
        </div>
        <div class="rental-adjust-preview" id="rentalAdjustmentPreview" aria-live="polite"></div>
        <div class="rental-adjust-dialog-actions">
            <button type="button" class="btn" data-close-rental-adjustment>Cancel</button>
            <button type="submit" class="btn primary">Save and recalculate</button>
        </div>
    </form>
</dialog>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('rentalAdjustmentDialog');
    const form = document.getElementById('rentalAdjustmentForm');
    const field = name => form.elements.namedItem(name);
    const refresh = () => {
        const credit = field('mode').value === 'credit';
        const range = field('mode').value === 'range';
        form.querySelectorAll('[data-schedule-field]').forEach(label => {
            label.hidden = credit;
            label.querySelectorAll('input, select').forEach(input => input.disabled = credit);
        });
        form.querySelectorAll('[data-credit-field]').forEach(label => {
            label.hidden = !credit;
            label.querySelectorAll('input, select').forEach(input => input.disabled = !credit);
        });
        field('resume_month').required = !credit;
        field('resume_month').min = field('effective_month').value;
        field('revised_amount_due').required = range;
        field('revised_amount_due').min = range ? '0' : '0.01';
        field('unexpended_amount').required = credit;
        document.getElementById('rentalRangeEndLabel').textContent = range ? 'Through month (inclusive)' : 'Resume payments';
        document.getElementById('rentalDueLabel').textContent = range ? 'Monthly due during range' : 'New regular monthly due (optional)';
        document.getElementById('rentalDueHelp').textContent = range ? 'Enter 0 for a payment holiday. The regular due resumes after the range.' : 'Leave blank to keep the regular due after the pause.';
        document.getElementById('rentalAdjustmentPreview').textContent = credit
            ? 'The amount is deducted once. Choose 36 months for a new monthly amount, or keep the regular due to shorten the term. The final installment covers the exact remainder.'
            : range ? 'The entered monthly due applies from the start through the end month, inclusive. Afterward the regular due resumes. Lower dues extend the term; higher dues shorten it.'
            : 'No payments are due before the resume month. If entered, the new regular monthly due continues until the balance is covered.';
    };
    const open = (data = {}) => {
        form.reset();
        field('effective_month').value = @json($eligibleAdjustmentStarts->first()?->due_month?->format('Y-m') ?? '');
        field('approved_date').value = @json(now()->format('Y-m-d'));
        for (const [key, value] of Object.entries(data)) {
            const input = field(key);
            if (input && key !== '_token' && key !== '_method') input.value = value ?? '';
        }
        const editing = !!data._adjustment_id;
        form.action = editing ? `${form.dataset.editBase}/${encodeURIComponent(data._adjustment_id)}` : form.dataset.createUrl;
        field('_method').value = editing ? 'PUT' : 'POST';
        document.getElementById('rentalAdjustmentTitle').textContent = editing ? 'Edit adjustment' : data.mode === 'credit' ? 'Input Unexpended Balance' : 'Adjust schedule';
        refresh();
        dialog.showModal();
    };
    document.querySelector('[data-open-rental-adjustment]')?.addEventListener('click', () => open({mode: 'range'}));
    document.querySelector('[data-open-unexpended]')?.addEventListener('click', () => open({mode: 'credit'}));
    document.querySelectorAll('[data-edit-adjustment]').forEach(button => button.addEventListener('click', () => open(JSON.parse(button.dataset.editAdjustment))));
    document.querySelectorAll('[data-remove-adjustment]').forEach(item => item.addEventListener('submit', event => {
        if (!confirm('Remove this adjustment and recalculate the unpaid schedule? Saved rental history will be preserved.')) event.preventDefault();
    }));
    document.querySelectorAll('[data-close-rental-adjustment]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    form.addEventListener('change', refresh);
    @if($errors->any() && old('mode'))
        open(@json(old()));
    @endif
});
</script>
@endpush
