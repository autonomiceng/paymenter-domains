#!/usr/bin/env node
// Explicit offline check; intentionally outside the default native test runner.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium } = require('playwright');

async function main() {
    const watchdog = setTimeout(() => { console.error('Browser check exceeded 60 seconds'); process.exit(1); }, 60000);
    watchdog.unref();
    const directory = process.argv[2];
    if (!directory) throw new Error('usage: node scripts/check-button-layout.cjs RENDER_DIRECTORY');
    const output = path.resolve(directory);
    const stylesheet = fs.readFileSync(path.join(__dirname, '../Domains/resources/views/partials/styles.blade.php'));
    const stylesheetHash = crypto.createHash('sha256').update(stylesheet).digest('hex');
    const manifest = JSON.parse(fs.readFileSync(path.join(output, 'button-layout-source.json')));
    if (manifest.stylesheet_sha256 !== stylesheetHash) throw new Error('Render artifacts do not match the current stylesheet; render again.');
    const receiptPath = path.join(output, 'button-layout-receipt.json');
    if (fs.existsSync(receiptPath)) throw new Error('Preserve the previous browser receipt; choose a fresh render directory.');
    console.log('Launching offline browser');
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_BIN, timeout: 15000,
        args: ['--no-sandbox', '--disable-background-networking'] });
    console.log('Creating offline context');
    const context = await browser.newContext({ viewport: { width: 320, height: 900 },
        javaScriptEnabled: false, serviceWorkers: 'block' });
    const requests = [];
    await context.route('**/*', route => { requests.push(route.request().url()); return route.abort(); });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.setDefaultNavigationTimeout(10000);
    const cases = [], failures = [];
    const check = (condition, message) => { if (!condition) failures.push(message); };

    async function measure(file, selector, width, fontSize, reference = false) {
        console.log(`Measuring ${file}, ${width}px, ${fontSize || 'default'} text, reference=${reference}`);
        await page.setViewportSize({ width, height: 900 });
        let html = fs.readFileSync(path.join(output, file), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
        const extraStyle = '*{box-sizing:border-box}' +
            (reference ? '.domains .domains-btn{overflow-wrap:normal;line-height:1.25rem}.domains .domains-result__action .domains-btn{display:inline-flex}' : '') +
            (fontSize ? `${selector}{font-size:${fontSize}px}` : '');
        // Shared styles are rendered inside body; keep test overrides after them.
        html = html.replace('</body>', `<style>${extraStyle}</style></body>`);
        // No application scripts, asset server, file navigation or network resources.
        const heads = html.match(/<head\b[^>]*>/gi) || [];
        if (heads.length !== 1) throw new Error(`Expected exactly one opening head tag in ${file}; got ${heads.length}`);
        html = html.replace(/<head\b[^>]*>/i, head => head +
            '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'">');
        await page.setContent(html, { waitUntil: 'domcontentloaded' });
        const mode = await page.evaluate(() => document.compatMode);
        if (mode !== 'CSS1Compat') throw new Error(`Expected standards mode before measurement; got ${mode}`);
        const node = page.locator(selector);
        if (await node.count() !== 1) throw new Error(`Expected one real fixture action: ${selector}`);
        return node.evaluate(button => {
            const box = button.getBoundingClientRect(), style = getComputedStyle(button);
            const range = document.createRange(); range.selectNodeContents(button);
            const text = [...range.getClientRects()].filter(r => r.width && r.height)
                .map(r => ({ left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height }));
            const lines = [...new Set(text.map(r => Math.round(r.top * 10) / 10))];
            return { label: button.textContent.trim(), box: { left: box.left, right: box.right, top: box.top,
                bottom: box.bottom, width: box.width, height: box.height }, text, lines: lines.length,
                fontSize: style.fontSize, lineHeight: style.lineHeight, overflowWrap: style.overflowWrap,
                paddingTop: parseFloat(style.paddingTop), paddingBottom: parseFloat(style.paddingBottom) };
        });
    }

    function contained(result, name) {
        check(result.text.length > 0, `${name}: actual text ranges are required`);
        for (const rect of result.text) {
            check(rect.left >= result.box.left - .5 && rect.right <= result.box.right + .5, `${name}: horizontal text overflow`);
            check(rect.top >= result.box.top - .5 && rect.bottom <= result.box.bottom + .5, `${name}: vertical text overflow`);
        }
    }

    try {
        const transfer = '.domains-result[data-state="transferable"] .domains-btn';
        for (const size of [14, 32]) {
            const current = await measure('button-transfer.html', transfer, 320, size);
            const name = `Transfer at 320px, ${size}px text`;
            cases.push({ name, current }); contained(current, name);
            check(current.label === 'Transfer', `${name}: fixture label`);
            check(Math.abs(current.box.width - 120) < .5, `${name}: real narrow action column`);
            if (size === 14) {
                const reference = await measure('button-transfer.html', transfer, 320, size, true);
                cases.at(-1).reference = reference;
                check(current.lines === 1, `${name}: ordinary label stays on one line`);
                check(JSON.stringify(current.box) === JSON.stringify(reference.box), `${name}: ordinary button geometry changed`);
                check(JSON.stringify(current.text) === JSON.stringify(reference.text), `${name}: ordinary text geometry changed`);
            } else {
                check(current.lines >= 2, `${name}: enlarged word must wrap`);
                const glyphHeight = Math.max(...current.text.map(r => r.height));
                check(parseFloat(current.lineHeight) >= glyphHeight, `${name}: line height must accommodate glyph ranges`);
                const textHeight = Math.max(...current.text.map(r => r.bottom)) - Math.min(...current.text.map(r => r.top));
                check(current.box.height >= textHeight + current.paddingTop + current.paddingBottom, `${name}: wrapped height must retain vertical padding`);
            }
        }
        for (const mode of ['dark', 'light']) for (const width of [320, 360, 768, 1280]) {
            const selector = '#renewal a[href="/invoices/1043"]';
            const current = await measure(`domain-page-invoice-due-${mode}.html`, selector, width);
            const reference = await measure(`domain-page-invoice-due-${mode}.html`, selector, width, null, true);
            const name = `Renewal Pay invoice, ${mode}, ${width}px`;
            cases.push({ name, current, reference }); contained(current, name);
            check(current.label === 'Pay invoice' && current.lines === 1, `${name}: keep the actual renewal action on one line`);
            check(JSON.stringify(current.box) === JSON.stringify(reference.box), `${name}: ordinary action geometry changed`);
            check(JSON.stringify(current.text) === JSON.stringify(reference.text), `${name}: ordinary text geometry changed`);
        }
        for (const width of [320, 360, 768, 1280]) for (const [state, label] of [['available', 'Register'], ['transferable', 'Transfer'], ['error', 'Retry']]) {
            if (width === 320 && label === 'Transfer') continue; // Already measured above.
            const selector = `.domains-result[data-state="${state}"] .domains-btn`;
            const current = await measure('button-transfer.html', selector, width, 14);
            const reference = await measure('button-transfer.html', selector, width, 14, true);
            const name = `${label} at ${width}px, ordinary text`;
            cases.push({ name, current, reference }); contained(current, name);
            check(current.label === label && current.lines === 1, `${name}: ordinary label stays on one line`);
            check(JSON.stringify(current.box) === JSON.stringify(reference.box), `${name}: ordinary button geometry changed`);
            check(JSON.stringify(current.text) === JSON.stringify(reference.text), `${name}: ordinary text geometry changed`);
        }
        check(requests.length === 0, 'Fixtures must not attempt any network resources');
        const receipt = { complete: failures.length === 0, browser: browser.version(), playwright: require('playwright/package.json').version,
            stylesheet_sha256: stylesheetHash, check_sha256: crypto.createHash('sha256').update(fs.readFileSync(__filename)).digest('hex'),
            scope: 'Real Blade with synthetic DTOs and minimal offline shell; text-size override, not browser zoom or installed-app acceptance.',
            network_blocked: true, network_requests: requests.length, real_provider_calls: 0, cases, failures };
        fs.writeFileSync(receiptPath, JSON.stringify(receipt, null, 2) + '\n');
        console.log(JSON.stringify({ complete: receipt.complete, cases: cases.length, failures, receipt: receiptPath }));
        process.exitCode = failures.length ? 1 : 0;
    } finally { await context.close(); await browser.close(); }
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
