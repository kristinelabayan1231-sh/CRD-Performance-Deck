// Generic tabs: [data-tabs] wraps [data-tab] buttons and [data-tab-panel] panels.
// The chosen tab is remembered per browser under the data-tabs key.
export function initTabs() {
    document.querySelectorAll('[data-tabs]').forEach((root) => {
        const key = `tabs.${root.dataset.tabs}`;
        const tabs = [...root.querySelectorAll('[data-tab]')];

        const show = (name) => {
            if (!tabs.some((tab) => tab.dataset.tab === name)) return;
            tabs.forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.tab === name)));
            root.querySelectorAll('[data-tab-panel]').forEach((panel) => (panel.hidden = panel.dataset.tabPanel !== name));
            try {
                localStorage.setItem(key, name);
            } catch {
                // Storage unavailable: the choice just won't persist.
            }
        };

        tabs.forEach((tab) => tab.addEventListener('click', () => show(tab.dataset.tab)));

        try {
            show(localStorage.getItem(key));
        } catch {
            // Keep the server-rendered default.
        }
    });
}
