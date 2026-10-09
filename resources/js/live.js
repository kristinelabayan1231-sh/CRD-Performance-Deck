// Live pages: [data-live-url] returns a stamp of the page's data and starts stale syncs. It is
// polled every minute while the tab is visible; when the stamp changes the page reloads, but not
// while someone is typing or has a dialog open (it waits for the next check).
const EVERY_MS = 60_000;

export function initLive() {
    const root = document.querySelector('[data-live-url]');
    if (!root) return;

    const version = root.dataset.liveVersion;
    let changed = false;

    const busy = () => document.querySelector('dialog[open]') || document.activeElement?.matches('input, select, textarea');

    const check = async () => {
        if (document.hidden) return;

        if (!changed) {
            try {
                const response = await fetch(root.dataset.liveUrl, { headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                changed = (await response.json()).version !== version;
            } catch {
                return; // Offline or signed out: try again next time.
            }
        }

        if (changed && !busy()) location.reload();
    };

    setInterval(check, EVERY_MS);
    document.addEventListener('visibilitychange', check);
}
