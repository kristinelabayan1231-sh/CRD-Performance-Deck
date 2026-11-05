import { toPng } from 'html-to-image';

// Daily I.S. Screenshot button: saves one row (the department's or a CRA's card and every product card, the ones
// scrolled out of view too) as a single wide PNG. The cards are copied side by side off screen and captured there.
export function initDailyIsShot() {
    document.querySelectorAll('[data-is-shot]').forEach((button) =>
        button.addEventListener('click', async () => {
            const row = button.closest('[data-is-row]');
            const label = button.querySelector('[data-is-shot-label]');
            const background = getComputedStyle(document.body).backgroundColor;

            const sheet = document.createElement('div');
            sheet.style.cssText = `position: fixed; top: 0; left: -100000px; display: flex; flex-direction: column; gap: 12px; padding: 20px; width: max-content; background: ${background}`;
            const title = document.createElement('p');
            title.textContent = button.dataset.isShotTitle;
            title.className = 'text-sm font-bold text-ink';
            const cards = document.createElement('div');
            cards.style.cssText = 'display: flex; align-items: flex-start; gap: 12px';
            row.querySelectorAll('[data-is-card]').forEach((card) => {
                const copy = card.cloneNode(true);
                copy.style.width = '350px';
                copy.style.flexShrink = '0';
                cards.append(copy);
            });
            sheet.append(title, cards);
            document.body.append(sheet);

            button.disabled = true;
            label.textContent = 'Saving…';
            try {
                const png = await toPng(sheet, {
                    pixelRatio: 2,
                    backgroundColor: background,
                    skipFonts: true,
                    // The copy sits off screen; in the image it starts at the top left.
                    style: { position: 'static', left: '0', top: '0' },
                });
                const link = document.createElement('a');
                link.href = png;
                link.download = button.dataset.isShotFile;
                link.click();
            } catch {
                alert('Could not save the screenshot. Please try again.');
            } finally {
                sheet.remove();
                button.disabled = false;
                label.textContent = 'Screenshot';
            }
        }),
    );
}
