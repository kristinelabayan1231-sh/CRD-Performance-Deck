// Light / Dark: the top bar's switch sets <html data-theme>, remembered on this browser only (the head script applies
// it before the page paints). The dark colours are build/assets/theme-dark.css (vite.dark-theme.js).
export function initTheme() {
    const buttons = [...document.querySelectorAll('[data-theme-set]')];
    if (!buttons.length) return;

    const show = (theme) => buttons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.themeSet === theme)));
    show(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light');

    buttons.forEach((button) =>
        button.addEventListener('click', () => {
            const theme = button.dataset.themeSet;
            document.documentElement.dataset.theme = theme;
            show(theme);
            try {
                localStorage.setItem('theme', theme);
            } catch {}
        }),
    );
}
