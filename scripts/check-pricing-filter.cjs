#!/usr/bin/env node
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Explicit offline browser check using real rendered Blade; no server or provider requests.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

async function main() {
    const directory = path.resolve(process.argv[2]);
    const html = fs.readFileSync(path.join(directory, 'pricing-599.html'), 'utf8');
    const activation = fs.readFileSync(path.join(directory, 'activation-599.html'), 'utf8');
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_BIN,
        args: ['--no-sandbox', '--disable-background-networking'] });
    const requests = [], errors = [], cases = [];
    try {
        for (const width of [1440, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 }, serviceWorkers: 'block' });
            await context.route('**/*', route => { requests.push(route.request().url()); return route.abort(); });
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            await page.setContent(html);
            const rows = page.locator('#domains-price-rows tr');
            const visible = page.locator('#domains-price-rows tr:visible');
            const input = page.getByLabel('Filter by TLD');
            const count = page.locator('#domains-price-count');
            const waitCount = async expected => page.waitForFunction(n =>
                [...document.querySelectorAll('#domains-price-rows tr')].filter(row => row.getBoundingClientRect().height > 0).length === n, expected);
            assert.equal(await rows.count(), 599);
            await waitCount(25);
            assert.deepEqual(await rows.evaluateAll(nodes => nodes.slice(0, 3).map(node => node.dataset.tld)), ['com', 'net', 'org']);
            assert.equal(await count.textContent(), '25 of 599 TLDs');
            await page.screenshot({ path: path.join(directory, `pricing-${width}.png`) });
            for (const [query, expected] of [['.COM', 1], ['scale', 596], ['nothing-matches', 0], ['', 25]]) {
                await input.fill(query); await waitCount(expected);
                assert.equal(await visible.count(), expected);
                assert.equal(await page.locator('#domains-price-empty').isVisible(), expected === 0);
                const displayedCount = await count.textContent();
                assert.equal(displayedCount, `${expected} of 599 TLDs${query ? ` (${expected} matching)` : ''}`);
                cases.push({ width, query, visible: expected, count: displayedCount });
            }
            await page.getByRole('button', { name: 'Show all', exact: true }).click(); await waitCount(599);
            assert.equal(await count.textContent(), '599 of 599 TLDs');
            await input.fill('org'); await waitCount(1);
            await input.fill(''); await waitCount(599);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
            assert.equal(overflow, false, `${width}px pricing has no page overflow`);
            await page.setContent(activation);
            await page.locator('summary').click();
            assert.equal(await page.locator('tbody tr').count(), 599);
            const table = page.getByRole('region', { name: 'Costs and prices by TLD' });
            assert.equal(await table.evaluate(node => node.scrollHeight > node.clientHeight && node.clientHeight <= 384), true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
            await page.screenshot({ path: path.join(directory, `activation-${width}.png`) });
            await context.close();
            const noScript = await browser.newContext({ javaScriptEnabled: false, viewport: { width, height: 1000 } });
            await noScript.route('**/*', route => { requests.push(route.request().url()); return route.abort(); });
            const fallback = await noScript.newPage(); await fallback.setContent(html);
            assert.equal(await fallback.locator('#domains-price-rows tr:visible').count(), 599, 'No-script keeps every price visible');
            assert.equal(await fallback.getByLabel('Filter by TLD').isVisible(), false);
            await noScript.close();
        }
        assert.deepEqual(requests, []); assert.deepEqual(errors, []);
        fs.writeFileSync(path.join(directory, 'pricing-filter-receipt.json'), JSON.stringify({ cases, requests, errors, pass: true }, null, 2));
        console.log('Desktop and phone: 599 HTML rows, popular order, filter/count/empty/clear/Show all, no-script fallback and scrollable activation costs passed; 0 requests/errors.');
    } finally { await browser.close(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
