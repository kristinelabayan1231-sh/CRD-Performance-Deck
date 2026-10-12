{{-- A row's edit form (or Add person's when $row is null), hidden until Edit / + Add person; its inputs belong to the form #$formId below the table. --}}
@php
    $key = $row?->id ?? 'new';
    $useOld = $open && old('editing') !== null;
    $value = fn (string $field, $current) => $useOld ? old($field) : $current;
    $payType = $value('pay_type', $row?->pay_type ?? 'regular');
    $control = 'h-9 w-full rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
@endphp
<tr data-cost-form="{{ $key }}" @unless ($open) hidden @endunless class="bg-brand-50/70 align-top">
    <td class="px-4 py-3">
        <label class="sr-only" for="{{ $formId }}-name">Name</label>
        <input id="{{ $formId }}-name" form="{{ $formId }}" name="name" value="{{ $value('name', $row?->name) }}" placeholder="Name (blank = vacant)" maxlength="255"
               class="{{ $control }} font-semibold">
        <label class="sr-only" for="{{ $formId }}-position">Position</label>
        <input id="{{ $formId }}-position" form="{{ $formId }}" name="position" value="{{ $value('position', $row?->position) }}" placeholder="Position" required maxlength="255"
               class="{{ $control }} mt-1.5 text-xs">
    </td>
    <td class="px-3 py-3">
        <label class="sr-only" for="{{ $formId }}-pay">Pay</label>
        <select id="{{ $formId }}-pay" form="{{ $formId }}" name="pay_type" data-cost-pay class="{{ $control }} w-auto min-w-48 pr-8">
            @foreach (config('monthly_cost.pay_types') as $type => $label)
                <option value="{{ $type }}" @selected($payType === $type)>{{ $label }}{{ $type !== \App\Models\MonthlyCostRow::FIXED ? ' · ₱'.number_format($cost->dailyRate($type)).'/day' : '' }}</option>
            @endforeach
        </select>
    </td>
    <td class="px-3 py-3 text-right">
        <label class="sr-only" for="{{ $formId }}-salary">Monthly salary</label>
        <input id="{{ $formId }}-salary" form="{{ $formId }}" name="salary" type="number" min="0" step="0.01" data-cost-salary
               value="{{ $value('salary', $row?->salary !== null ? $row->salary + 0 : '') }}"
               class="h-9 w-32 rounded-lg border border-line bg-white px-2.5 text-right text-sm tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none read-only:bg-canvas read-only:text-muted">
        <span data-cost-hint class="block text-xs text-muted"></span>
    </td>
    <td class="px-3 py-3 text-right whitespace-nowrap">
        <button type="submit" form="{{ $formId }}" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white hover:bg-brand-700">{{ $row ? 'Save' : 'Add' }}</button>
        <button type="button" data-cost-cancel="{{ $key }}" class="h-9 rounded-lg border border-line bg-white px-3 text-xs font-semibold">Cancel</button>
    </td>
</tr>
