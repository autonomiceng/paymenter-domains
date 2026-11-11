// Staff Cancel and refund on held paid renewals, clicked in Chromium. MIT, Copyright (c) 2026 Autonomic Engineering.
'use strict';
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const [base, seedFile, out] = process.argv.slice(2);
const seed = JSON.parse(fs.readFileSync(seedFile, 'utf8'));
const receipt = { steps: [], requests: [], console: [], errors: [] };
// Paymenter serves Livewire updates at /paymenter/update.
const isUpdate = (url) => /\/update$/.test(new URL(url).pathname);
const REASON = 'Customer asked to cancel this renewal';

function watch(page) {
  page.on('console', (m) => { if (m.type() === 'error') receipt.console.push(m.text()); });
  page.on('pageerror', (e) => receipt.errors.push(String(e)));
  page.on('response', (r) => { if (r.status() >= 400) receipt.console.push(`status ${r.status()}: ${r.url()}`); });
  page.on('request', (r) => {
    if (r.method() !== 'POST') return;
    let calls = null;
    try { calls = (JSON.parse(r.postData() || '{}').components ?? []).flatMap((c) => (c.calls ?? []).map((call) => call.method)); } catch { calls = null; }
    receipt.requests.push({ url: new URL(r.url()).pathname, calls });
  });
}

async function state(page, label) {
  const row = await page.evaluate(() => {
    const visible = (el) => !!el && el.offsetParent !== null;
    const root = [...document.querySelectorAll('[wire\\:id]')].find((el) => (el.getAttribute('wire:name') ?? '').includes('ViewOperation'));
    const component = root && window.Livewire?.find(root.getAttribute('wire:id'));
    const modal = [...document.querySelectorAll('.fi-modal-window')].find(visible);
    const mounted = component ? JSON.parse(JSON.stringify(component.get('mountedActions') ?? [])) : null;
    return {
      modalOpen: !!modal,
      reason: modal?.querySelector('textarea')?.value ?? null,
      mounted: mounted?.map((a) => ({ name: a.name, reason: a.data?.reason ?? null })) ?? null,
      menu: [...document.querySelectorAll('.fi-dropdown-list-item')].map((n) => n.innerText.trim()),
      notifications: [...document.querySelectorAll('.fi-no-notification')].filter(visible).map((n) => n.innerText.replace(/\s+/g, ' ').trim()),
      text: (document.querySelector('.fi-page') ?? document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 3000),
    };
  });
  const shot = path.join(out, `${String(receipt.steps.length + 1).padStart(2, '0')}-${label}.png`);
  await page.screenshot({ path: shot });
  receipt.steps.push({ label, ...row, shot });
  return row;
}

async function open(page, id) {
  await page.goto(`${base}/admin/registrar-requests/${id}`);
  await page.waitForFunction(() => !!window.Livewire && !!document.querySelector('[wire\\:id]'));
}

async function openRefund(page) {
  await page.click('button:has-text("More")');
  await page.click('.fi-dropdown-list-item:has-text("Cancel and refund")');
  await page.waitForLoadState('networkidle');
  await page.waitForSelector('.fi-modal-window textarea', { state: 'visible' });
  await page.locator('.fi-modal-window textarea').click();
  await page.keyboard.type(REASON);
}

async function confirm(page) {
  const button = page.locator('.fi-modal-window:visible .fi-modal-footer button', { hasText: 'Cancel and refund' });
  const reply = page.waitForResponse((r) => isUpdate(r.url()), { timeout: 30000 });
  await button.click();
  await reply;
  // Filament shows the notice on the client after the reply; a dialog that only reopens shows none, so the wait ends quietly.
  await page.waitForFunction(() => [...document.querySelectorAll('.fi-no-notification')].some((el) => el.offsetParent !== null),
    null, { timeout: 5000 }).catch(() => {});
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || undefined });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'dark' });
  await context.route((url) => !url.href.startsWith(base), (route) => route.abort());
  try {
    const first = await context.newPage();
    watch(first);
    await first.goto(`${base}/refund-browser-login`);
    // A second tab holds an open dialog for the same request: its later click is the duplicate.
    const second = await context.newPage();
    watch(second);
    await open(second, seed.refund.id);
    await openRefund(second);
    await open(first, seed.refund.id);
    await openRefund(first);
    await state(first, 'reason-typed');
    await confirm(first);
    await state(first, 'confirmed');
    await open(first, seed.refund.id);
    await state(first, 'reloaded');
    await confirm(second);
    await state(second, 'duplicate');
    // A paid renewal whose registrar reply is unknown is never offered or accepted for a refund.
    await open(first, seed.unknown.id);
    if (await first.locator('button:has-text("More")').count()) await first.click('button:has-text("More")');
    await state(first, 'unknown-offered');
    await first.evaluate(() => {
      const root = [...document.querySelectorAll('[wire\\:id]')].find((el) => (el.getAttribute('wire:name') ?? '').includes('ViewOperation'));
      return window.Livewire.find(root.getAttribute('wire:id')).mountAction('refund');
    });
    await first.waitForLoadState('networkidle');
    await state(first, 'unknown-forced');
    // A refund that cannot finish keeps its warning on screen after ordinary notices close.
    const third = await context.newPage();
    watch(third);
    await third.clock.install();
    await open(third, seed.failing.id);
    await openRefund(third);
    await confirm(third);
    await state(third, 'failed');
    await third.clock.fastForward(60000);
    await state(third, 'failed-later');
    receipt.facts = await (await context.request.get(`${base}/refund-browser-facts`)).json();
  } catch (error) {
    receipt.errors.push(String((error && error.stack) || error));
  }
  await browser.close();
  fs.writeFileSync(path.join(out, 'receipt.json'), JSON.stringify(receipt, null, 1));
  console.log(JSON.stringify({ steps: receipt.steps.map(({ shot, text, ...s }) => ({ ...s, text })), errors: receipt.errors, requests: receipt.requests, facts: receipt.facts ?? null }));
})();
