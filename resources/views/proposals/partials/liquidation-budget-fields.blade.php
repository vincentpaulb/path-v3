@php
    $line = $line ?? null;
@endphp
<div class="liquidation-form-grid">
    <label class="span-2">Equipment / technology approved
        <input name="item_name" value="{{ old('item_name', $line?->item_name) }}" required>
    </label>

    <label class="span-2">Specifications as approved
        <textarea name="description" rows="4" placeholder="Enter the approved equipment specifications...">{{ old('description', $line?->description) }}</textarea>
    </label>

    <label>Quantity
        <input type="number" name="quantity" step="0.01" min="0.01" value="{{ old('quantity', $line?->quantity ?: 1) }}" required>
    </label>

    <label>Unit
        <input name="unit" value="{{ old('unit', $line?->unit) }}" placeholder="unit, set, lot...">
    </label>

    <label class="span-2">Amount as approved
        <input type="number" name="approved_amount" step="0.01" min="0" value="{{ old('approved_amount', $line?->approved_amount) }}" required>
    </label>
</div>
