// Renders guide.html (with the screenshots) into the user guide PDF.
//   node docs/user-guide/build.mjs
import { chromium } from 'playwright';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const dir = path.dirname(fileURLToPath(import.meta.url));
const out = path.join(dir, 'CRD-Performance-Deck-User-Guide.pdf');

const browser = await chromium.launch({ channel: 'chrome' });
const page = await browser.newPage();
await page.goto(pathToFileURL(path.join(dir, 'guide.html')).href, { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);
await page.pdf({
    path: out,
    format: 'A4',
    printBackground: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate: `<div style="width:100%;font-size:8px;color:#6c7383;padding:0 14mm;display:flex;justify-content:space-between;font-family:sans-serif">
        <span>CRD Performance Deck — User Guide</span><span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>`,
    margin: { top: '16mm', bottom: '18mm', left: '14mm', right: '14mm' },
});
await browser.close();
console.log(`✓ ${path.relative(process.cwd(), out)}`);
