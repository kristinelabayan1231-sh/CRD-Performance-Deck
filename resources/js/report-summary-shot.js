import { toPng } from 'html-to-image';

// Report Summary Screenshot buttons: save a section (the week or month to date, or the daily section with every day of
// a range) as one PNG under a title with its dates. The section is copied off screen with its scrolling boxes opened
// out, so the columns and days scrolled out of view are in the image too.
export function initReportSummaryShot() {
    document.querySelectorAll('[data-rs-shot]').forEach((button) =>
        button.addEventListener('click', async () => {
            const area = document.getElementById(button.dataset.rsShot);
            const label = button.querySelector('[data-rs-shot-label]');
            if (!area) {
                return;
            }
            const background = getComputedStyle(document.body).backgroundColor;
            const surface = getComputedStyle(area).backgroundColor;

            const sheet = document.createElement('div');
            sheet.style.cssText = `position: fixed; top: 0; left: -100000px; display: flex; flex-direction: column; gap: 12px; padding: 20px; width: max-content; background: ${background}`;
            const title = document.createElement('p');
            title.textContent = button.dataset.rsShotTitle;
            title.className = 'text-sm font-bold text-ink';
            // As wide as everything in the section, the parts scrolled out of view too (a full-width table can't size
            // itself to a box that sizes to its content).
            const scrollers = [area, ...area.querySelectorAll('.overflow-x-auto')];
            const fullWidth = Math.max(...scrollers.map((box) => box.scrollWidth));
            const copy = area.cloneNode(true);
            copy.removeAttribute('id');
            copy.style.cssText = `width: ${fullWidth}px; border-radius: 12px; overflow: hidden; background: ${surface}`;
            // Open out every scrolling box, so nothing is cut off at the panel's edge.
            copy.querySelectorAll('.overflow-x-auto').forEach((box) => {
                box.style.overflow = 'visible';
            });
            sheet.append(title, copy);
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
                link.download = button.dataset.rsShotFile;
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
