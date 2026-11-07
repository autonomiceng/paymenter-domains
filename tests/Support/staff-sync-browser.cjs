// MIT, Copyright (c) 2026 Autonomic Engineering.
// Real Livewire + Filament assets, no server or provider calls; native PHP answers browser requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const readline = require('node:readline');
const { chromium } = require('playwright');
const input = readline.createInterface({ input: process.stdin });
const replies = [];
input.on('line', line => { const next = replies.shift(); assert.ok(next, 'Reply was requested'); next(JSON.parse(line)); });
let rpcTail = Promise.resolve();
function rpc(message) {
    const result = rpcTail.then(() => new Promise(resolve => {
        replies.push(resolve); process.stdout.write(JSON.stringify(message) + '\n');
    }));
    rpcTail = result;
    return result;
}
async function main() {
    const watchdog = setTimeout(() => { console.error('Staff sync browser exceeded 30 seconds'); process.exit(1); }, 30000);
    watchdog.unref();
    const browser = await chromium.launch({headless: true, executablePath: process.env.CHROME_BIN,
        args: ['--no-sandbox', '--disable-background-networking', '--no-zygote', '--single-process']});
    try {
        const context = await browser.newContext({serviceWorkers: 'block'});
        const page = await context.newPage();
        const errors = [], polls = [];
        page.setDefaultTimeout(8000);
        page.setDefaultNavigationTimeout(8000);
        page.on('pageerror', e => { errors.push(e.message); if (errors.length <= 3) console.error('Page error: ' + e.message); });

        const {html, assets, updatePath} = await rpc({type: 'start'});
        const fixture = process.env.PAYMENTER_TEST_FIXTURE;
        await context.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            if (request.method() === 'POST' && url.pathname === updatePath) {
                const payload = request.postDataJSON();
                console.error('Livewire calls: ' + JSON.stringify(payload.components.map(c => c.calls)));
                for (const component of payload.components) {
                    for (const call of component.calls) if (call.method === 'pollManagement') polls.push(call);
                }
                const response = await rpc({type: 'update', payload});
                return route.fulfill({json: response});
            }
            if (request.isNavigationRequest()) return route.fulfill({contentType: 'text/html', body: html});
            if (url.pathname.includes('/livewire') && url.pathname.endsWith('.js')) {
                return route.fulfill({contentType: 'application/javascript', body: fs.readFileSync(path.join(fixture, 'vendor/livewire/livewire/dist/livewire.js'))});
            }
            if (assets[url.pathname]) return route.fulfill({contentType: 'application/javascript', path: assets[url.pathname]});
            const asset = path.resolve(fixture, 'public', '.' + decodeURIComponent(url.pathname));
            if (asset.startsWith(path.join(fixture, 'public') + path.sep) && fs.existsSync(asset) && fs.statSync(asset).isFile()) {
                return route.fulfill({path: asset});
            }
            if (request.resourceType() === 'script' || request.method() !== 'GET') console.error('Blocked request: ' + request.method() + ' ' + url.pathname);
            return route.abort();
        });
        await page.goto('http://domains-tests.invalid/admin/domains/1');
        await page.waitForFunction(() => !!window.Livewire, null, {timeout: 8000});
        console.error('Native Livewire assets loaded');
        await page.evaluate(() => { window.sameDocument = 'staff-sync-original'; });
        const origin = await page.evaluate(() => performance.timeOrigin);
        assert.equal(await page.getByRole('button', {name: 'Sync now', exact: true}).count(), 1);
        await page.getByRole('button', {name: 'Sync now', exact: true}).click();
        await page.getByText('Sync requested', {exact: true}).waitFor();
        console.error('Browser action shows Sync requested');
        // The real job can take minutes. Poll while it is still pending before finishing it.
        const isPollResponse = response => response.request().method() === 'POST' &&
            response.request().postDataJSON()?.components?.some(c => c.calls.some(call => call.method === 'pollManagement'));
        await page.waitForResponse(isPollResponse);
        await page.waitForResponse(isPollResponse);
        // A staff tab can be hidden while the queued registrar read finishes. Livewire drops
        // 95% of background polls without keep-alive; choose a deterministic throttled tick.
        await page.evaluate(() => {
            Object.defineProperty(document, 'hidden', {configurable: true, get: () => true});
            Math.random = () => 0.5;
            document.dispatchEvent(new Event('visibilitychange'));
        });
        const pendingPolls = polls.length;
        assert.equal((await rpc({type: 'finish'})).finished, true, 'Native worker saved completion');
        // Only the rendered wire:poll may request completion. No $wire call or reload from the test.
        await page.locator('.fi-callout-heading').filter({hasText: "Couldn't read all records"}).waitFor({timeout: 8000});
        assert.ok(polls.length > pendingPolls, 'Real Livewire polling requested completion in a hidden tab');
        assert.equal(await page.evaluate(() => window.sameDocument), 'staff-sync-original');
        assert.equal(await page.evaluate(() => performance.timeOrigin), origin);
        assert.equal(await page.getByText('Sync requested', {exact: true}).count(), 1);
        await page.locator('.domains-trace-title').filter({hasText: "Couldn't read all records"}).waitFor({timeout: 8000});
        assert.equal(await page.locator('[wire\\:key="management-poll-fast"]').count(), 0, 'Completion stops polling');
        assert.deepEqual(errors, [], 'No browser script errors');
        process.stdout.write(JSON.stringify({type: 'passed', detail: 'Chromium Sync now updates completion notice and Activity in the same document via real Livewire polling.'}) + '\n');
    } finally { await browser.close(); input.close(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; input.close(); });
