#!/usr/bin/env node
// Explicit offline check; intentionally outside the default native test runner.
// The real customer stylesheet: a label keeps its words whole however long the line beside it runs.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const styles = fs.readFileSync(path.join(__dirname, '../Domains/resources/views/partials/styles.blade.php'), 'utf8')
    .replace(/^[\s\S]*?<style>/, '').replace(/<\/style>[\s\S]*$/, '');
const help = '<span class="domains-help" data-help><button type="button" class="domains-help-btn">?</button></span>';
const result = '<span class="domains-sub" role="status">Email request accepted. Check the registrant email.</span>';
const long = 'We email it to the registrant email address on file for this domain name';
const html = `<div class="domains"><ul class="domains-rows">
<li class="domains-row"><span class="domains-row__l">Auth code${help}${result}</span><span class="domains-row__v">${long}</span></li>
<li class="domains-row"><span class="domains-row__l">Transfer lock${help}<span class="domains-sub" role="status">Saved.</span></span><span class="domains-row__v">On</span><span class="domains-row__a"><a class="domains-a">Turn off</a></span></li>
</ul><dl class="domains-card"><div><dt>Auto-renew ${help}</dt><dd>${long} ${long}</dd></div></dl></div>`;

// A word the browser broke has more than one line box.
const broken = () => [...document.querySelectorAll('.domains-row__l, .domains-card dt')].flatMap((label) => {
    const text = [...label.childNodes].find((node) => node.nodeType === 3 && node.textContent.trim());
    return [...text.textContent.matchAll(/\S+/g)].filter((word) => {
        const range = document.createRange();
        range.setStart(text, word.index);
        range.setEnd(text, word.index + word[0].length);
        return range.getClientRects().length > 1;
    }).map((word) => word[0]);
});

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_BIN });
    const page = await browser.newPage();
    const failures = [];
    for (const width of [1920, 1440, 1100, 901, 900, 390, 360, 320]) {
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(`<!doctype html><style>*{box-sizing:border-box}${styles}</style>${html}`);
        const words = await page.evaluate(broken);
        if (words.length) failures.push(`${width}px: ${words.join(', ')}`);
    }
    await browser.close();
    if (failures.length) { console.error(`Labels broke mid-word\n${failures.join('\n')}`); process.exit(1); }
    console.log('Labels keep their words whole');
})().catch((error) => { console.error(error); process.exit(1); });
