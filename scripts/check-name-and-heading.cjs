#!/usr/bin/env node
// Run by tests/test_rendered_layout.py in the ui suite, or by hand on a render directory (docs/testing.md).
// Real rendered pages, real stylesheets: long staff list text stays inside its exact column width and ends in a
// painted "..." with its full text kept, in either stylesheet order; the customer domain heading and its status mark keep their sizes.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const LONG = 'a-very-long-customer-subdomain-label-that-keeps-going.example-hosting-company.com';
// Staff column contracts in rem (staff-styles.blade.php, OneLine.php).
const NAME = ['domains-name-cell', 16], PERSON = ['domains-cut', 11], NOTE = ['domains-list-note', 13];
// The staff sheet as installed (after Filament's theme), and before it, as a skin or another load order may place it.
const ORDERS = ['staff sheet after theme', 'staff sheet before theme'];
const words = text => text.replace(/\s+/g, ' ').trim();
const sentences = text => words(text).split(/(?<=\.)\s+/).sort();

async function main() {
    const watchdog = setTimeout(() => { console.error('Browser check exceeded 90 seconds'); process.exit(1); }, 90000);
    watchdog.unref();
    const directory = process.argv[2];
    if (!directory) throw new Error('usage: node scripts/check-name-and-heading.cjs RENDER_DIRECTORY');
    const output = path.resolve(directory);
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_BIN, timeout: 15000,
        args: ['--no-sandbox', '--disable-background-networking'] });
    const context = await browser.newContext({ javaScriptEnabled: false, serviceWorkers: 'block' });
    const requests = [];
    await context.route('**/*', route => { requests.push(route.request().url()); return route.abort(); });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const failures = [];
    let measured = 0;
    const check = (condition, message) => { if (!condition) failures.push(message); };

    async function open(file, width, order = ORDERS[0]) {
        await page.setViewportSize({ width, height: 900 });
        let html = fs.readFileSync(path.join(output, file), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
        html = html.replace(/<head\b[^>]*>/i, head => head +
            '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'">');
        // Filament fades its main area in with a script; without scripts it stays transparent.
        html = html.replace('</body>', '<style>.fi-main-ctn{opacity:1!important}</style></body>');
        await page.setContent(html, { waitUntil: 'domcontentloaded' });
        if (order === ORDERS[0]) return true;
        return page.evaluate(() => {
            const styles = [...document.querySelectorAll('style')];
            const staff = styles.filter(style => style.textContent.includes('.domains-name-cell{'));
            // Filament's built theme: the Tailwind build that styles table text items.
            const theme = styles.filter(style => style.textContent.startsWith('/*! tailwindcss') && style.textContent.includes('.fi-ta-text-item'));
            if (staff.length !== 1 || theme.length !== 1 || staff[0] === theme[0]) return false;
            theme[0].before(staff[0]);
            return Boolean(staff[0].compareDocumentPosition(theme[0]) & Node.DOCUMENT_POSITION_FOLLOWING);
        });
    }

    // One staff list text item: a one-line block box inside its exact width contract, cut with a painted "...".
    // full: the text a reader gets, or true for a text only its tooltip states (a note shows its step first).
    // Screen readers read the whole text and the tooltip shows it. long: replace a short fixture value.
    async function measure(name, item, rem, width, { full, long } = {}) {
        measured++;
        const got = await item.evaluate((item, long) => {
            if (long) [...item.childNodes].find(node => node.nodeType === 3 && node.textContent.trim()).textContent = long;
            const style = getComputedStyle(item), range = document.createRange(); range.selectNodeContents(item);
            const tops = new Set([...range.getClientRects()].filter(r => r.width && r.height).map(r => Math.round(r.top)));
            // Filament writes the tooltip as Js::from(): a JSON string body in single quotes.
            const tip = item.querySelector('[x-tooltip]')?.getAttribute('x-tooltip').match(/content:\s*'((?:[^'\\]|\\.)*)'/);
            return { display: style.display, overflow: style.overflowX, textOverflow: style.textOverflow, whiteSpace: style.whiteSpace,
                maxWidth: style.maxWidth, clientWidth: item.clientWidth, scrollWidth: item.scrollWidth, lines: tops.size,
                text: item.textContent, tooltip: tip ? JSON.parse(`"${tip[1]}"`) : null, link: Boolean(item.closest('a[href]')),
                rem: parseFloat(getComputedStyle(document.documentElement).fontSize) };
        }, long ?? null);
        const limit = rem * got.rem;
        // Filament's -webkit-box paints no ellipsis.
        check(got.display === 'block', `${name}: the item is a block box, got ${got.display}`);
        check(got.textOverflow === 'ellipsis', `${name}: text-overflow is ellipsis, got ${got.textOverflow}`);
        check(got.overflow === 'hidden', `${name}: overflow is hidden, got ${got.overflow}`);
        check(got.whiteSpace === 'nowrap', `${name}: white-space is nowrap, got ${got.whiteSpace}`);
        check(got.lines === 1, `${name}: one line, got ${got.lines}`);
        check(got.maxWidth === `${limit}px`, `${name}: max-width is ${rem}rem (${limit}px), got ${got.maxWidth}`);
        // The long text fills the column: exactly the contract, never wider.
        check(Math.abs(got.clientWidth - limit) <= .5, `${name}: exactly ${rem}rem wide, got ${got.clientWidth}px`);
        check(got.scrollWidth > got.clientWidth, `${name}: the long text is cut (${got.scrollWidth}px in ${got.clientWidth}px)`);
        if (full !== undefined) {
            // The cut is visual only: the whole text stays in the item, and the tooltip carries all of it.
            const expected = sentences(full === true ? got.tooltip ?? '' : full).join(' ');
            check(got.tooltip !== null && words(got.tooltip) !== '', `${name}: the item has a tooltip`);
            check(sentences(got.text).join(' ') === expected, `${name}: the item keeps its full text, got ${JSON.stringify(words(got.text))}`);
            check(sentences(got.tooltip ?? '').join(' ') === expected, `${name}: the tooltip has the full text, got ${JSON.stringify(got.tooltip)}`);
        }
        // The browser paints the ellipsis: the item differs from the same item cut without one.
        const cut = await item.screenshot({ animations: 'disabled' });
        await item.evaluate(item => { item.style.textOverflow = 'clip'; });
        const clipped = await item.screenshot({ animations: 'disabled' });
        await item.evaluate(item => { item.style.textOverflow = ''; });
        check(!cut.equals(clipped), `${name}: the cut text ends in a painted ellipsis`);
        if (full === undefined) return;
        // Keyboard focus on the row link shows the whole text in place.
        check(got.link, `${name}: the item is inside a row link`);
        if (!got.link) return;
        const focused = await item.evaluate(item => {
            const link = item.closest('a[href]'); link.focus();
            const style = getComputedStyle(item), result = { visible: link.matches(':focus-visible'), whiteSpace: style.whiteSpace,
                clientWidth: item.clientWidth, scrollWidth: item.scrollWidth };
            link.blur();
            return result;
        });
        check(focused.visible, `${name}: the row link takes keyboard focus`);
        check(focused.whiteSpace === 'normal' && focused.scrollWidth <= focused.clientWidth,
            `${name}: keyboard focus shows the full text (${focused.whiteSpace}, ${focused.scrollWidth}px in ${focused.clientWidth}px)`);
    }

    try {
        for (const order of ORDERS) for (const width of [1440, 900, 390]) {
            // The staff Domains list with real long values: name, customer and Note, each with its tooltip.
            const where = `Staff Domains list, ${order}, ${width}px`;
            check(await open('staff-domains-list.html', width, order), `${where}: one staff sheet and one theme, reordered`);
            const expected = [[NAME, 'long-domain-long-domain-long-domain-long-domain-long-domain-end.example.com'],
                [PERSON, 'Maximiliana-Alexandrina Featherstonehaugh-Worthington'], [NOTE, null]];
            for (const [[kind, rem], value] of expected) {
                const name = `${where}, ${kind}`;
                const items = page.locator(`.fi-ta-text-item.${kind}`).filter({ has: page.locator('[x-tooltip]') });
                const count = await items.count();
                if (kind === NOTE[0]) {
                    // Every held row has a note: check each one.
                    check(count >= 1, `${name}: a rendered note`);
                    for (let i = 0; i < count; i++) await measure(`${name} ${i + 1}`, items.nth(i), rem, width, { full: true });
                    continue;
                }
                check(count === 1, `${name}: one rendered long item, got ${count}`);
                if (count === 1) await measure(name, items, rem, width, { full: value });
            }
            // The monitoring list's name and person items, with a name too long for its column.
            for (const state of ['paused', 'retired']) {
                const at = `Staff monitoring list, ${state}, ${order}, ${width}px`;
                check(await open(`r14-watchlist-${state}.html`, width, order), `${at}: one staff sheet and one theme, reordered`);
                for (const [kind, rem] of [NAME, PERSON]) {
                    const items = page.locator(`.fi-ta-text-item.${kind}`);
                    check(await items.count() === 1, `${at}, ${kind}: one rendered item`);
                    if (await items.count() === 1) await measure(`${at}, ${kind}`, items, rem, width, { long: LONG });
                }
            }
        }
        // The customer domain heading: clamp(1.125rem, 5vw, 1.5rem), and the head-size status mark.
        for (const mode of ['dark', 'light']) for (const width of [320, 390, 768, 1280]) {
            const name = `Domain heading, ${mode}, ${width}px`;
            await open(`domain-page-invoice-due-${mode}.html`, width);
            const heading = page.locator('.domains-ph > h1');
            check(await heading.count() === 1, `${name}: one page heading`);
            if (await heading.count() !== 1) continue;
            measured++;
            const got = await heading.evaluate(h1 => {
                const style = getComputedStyle(h1), mark = h1.parentElement.querySelector('.domains-status');
                const before = mark ? getComputedStyle(mark, '::before') : null;
                return { text: h1.textContent.trim(), size: parseFloat(style.fontSize), lineHeight: parseFloat(style.lineHeight),
                    weight: style.fontWeight, mark: before ? [before.width, before.height] : null };
            });
            const expected = Math.min(24, Math.max(18, width * .05));
            check(got.text === 'invoice-due.example', `${name}: fixture heading`);
            check(Math.abs(got.size - expected) < .05, `${name}: font size ${expected}px, got ${got.size}px`);
            check(Math.abs(got.lineHeight - expected * 1.5) < .1, `${name}: line height ${expected * 1.5}px, got ${got.lineHeight}px`);
            check(got.weight === '600', `${name}: weight 600, got ${got.weight}`);
            check(JSON.stringify(got.mark) === JSON.stringify(['18px', '20px']), `${name}: head status mark 18 by 20px, got ${JSON.stringify(got.mark)}`);
        }
        check(requests.length === 0, 'Fixtures must not attempt any network resources');
        console.log(JSON.stringify({ complete: failures.length === 0, browser: browser.version(), measured, failures }));
        process.exitCode = failures.length ? 1 : 0;
    } finally { await context.close(); await browser.close(); }
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
