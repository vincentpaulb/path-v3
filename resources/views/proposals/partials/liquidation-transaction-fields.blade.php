@php
    $transaction = $transaction ?? null;
    $txAmount = $transaction ? max(abs((float)$transaction->obligation_amount), abs((float)$transaction->disbursement_amount), abs((float)$transaction->adjustment_amount), abs((float)$transaction->refund_amount)) : null;
@endphp
<div class="liquidation-form-grid">
    <label>Transaction date<input type="date" name="transaction_date" value="{{ old('transaction_date', $transaction?->transaction_date?->format('Y-m-d') ?: now()->format('Y-m-d')) }}" required></label>
    <label>Transaction type<select name="transaction_type" required>@foreach($transactionTypes as $type)<option value="{{ $type }}" @selected(old('transaction_type', $transaction?->transaction_type) === $type)>{{ $type }}</option>@endforeach</select></label>
    <label>Amount<input type="number" name="amount" min="0.01" step="0.01" value="{{ old('amount', $txAmount) }}" required></label>
    <label>Status<select name="status"><option value="Posted" @selected(old('status', $transaction?->status ?: 'Posted') === 'Posted')>Posted</option><option value="Pending" @selected(old('status', $transaction?->status) === 'Pending')>Pending</option><option value="Void" @selected(old('status', $transaction?->status) === 'Void')>Void</option></select></label>
    <label>Entry number<input name="entry_number" value="{{ old('entry_number', $transaction?->entry_number) }}"></label>
    <label>Reference type<select name="reference_type"><option value="">Select...</option>@foreach($referenceTypes as $type)<option value="{{ $type }}" @selected(old('reference_type', $transaction?->reference_type) === $type)>{{ $type }}</option>@endforeach</select></label>
    <label>Reference number<input name="reference_number" value="{{ old('reference_number', $transaction?->reference_number) }}"></label>
    <label>Payee / source<input name="payee" value="{{ old('payee', $transaction?->payee) }}"></label>
    <label class="span-2">Particulars<textarea name="particulars" rows="3" required>{{ old('particulars', $transaction?->particulars) }}</textarea></label>
    <label>Approved budget item<select name="budget_line_id"><option value="">General / unlinked</option>@foreach($proposal->budgetLines as $line)<option value="{{ $line->id }}" @selected((string)old('budget_line_id', $transaction?->budget_line_id) === (string)$line->id)>{{ $line->item_name }}</option>@endforeach</select></label>
    <label>Acquisition<select name="acquisition_id"><option value="">No acquisition link</option>@foreach($proposal->acquisitions as $a)<option value="{{ $a->id }}" @selected((string)old('acquisition_id', $transaction?->acquisition_id) === (string)$a->id)>{{ $a->actual_item_name }}</option>@endforeach</select></label>
    <label>Funding source<input name="funding_source" value="{{ old('funding_source', $transaction?->funding_source) }}"></label><label>Fund account<input name="fund_account" value="{{ old('fund_account', $transaction?->fund_account) }}"></label>
    <label class="span-2">Remarks<textarea name="remarks" rows="2">{{ old('remarks', $transaction?->remarks) }}</textarea></label>
</div>
