#!/usr/bin/env node
// Run by tests/test_rendered_layout.py in the ui suite, or by hand on a render directory (docs/testing.md).
// Real rendered pages, real stylesheets: long staff list text stays inside the approved table's column widths and ends in
// a painted "..." with its full text kept, in either stylesheet order; the customer domain heading and its status mark keep their sizes.
// The page's own staff reveal script runs (no other script does): keyboard focus shows a cut text over the table, and only
// a cut text in a row that opens nothing takes a tab stop.
// Optional second argument: lists (the Domains and monitoring lists and the domain heading) or managed (the DNS
// monitoring setup list). Without it, everything.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const LONG = 'a-very-long-customer-subdomain-label-that-keeps-going.example-hosting-company.com';
// Staff column contracts in px, padding included (staff-styles.blade.php, OneLine.php): the approved staff table gives a
// domain name 326px and a customer 120px; a list Note takes the rest of the row, and 160px when the table scrolls.
const NAME = ['domains-name-cell', 326], PERSON = ['domains-cut', 120], NOTE = ['domains-list-note', 160];
// The staff sheet as installed (after Filament's theme), and before it, as a skin or another load order may place it.
const ORDERS = ['staff sheet after theme', 'staff sheet before theme'];
const words = text => text.replace(/\s+/g, ' ').trim();
const sentences = text => words(text).split(/(?<=\.)\s+/).sort();
const SCOPES = ['lists', 'managed'];
// Two frames: the reveal script's queued scan and its resize observer have run.
const frames = () => new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)));

async function main() {
    const watchdog = setTimeout(() => { console.error('Browser check exceeded 90 seconds'); process.exit(1); }, 90000);
    watchdog.unref();
    const directory = process.argv[2];
    if (!directory) throw new Error('usage: node scripts/check-name-and-heading.cjs RENDER_DIRECTORY [lists|managed]');
    const scopes = process.argv[3] ? [process.argv[3]] : SCOPES;
    if (!scopes.every(scope => SCOPES.includes(scope))) throw new Error(`Unknown scope: ${process.argv[3]}`);
    const output = path.resolve(directory);
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_BIN, timeout: 15000,
        args: ['--no-sandbox', '--disable-background-networking'] });
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const requests = [];
    await context.route('**/*', route => { requests.push(route.request().url()); return route.abort(); });
    let page = null;
    const failures = [], columns = {};
    let measured = 0;
    const check = (condition, message) => { if (!condition) failures.push(message); };

    // A staff list page: only its staff reveal script may run, by nonce. staff: check that it ran.
    async function open(file, width, order = ORDERS[0], staff = null) {
        // A new tab per page: the reveal script runs once per window, as on a real page load.
        await page?.close();
        page = await context.newPage();
        page.setDefaultTimeout(10000);
        await page.setViewportSize({ width, height: 900 });
        let html = fs.readFileSync(path.join(output, file), 'utf8')
            .replace(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi, (tag, attributes, body) =>
                /\bdata-domains-reveal\b/.test(attributes) ? `<script nonce="domains-reveal"${attributes}>${body}</script>` : '');
        html = html.replace(/<head\b[^>]*>/i, head => head + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; ' +
            'style-src \'unsafe-inline\'; script-src \'nonce-domains-reveal\'">');
        // Filament fades its main area in with a script; without its scripts it stays transparent.
        html = html.replace('</body>', '<style>.fi-main-ctn{opacity:1!important}</style></body>');
        await page.setContent(html, { waitUntil: 'domcontentloaded' });
        const ordered = order === ORDERS[0] || await page.evaluate(() => {
            const styles = [...document.querySelectorAll('style')];
            const staff = styles.filter(style => style.textContent.includes('.domains-name-cell{'));
            // The panel's built theme: the largest sheet, the Tailwind build that styles table text items.
            const theme = styles.filter(style => style !== staff[0] && style.textContent.includes('.fi-ta-text-item'))
                .sort((a, b) => b.textContent.length - a.textContent.length).slice(0, 1);
            if (staff.length !== 1 || theme.length !== 1) return false;
            theme[0].before(staff[0]);
            return Boolean(staff[0].compareDocumentPosition(theme[0]) & Node.DOCUMENT_POSITION_FOLLOWING);
        });
        await page.evaluate(frames);
        if (staff) check(await page.evaluate(() => window.domainsReveal === true), `${staff}: the page runs the staff reveal script`);
        return ordered;
    }
    // No cut text in a linked row takes a tab stop of its own: the row link takes focus.
    async function linkedStops(where) {
        const stops = await page.locator(':is(.domains-cut,.domains-line,.domains-name-cell)[tabindex]').count();
        check(stops === 0, `${where}: rows open a page, so no text adds a tab stop (got ${stops})`);
    }

    // One staff list text item: a one-line block box inside its width contract, cut with a painted "...".
    // px: its exact width, or with fill its least width, filling its cell. column: the list's Note takes the spare width.
    // full: the text a reader gets, or true for a text only its tooltip states (a note shows its step first).
    // Screen readers read the whole text and the tooltip shows it. long: replace a short fixture value.
    // focus: check the keyboard reveal also without full (it always runs with full).
    async function measure(name, item, px, width, { full, long, fill, column, focus } = {}) {
        measured++;
        const got = await item.evaluate(async (item, long) => {
            if (long) {
                // The first nonblank text, inside its tooltip span when it has one.
                const walker = document.createTreeWalker(item, NodeFilter.SHOW_TEXT);
                let node;
                while ((node = walker.nextNode()) && !node.textContent.trim());
                node.textContent = long;
                // The reveal script's queued scan and resize observer have run.
                await new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)));
            }
            const style = getComputedStyle(item), range = document.createRange(); range.selectNodeContents(item);
            const tops = new Set([...range.getClientRects()].filter(r => r.width && r.height).map(r => Math.round(r.top)));
            // Filament writes the tooltip as Js::from(): a JSON string body in single quotes.
            const tip = item.querySelector('[x-tooltip]')?.getAttribute('x-tooltip').match(/content:\s*'((?:[^'\\]|\\.)*)'/);
            return { display: style.display, overflow: style.overflowX, textOverflow: style.textOverflow, whiteSpace: style.whiteSpace,
                maxWidth: style.maxWidth, clientWidth: item.clientWidth, scrollWidth: item.scrollWidth, lines: tops.size,
                text: item.textContent, tooltip: tip ? JSON.parse(`"${tip[1]}"`) : null,
                width: item.getBoundingClientRect().width, cell: cellInner(item.closest('td')) };
            function cellInner(cell) {
                const style = getComputedStyle(cell), side = edge => parseFloat(style[`padding${edge}`]) + parseFloat(style[`border${edge}Width`]);
                return cell.getBoundingClientRect().width - side('Left') - side('Right');
            }
        }, long ?? null);
        // Filament's -webkit-box paints no ellipsis.
        check(got.display === 'block', `${name}: the item is a block box, got ${got.display}`);
        check(got.textOverflow === 'ellipsis', `${name}: text-overflow is ellipsis, got ${got.textOverflow}`);
        check(got.overflow === 'hidden', `${name}: overflow is hidden, got ${got.overflow}`);
        check(got.whiteSpace === 'nowrap', `${name}: white-space is nowrap, got ${got.whiteSpace}`);
        check(got.lines === 1, `${name}: one line, got ${got.lines}`);
        if (fill) {
            // A Note fills its cell: the rest of the row, never narrower than its contract.
            check(got.maxWidth === 'none', `${name}: no max-width, got ${got.maxWidth}`);
            check(got.clientWidth >= px - .5, `${name}: at least ${px}px wide, got ${got.clientWidth}px`);
        } else {
            check(got.maxWidth === `${px}px`, `${name}: max-width is ${px}px, got ${got.maxWidth}`);
            // The long text fills the column: exactly the contract, never wider.
            check(Math.abs(got.clientWidth - px) <= .5, `${name}: exactly ${px}px wide, got ${got.clientWidth}px`);
        }
        // The column is the item: the table gives its spare width to the Note, not to a cut name.
        if (column) check(Math.abs(got.cell - got.width) <= .5, `${name}: its cell is no wider than the item (${got.cell}px for ${got.width}px)`);
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
        if (full === undefined && !focus) return;
        // Keyboard focus on the row's link or button, or, in a row that opens nothing, on the cut text itself (then in the
        // tab order with a focus ring), shows the whole text in an overlay. No cell or row of the table moves or resizes.
        const focused = await item.evaluate(async item => {
            const settle = () => new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)));
            const target = item.closest('a[href], button') ?? (item.getAttribute('tabindex') === '0' ? item : null);
            if (!target) return null;
            const table = item.closest('table');
            const shape = () => {
                const origin = table.getBoundingClientRect();
                return [origin.width, origin.height, ...[...table.querySelectorAll('th, td')].flatMap(cell => {
                    const box = cell.getBoundingClientRect();
                    return [box.left - origin.left, box.top - origin.top, box.width, box.height];
                })];
            };
            const before = shape();
            target.focus({ preventScroll: true, focusVisible: true });
            await settle();
            const after = shape(), ring = getComputedStyle(target);
            const overlay = [...document.querySelectorAll('.domains-reveal')].find(node => !node.hidden && node.getClientRects().length);
            const box = overlay?.getBoundingClientRect();
            const result = { visible: target.matches(':focus-visible'), self: target === item,
                ring: ring.outlineStyle !== 'none' && parseFloat(ring.outlineWidth) > 0,
                moved: before.length === after.length ? Math.max(...before.map((value, i) => Math.abs(value - after[i]))) : Infinity,
                text: item.textContent, cut: item.scrollWidth > item.clientWidth,
                overlay: overlay ? { text: overlay.textContent, position: getComputedStyle(overlay).position,
                    fits: overlay.scrollWidth <= overlay.clientWidth && overlay.scrollHeight <= overlay.clientHeight,
                    inside: box.left >= 0 && box.top >= 0 && box.right <= innerWidth && box.bottom <= innerHeight } : null };
            target.blur();
            await settle();
            result.closed = ![...document.querySelectorAll('.domains-reveal')].some(node => !node.hidden && node.getClientRects().length);
            return result;
        });
        check(focused !== null, `${name}: keyboard focus can reach the cut text (a row link or button, or the text itself)`);
        if (!focused) return;
        check(focused.visible, `${name}: the ${focused.self ? 'cut text' : 'row link'} takes keyboard focus`);
        if (focused.self) check(focused.ring, `${name}: the focused cut text shows a focus ring`);
        check(focused.moved <= .5, `${name}: keyboard focus leaves every cell and row as it is (moved ${focused.moved}px)`);
        check(focused.cut, `${name}: the focused text stays cut in its cell`);
        check(focused.overlay !== null, `${name}: keyboard focus shows the full text in an overlay`);
        if (!focused.overlay) return;
        const expected = full === undefined ? words(focused.text) : sentences(full === true ? got.tooltip ?? '' : full).join(' ');
        const shown = full === undefined ? words(focused.overlay.text) : sentences(focused.overlay.text).join(' ');
        check(shown === expected, `${name}: the overlay has the full text, got ${JSON.stringify(focused.overlay.text)}`);
        check(focused.overlay.position === 'fixed', `${name}: the overlay is fixed over the page, got ${focused.overlay.position}`);
        check(focused.overlay.fits && focused.overlay.inside, `${name}: the overlay shows all its text inside the viewport`);
        check(focused.closed, `${name}: the overlay closes when focus leaves`);
    }

    // In a row that opens nothing, a text takes a tab stop only while it is cut: a fitting value has none, and the stop
    // follows the text and its width.
    async function cutStops(name, item) {
        const got = await item.evaluate(async item => {
            const settle = () => new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)));
            const walker = document.createTreeWalker(item, NodeFilter.SHOW_TEXT);
            let node;
            while ((node = walker.nextNode()) && !node.textContent.trim());
            const long = node.textContent, stop = () => item.getAttribute('tabindex'), cut = () => item.scrollWidth > item.clientWidth;
            const result = { long: [stop(), cut()] };
            node.textContent = 'a@b.test'; await settle(); result.short = [stop(), cut()];
            node.textContent = long; await settle(); result.again = [stop(), cut()];
            item.style.maxWidth = 'none'; await settle(); result.wide = [stop(), cut()];
            item.style.maxWidth = ''; await settle(); result.narrow = [stop(), cut()];
            return result;
        });
        const expect = { long: ['0', true], short: [null, false], again: ['0', true], wide: [null, false], narrow: ['0', true] };
        const say = { long: 'the long text is cut and takes a tab stop', short: 'a fitting value (a@b.test) takes no tab stop',
            again: 'cut again, it takes its tab stop back', wide: 'widened until it fits, it gives up its tab stop',
            narrow: 'narrowed back, it takes its tab stop again' };
        for (const [step, want] of Object.entries(expect)) {
            check(JSON.stringify(got[step]) === JSON.stringify(want), `${name}: ${say[step]} (tabindex, cut: ${JSON.stringify(got[step])})`);
        }
    }

    try {
        for (const order of ORDERS) for (const width of [1440, 900, 390]) {
            if (scopes.includes('lists')) await lists(order, width);
            if (scopes.includes('managed')) await managed(order, width);
        }
        if (scopes.includes('lists')) await headings();
        check(requests.length === 0, 'Fixtures must not attempt any network resources');
        console.log(JSON.stringify({ complete: failures.length === 0, browser: browser.version(), scopes, measured, columns, failures }));
        process.exitCode = failures.length ? 1 : 0;
    } finally { await context.close(); await browser.close(); }

    async function lists(order, width) {
        // The staff Domains list with real long values: name, customer and Note, each with its tooltip.
        const where = `Staff Domains list, ${order}, ${width}px`;
        check(await open('staff-domains-list.html', width, order, where), `${where}: one staff sheet and one theme, reordered`);
        // Wide, the table fits its card; narrower, it scrolls inside its card and the page does not (table standard, Phones).
        const fit = await page.evaluate(() => {
            const table = document.querySelector('.fi-ta-table'), card = table.closest('.fi-ta-content-ctn') ?? table.parentElement;
            const head = label => [...table.querySelectorAll('thead th')].find(th => th.textContent.trim() === label)?.getBoundingClientRect().width;
            return { card: card.clientWidth, table: card.scrollWidth, page: document.documentElement.scrollWidth, view: innerWidth,
                columns: ['Domain', 'Customer', 'Note'].map(label => Math.round(head(label) * 10) / 10) };
        });
        columns[`${order}, ${width}px`] = fit.columns;
        check(fit.page <= fit.view, `${where}: no page scroll (${fit.page}px in ${fit.view}px)`);
        if (width === 1440) check(fit.table <= fit.card, `${where}: the table fits its card (${fit.table}px in ${fit.card}px)`);
        const expected = [[NAME, 'long-domain-long-domain-long-domain-long-domain-long-domain-end.example.com'],
            [PERSON, 'Maximiliana-Alexandrina Featherstonehaugh-Worthington'], [NOTE, null]];
        for (const [[kind, px], value] of expected) {
            const name = `${where}, ${kind}`;
            // Every Note with text; the one long name and customer. Each must carry its tooltip (measure).
            const items = page.locator(`.fi-ta-text-item.${kind}`).filter({ hasText: value ?? /\S/ });
            const count = await items.count();
            if (kind === NOTE[0]) {
                // Every held row has a note: check each one.
                check(count >= 1, `${name}: a rendered note`);
                for (let i = 0; i < count; i++) await measure(`${name} ${i + 1}`, items.nth(i), px, width, { full: true, fill: true, column: true });
                continue;
            }
            check(count === 1, `${name}: one rendered long item, got ${count}`);
            if (count === 1) await measure(name, items, px, width, { full: value, column: true });
        }
        await linkedStops(where);
        // The monitoring list's name and person items, with a name too long for its column.
        for (const state of ['paused', 'retired']) {
            const at = `Staff monitoring list, ${state}, ${order}, ${width}px`;
            check(await open(`r14-watchlist-${state}.html`, width, order, at), `${at}: one staff sheet and one theme, reordered`);
            for (const [kind, px] of [NAME, PERSON]) {
                const items = page.locator(`.fi-ta-text-item.${kind}`);
                check(await items.count() === 1, `${at}, ${kind}: one rendered item`);
                if (await items.count() === 1) await measure(`${at}, ${kind}`, items, px, width, { long: LONG, focus: true });
            }
            await linkedStops(at);
        }
    }

    async function managed(order, width) {
        // The DNS monitoring setup list: its rows open nothing, so each cut text must take focus itself.
        const where = `Staff DNS monitoring setup list, ${order}, ${width}px`;
        check(await open('staff-managed-dns.html', width, order, where), `${where}: one staff sheet and one theme, reordered`);
        const account = 'maximiliana.featherstonehaugh-worthington@example.test';
        const customers = page.locator(`.fi-ta-text-item.${PERSON[0]}`).filter({ hasText: account });
        check(await customers.count() === 1, `${where}, ${PERSON[0]}: one rendered long item`);
        if (await customers.count() !== 1) return;
        await measure(`${where}, ${PERSON[0]}`, customers, PERSON[1], width, { full: account });
        await cutStops(`${where}, ${PERSON[0]}`, customers);
        // Real Tab presses from the page start reach the cut text, and its overlay shows.
        await page.evaluate(() => document.activeElement?.blur());
        let reached = false;
        for (let press = 0; press < 40 && !reached; press++) {
            await page.keyboard.press('Tab');
            reached = await customers.evaluate(item => document.activeElement === item);
        }
        check(reached, `${where}, ${PERSON[0]}: Tab presses from the page start reach the cut text`);
        if (reached) check(await page.locator('.domains-reveal:not([hidden])').count() === 1, `${where}, ${PERSON[0]}: Tab focus shows the overlay`);
        await page.evaluate(() => document.activeElement?.blur());
    }

    async function headings() {
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
    }
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
