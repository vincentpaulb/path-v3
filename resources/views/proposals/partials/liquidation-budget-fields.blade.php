@php
    $line = $line ?? null;
    $forRealignment = $forRealignment ?? false;
    $useOldInput = $forRealignment === $errors->realignment->any();
    $fieldValue = fn ($name, $default) => $useOldInput ? old($name, $default) : $default;
@endphp
<div class="liquidation-form-grid">
    <label class="span-2">Equipment / technology approved
        <input name="item_name" maxlength="500" value="{{ $fieldValue('item_name', $line?->item_name) }}" required>
    </label>

    <label class="span-2">Specifications as approved
        <textarea name="description" rows="4" maxlength="5000" placeholder="Enter the approved equipment specifications...">{{ $fieldValue('description', $line?->description) }}</textarea>
    </label>

    <label>Quantity
        <input type="number" name="quantity" step="0.01" min="0.01" value="{{ $fieldValue('quantity', $line?->quantity ?: 1) }}" required>
    </label>

    <label>Unit
        <input name="unit" maxlength="80" value="{{ $fieldValue('unit', $line?->unit) }}" placeholder="unit, set, lot...">
    </label>

    <label class="span-2">{{ $forRealignment ? 'Amount approved in realignment' : 'Amount as approved' }}
        <input type="number" name="approved_amount" step="0.01" min="0" max="9999999999999.99" value="{{ $fieldValue('approved_amount', $line?->approved_amount) }}" required>
    </label>
</div>
