@php
    $acquisition = $acquisition ?? null;
@endphp
<div class="liquidation-form-grid">
    <label class="span-2">Approved equipment link
        <select name="budget_line_id">
            <option value="">Equipment without a plan item</option>
            @foreach($proposal->budgetLines as $line)
                @continue($line->removed_by_realignment && (int) $acquisition?->budget_line_id !== $line->id)
                <option value="{{ $line->id }}" @selected((string)old('budget_line_id', $acquisition?->budget_line_id) === (string)$line->id)>
                    {{ $line->item_name }} · {{ $line->removed_by_realignment ? 'Removed by realignment' : '₱'.number_format($line->currentApprovedAmount(), 2) }}
                </option>
            @endforeach
        </select>
    </label>

    <label class="span-2">Equipment / technology actually acquired
        <input name="actual_item_name" value="{{ old('actual_item_name', $acquisition?->actual_item_name) }}" required>
    </label>

    <label class="span-2">Specifications as liquidated
        <textarea name="specifications_liquidated" rows="4" placeholder="Enter the specifications of the equipment actually acquired...">{{ old('specifications_liquidated', $acquisition?->specifications_liquidated) }}</textarea>
    </label>

    <label>Quantity
        <input type="number" name="quantity" min="0.01" step="0.01" value="{{ old('quantity', $acquisition?->quantity ?: 1) }}" required>
    </label>

    <label>Unit
        <input name="unit" value="{{ old('unit', $acquisition?->unit ?: $acquisition?->budgetLine?->unit) }}" placeholder="unit, set, lot...">
    </label>

    <label>Amount as realigned
        <input type="number" name="realigned_amount" min="0" step="0.01" value="{{ old('realigned_amount', $acquisition?->realigned_amount) }}" placeholder="Leave blank if not realigned">
        <small class="field-help">A realignment recorded in the equipment plan supplies the amount for linked equipment.</small>
    </label>

    <label>Purchased amount
        <input type="number" name="actual_cost" min="0" step="0.01" value="{{ old('actual_cost', $acquisition?->actual_cost) }}" required>
    </label>

    <label>SETUP Property No.
        <input name="property_number" value="{{ old('property_number', $acquisition?->property_number) }}">
    </label>

    <label>Name of supplier
        <input name="supplier_name" value="{{ old('supplier_name', $acquisition?->supplier_name) }}">
    </label>

    <label>Date acquired
        <input type="date" name="date_acquired" value="{{ old('date_acquired', $acquisition?->date_acquired?->format('Y-m-d')) }}">
    </label>

    <label>Date liquidated
        <input type="date" name="date_liquidated" value="{{ old('date_liquidated', $acquisition?->date_liquidated?->format('Y-m-d')) }}">
        <small class="field-help">Once this date is entered, the purchased amount will count as liquidated.</small>
    </label>

    <label class="span-2">Remarks
        <textarea name="remarks" rows="3" placeholder="Example: realigned from another approved item, proposed but not acquired, etc.">{{ old('remarks', $acquisition?->remarks) }}</textarea>
    </label>
</div>
