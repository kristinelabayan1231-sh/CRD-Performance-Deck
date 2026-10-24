{{--
    A spiel's rich-text Content: toolbar (bold, italic, underline, strikethrough, bulleted and numbered list), the
    editable box, and Insert {name}/{product}. spiels.js keeps the hidden "body" input in step with the box; the server
    cleans the HTML (Spiel::sanitize). Expects $value (saved HTML or '') and optional $rows (box height in lines).
--}}
@php
    $tool = 'grid size-8 place-items-center rounded-md text-ink hover:bg-brand-50 hover:text-brand-700 focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none';
@endphp
<div data-spiel-editor class="overflow-hidden rounded-lg border border-line bg-white focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-200">
    <div role="toolbar" aria-label="Formatting" class="flex flex-wrap gap-0.5 border-b border-line bg-canvas/50 p-1">
        <button type="button" data-rte="bold" aria-label="Bold" title="Bold (Ctrl+B)" class="{{ $tool }} font-bold">B</button>
        <button type="button" data-rte="italic" aria-label="Italic" title="Italic (Ctrl+I)" class="{{ $tool }} italic">I</button>
        <button type="button" data-rte="underline" aria-label="Underline" title="Underline (Ctrl+U)" class="{{ $tool }} underline">U</button>
        <button type="button" data-rte="strikeThrough" aria-label="Strikethrough" title="Strikethrough" class="{{ $tool }} line-through">S</button>
        <span aria-hidden="true" class="mx-1 my-1.5 w-px bg-line"></span>
        <button type="button" data-rte="insertUnorderedList" aria-label="Bulleted list" title="Bulleted list" class="{{ $tool }}">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1" fill="currentColor"/><circle cx="4.5" cy="12" r="1" fill="currentColor"/><circle cx="4.5" cy="18" r="1" fill="currentColor"/></svg>
        </button>
        <button type="button" data-rte="insertOrderedList" aria-label="Numbered list" title="Numbered list" class="{{ $tool }}">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M10 6h10M10 12h10M10 18h10M4 5h1v3M4 11.5h2l-2 2.5h2M4 17h2v1.5H5 6V20H4"/></svg>
        </button>
    </div>
    <div contenteditable="true" role="textbox" aria-multiline="true" aria-label="Spiel Content" data-rte-box
         data-placeholder="Hi {name}! Malapit na pong maubos ang inyong {product}…"
         style="min-height: {{ ($rows ?? 7) * 1.5 }}rem"
         class="spiel-rich max-h-96 overflow-y-auto px-3 py-2.5 text-sm focus:outline-none empty:before:text-muted/70 empty:before:content-[attr(data-placeholder)]">{!! $value !!}</div>
    <input type="hidden" name="body" value="{{ $value }}" data-rte-input>
</div>
@include('settings._spiel-placeholders')
