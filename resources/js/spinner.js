// What a pop-up or panel shows while its content loads.
export function loadingMarkup(text = 'Loading…') {
    return `<p role="status" class="flex items-center justify-center gap-2 py-10 text-sm text-muted"><span class="spinner" aria-hidden="true"></span>${text}</p>`;
}
