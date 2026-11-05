{{-- One TSD upsell product in the add panel; $index is "__i__" in the template the "+ Another product" button copies. --}}
<fieldset data-tsd-row class="grid gap-2 rounded-lg border border-line p-3">
    <div class="flex items-center justify-between">
        <span class="text-xs font-semibold text-muted">Product <span data-tsd-number>{{ is_int($index) ? $index + 1 : '' }}</span></span>
        <button type="button" data-tsd-remove @class(['text-xs font-semibold text-coral-700 hover:underline', 'hidden' => $index === 0])>Remove</button>
    </div>
    <label>
        <span class="{{ $label }}">Product (keyword)</span>
        <input type="text" name="products[{{ $index }}][name]" maxlength="255" value="{{ $row['name'] ?? '' }}" placeholder="e.g. Haplunas" class="{{ $input }}">
    </label>
    <label>
        <span class="{{ $label }}">Also matches <span class="font-normal text-muted">(comma-separated)</span></span>
        <input type="text" name="products[{{ $index }}][keywords]" maxlength="1000" value="{{ $row['keywords'] ?? '' }}" placeholder="e.g. Haplunas Healing Eye Cream" class="{{ $input }}">
    </label>
    <label>
        <span class="{{ $label }}">Not this product if it has</span>
        <input type="text" name="products[{{ $index }}][exclude_keywords]" maxlength="1000" value="{{ $row['exclude_keywords'] ?? '' }}" placeholder="e.g. Nasal Inhaler" class="{{ $input }}">
    </label>
    <div class="grid grid-cols-2 gap-3">
        <label>
            <span class="{{ $label }}">SRP <span class="font-normal text-muted">(₱ per unit)</span></span>
            <input type="number" name="products[{{ $index }}][srp]" min="0" step="0.01" value="{{ $row['srp'] ?? '' }}" placeholder="Not set" class="{{ $input }} tabular-nums">
        </label>
        <label>
            <span class="{{ $label }}">Product cost <span class="font-normal text-muted">(₱)</span></span>
            <input type="number" name="products[{{ $index }}][unit_cost]" min="0" step="0.01" value="{{ $row['unit_cost'] ?? '' }}" placeholder="Not set" class="{{ $input }} tabular-nums">
        </label>
    </div>
</fieldset>
