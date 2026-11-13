#!/usr/bin/env node
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Real Blade fixtures retain complete data, record links and accessible page identities.
// Screenshots beside approved mocks cover presentation; this check guards behaviour.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const SCOPES = ['staff', 'watch', 'heading', 'managed'];

async function main() {
    const output = path.resolve(process.argv[2]);
    const scopes = process.argv[3] ? [process.argv[3]] : SCOPES;
    if (!scopes.every(scope => [...SCOPES, 'lists'].includes(scope))) throw new Error('Unknown scope');
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_BIN,
        args: ['--no-sandbox', '--disable-background-networking'] });
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const requests = [], failures = [];
    await context.route('**/*', route => { requests.push(route.request().url()); return route.abort(); });
    let measured = 0;
    const check = (ok, why) => { if (!ok) failures.push(why); };
    const page = await context.newPage();
    async function open(file, width) {
        await page.setViewportSize({ width, height: 900 });
        // No scripts, and a policy that blocks every outside resource, such as the core avatar image, before it is requested.
        const html = fs.readFileSync(path.join(output, file), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
            .replace(/<head\b[^>]*>/i, head => head + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; font-src data:">');
        await page.setContent(html, { waitUntil: 'domcontentloaded' });
    }
    async function fullText(text, where) {
        measured++;
        check((await page.locator('body').textContent()).includes(text), `${where}: full fixture data remains readable`);
    }
    async function table(where) {
        check(await page.locator('table tbody tr').count() > 0, `${where}: fixture rows rendered`);
        const links = await page.locator('table tbody a[href]').evaluateAll(links => links.map(link => ({
            href: link.getAttribute('href'), text: link.textContent.trim(), tabindex: link.getAttribute('tabindex'),
        })));
        check(links.length > 0 && links.every(link => link.href && link.href !== '#' && link.text && link.tabindex !== '-1'),
            `${where}: record links keep their destination and accessible name`);
    }
    try {
        for (const width of [1440, 900, 390]) {
            if (scopes.includes('lists') || scopes.includes('staff')) {
                const where = `Domains at ${width}`;
                await open('staff-domains-list.html', width);
                await table(where);
                await fullText('long-domain-long-domain-long-domain-long-domain-long-domain-end.example.com', where);
                await fullText('Maximiliana-Alexandrina', where);
                await fullText('Featherstonehaugh-Worthington', where);
            }
            if (scopes.includes('lists') || scopes.includes('watch')) {
                for (const state of ['paused', 'retired']) {
                    const where = `Monitoring ${state} at ${width}`;
                    await open(`r14-watchlist-${state}.html`, width);
                    await table(where);
                    measured++;
                    check(await page.locator('.domains-name-cell').count() > 0, `${where}: domain identity remains in the list`);
                }
            }
            if (scopes.includes('managed')) {
                await open('staff-managed-dns.html', width);
                check(await page.locator('table tbody tr').count() > 0, 'DNS setup fixture rows rendered');
                await fullText('maximiliana.featherstonehaugh-worthington@example.test', `DNS setup at ${width}`);
            }
        }
        if (scopes.includes('lists') || scopes.includes('heading')) {
            for (const mode of ['dark', 'light']) for (const width of [320, 390, 768, 1280]) {
                await open(`domain-page-invoice-due-${mode}.html`, width);
                const heading = page.locator('h1');
                measured++;
                check(await heading.count() === 1 && (await heading.textContent()).includes('invoice-due.example'),
                    `Customer ${mode} at ${width}: one heading names the current record`);
            }
        }
        check(requests.length === 0, 'Fixtures attempt no network resources');
        console.log(JSON.stringify({ complete: failures.length === 0, browser: browser.version(), scopes, measured, failures }));
        process.exitCode = failures.length ? 1 : 0;
    } finally { await context.close(); await browser.close(); }
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
