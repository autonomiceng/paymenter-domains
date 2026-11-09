#!/usr/bin/env node
// Explicit offline check; intentionally outside the default native test runner.
// Real rendered pages, real stylesheets: long staff list text ends in a painted "..." on its own text item,
// and the customer domain heading and its status mark keep their sizes.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const LONG = 'a-very-long-customer-subdomain-label-that-keeps-going.example-hosting-company.com';

async function main() {
    const watchdog = setTimeout(() => { console.error('Browser check exceeded 60 seconds'); process.exit(1); }, 60000);
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
    const check = (condition, message) => { if (!condition) failures.push(message); };

    async function open(file, width) {
        await page.setViewportSize({ width, height: 900 });
        let html = fs.readFileSync(path.join(output, file), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
        html = html.replace(/<head\b[^>]*>/i, head => head +
            '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'">');
        // Filament fades its main area in with a script; without scripts it stays transparent.
        html = html.replace('</body>', '<style>.fi-main-ctn{opacity:1!important}</style></body>');
        await page.setContent(html, { waitUntil: 'domcontentloaded' });
    }

    try {
        // The rendered staff list's own name item, with a name too long for its column.
        for (const state of ['paused', 'retired']) for (const width of [1440, 900]) for (const [kind, max] of [['domains-name-cell', 24], ['domains-cut', 11]]) {
            const name = `Staff list ${kind}, ${state}, ${width}px`;
            await open(`r14-watchlist-${state}.html`, width);
            const items = page.locator(`.fi-ta-text-item.${kind}`);
            check(await items.count() === 1, `${name}: one rendered name item`);
            if (await items.count() !== 1) continue;
            const got = await items.evaluate((item, long) => {
                const text = [...item.childNodes].find(node => node.nodeType === 3 && node.textContent.trim());
                text.textContent = long;
                const style = getComputedStyle(item), range = document.createRange(); range.selectNodeContents(item);
                const tops = new Set([...range.getClientRects()].filter(r => r.width && r.height).map(r => Math.round(r.top)));
                return { display: style.display, overflow: style.overflowX, textOverflow: style.textOverflow, whiteSpace: style.whiteSpace,
                    clientWidth: item.clientWidth, scrollWidth: item.scrollWidth, lines: tops.size,
                    rem: parseFloat(getComputedStyle(document.documentElement).fontSize) };
            }, LONG);
            // Filament's -webkit-box paints no ellipsis.
            check(got.display === 'block', `${name}: the item is a block box, got ${got.display}`);
            check(got.textOverflow === 'ellipsis', `${name}: text-overflow is ellipsis, got ${got.textOverflow}`);
            check(got.overflow === 'hidden', `${name}: overflow is hidden, got ${got.overflow}`);
            check(got.whiteSpace === 'nowrap', `${name}: white-space is nowrap, got ${got.whiteSpace}`);
            check(got.lines === 1, `${name}: one line, got ${got.lines}`);
            check(got.clientWidth <= max * got.rem + .5, `${name}: at most ${max}rem wide, got ${got.clientWidth}px`);
            check(got.scrollWidth > got.clientWidth, `${name}: the long name is cut (${got.scrollWidth}px in ${got.clientWidth}px)`);
            // The browser paints the ellipsis: the item differs from the same item cut without one.
            const cut = await items.screenshot({ animations: 'disabled' });
            await items.evaluate(item => { item.style.textOverflow = 'clip'; });
            const clipped = await items.screenshot({ animations: 'disabled' });
            check(!cut.equals(clipped), `${name}: the cut name ends in a painted ellipsis`);
        }
        // The customer domain heading: clamp(1.125rem, 5vw, 1.5rem), and the head-size status mark.
        for (const mode of ['dark', 'light']) for (const width of [320, 390, 768, 1280]) {
            const name = `Domain heading, ${mode}, ${width}px`;
            await open(`domain-page-invoice-due-${mode}.html`, width);
            const heading = page.locator('.domains-ph > h1');
            check(await heading.count() === 1, `${name}: one page heading`);
            if (await heading.count() !== 1) continue;
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
        console.log(JSON.stringify({ complete: failures.length === 0, browser: browser.version(), failures }));
        process.exitCode = failures.length ? 1 : 0;
    } finally { await context.close(); await browser.close(); }
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
