{{-- Insert buttons for a spiel form's placeholders (spiels.js puts them at the cursor in the form's Content). --}}
<p class="flex flex-wrap items-center gap-1.5 text-xs text-muted">
    Insert
    @foreach (\App\Models\Spiel::PLACEHOLDERS as $placeholder)
        <button type="button" data-spiel-insert="{{ $placeholder }}" class="rounded bg-canvas px-1.5 py-0.5 text-xs text-ink hover:bg-brand-50 hover:text-brand-700">{{ $placeholder }}</button>
    @endforeach
    filled in for the customer when copied
</p>
