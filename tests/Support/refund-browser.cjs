// Staff Cancel and refund on held paid renewals, clicked in Chromium. MIT, Copyright (c) 2026 Autonomic Engineering.
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

// base is a synthetic origin: every request to it is answered here, and nothing listens on a port.
const [base, seedFile, out] = process.argv.slice(2);
const seed = JSON.parse(fs.readFileSync(seedFile, 'utf8'));
const receipt = { steps: [], requests: [], console: [], errors: [] };
const app = process.env.PAYMENTER_TEST_FIXTURE;
const publicRoot = path.join(app, 'public');
const KERNEL = path.join(__dirname, 'refund-browser-kernel.php');
// The kernel points the theme's missing compiled assets here.
const ASSETS = '/refund-browser-assets/';
// Paymenter serves Livewire updates at /paymenter/update.
const isUpdate = (url) => /\/update$/.test(new URL(url).pathname);
const REASON = 'Customer asked to cancel this renewal';

// One PHP process per request, one at a time, as the PHP web server handled them.
let pending = Promise.resolve();
function serially(task) {
  const run = pending.then(task);
  pending = run.catch(() => {});
  return run;
}

function kernel(request) {
  return new Promise((resolve, reject) => {
    // PHP diagnostics go to stderr; stdout carries only this driver's report.
    const php = spawn(process.env.REFUND_BROWSER_PHP, [KERNEL], { cwd: app, env: process.env, stdio: ['pipe', 2, 2, 'pipe'] });
    const reply = [];
    const timer = setTimeout(() => php.kill('SIGKILL'), 60000);
    php.stdio[3].on('data', (chunk) => reply.push(chunk));
    php.on('error', reject);
    php.on('close', (code, signal) => {
      clearTimeout(timer);
      if (code !== 0) return reject(new Error(`Kernel exit ${code ?? signal} for ${request.method} ${request.url}`));
      try { resolve(JSON.parse(Buffer.concat(reply).toString('utf8'))); } catch (error) { reject(error); }
    });
    php.stdin.end(JSON.stringify(request));
  });
}

async function call(method, url, headers = {}, body = Buffer.alloc(0)) {
  const reply = await serially(() => kernel({ method, url, headers, body: body.toString('base64') }));
  return { ...reply, body: Buffer.from(reply.body, 'base64') };
}

// A public file the PHP web server would have served itself, never a script.
function publicFile(pathname) {
  if (pathname === '/' || pathname.includes('..') || pathname.endsWith('.php')) return null;
  const file = path.join(publicRoot, pathname);
  try { return fs.statSync(file).isFile() ? file : null; } catch { return null; }
}

async function answer(route) {
  const request = route.request();
  const url = new URL(request.url());
  if (url.origin !== base) {
    receipt.errors.push(`Request to another origin: ${url.origin}`);
    return route.abort('blockedbyclient');
  }
  if (url.pathname.startsWith(ASSETS)) {
    return route.fulfill({ status: 200, contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: '' });
  }
  const file = publicFile(url.pathname);
  if (file) return route.fulfill({ path: file });
  try {
    const reply = await call(request.method(), request.url(), await request.allHeaders(), request.postDataBuffer() ?? Buffer.alloc(0));
    const headers = reply.cookies.length ? { ...reply.headers, 'set-cookie': reply.cookies.join('\n') } : reply.headers;
    return await route.fulfill({ status: reply.status, headers, body: reply.body });
  } catch (error) {
    receipt.errors.push(String((error && error.stack) || error));
    return route.abort('failed');
  }
}

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
  // Release prior navigation heaps before retaining the next small DOM receipt.
  const session = await page.context().newCDPSession(page);
  try { await session.send('HeapProfiler.collectGarbage'); } finally { await session.detach(); }
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
      menu: [...document.querySelectorAll('.fi-dropdown-list-item')].filter(visible).map((n) => n.innerText.trim()),
      notificationTitles: [...document.querySelectorAll('.fi-no-notification-title')].filter(visible).map((el) => el.innerText.trim()),
      notificationDurations: [...document.querySelectorAll('.fi-no-notification')].filter(visible).map((el) =>
        window.Alpine.evaluate(el, el.getAttribute('x-data').replace(/^notificationComponent/, '')).notification.duration),
      notifications: [...document.querySelectorAll('.fi-no-notification')].filter(visible).map((n) => n.innerText.replace(/\s+/g, ' ').trim()),
      text: (document.querySelector('.fi-page') ?? document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 3000),
    };
  });
  receipt.steps.push({ label, ...row });
  return row;
}

async function open(page, id) {
  await page.goto(`${base}/admin/registrar-requests/${id}`);
  await page.waitForFunction(() => !!window.Livewire && !!document.querySelector('[wire\\:id]'));
}

async function openRefund(page) {
  await page.click('button:has-text("More")');
  await page.click('.fi-dropdown-list-item:has-text("Cancel and refund")');
  await page.waitForSelector('.fi-modal-window textarea', { state: 'visible' });
  await page.locator('.fi-modal-window textarea').fill(REASON);
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
  // Limit renderer overhead for these local fixture pages.
  const browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || undefined,
    args: ['--single-process', '--no-zygote', '--disable-gpu', '--disable-software-rasterizer', '--js-flags=--max-old-space-size=32 --optimize-for-size'] });
  const context = await browser.newContext({ viewport: { width: 900, height: 700 }, colorScheme: 'dark' });
  await context.route('**/*', answer);
  try {
    const first = await context.newPage();
    watch(first);
    await first.goto(`${base}/refund-browser-login`);
    if ((process.env.REFUND_BROWSER_CASE || 'refund') === 'refund') {
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
    await first.click('button:has-text("More")');
    await state(first, 'reloaded');
    await confirm(second);
    await state(second, 'duplicate');
    await second.close();
    } else {
    // A paid renewal whose registrar reply is unknown is never offered or accepted for a refund.
    await open(first, seed.unknown.id);
    if (await first.locator('button:has-text("More")').count()) await first.click('button:has-text("More")');
    await state(first, 'unknown-offered');
    await first.evaluate(() => {
      const root = [...document.querySelectorAll('[wire\\:id]')].find((el) => (el.getAttribute('wire:name') ?? '').includes('ViewOperation'));
      return window.Livewire.find(root.getAttribute('wire:id')).mountAction('refund');
    });
    await state(first, 'unknown-forced');
    // Check the rendered auto-close setting and persistence with a deterministic clock.
    const third = first;
    await third.clock.install();
    await open(third, seed.failing.id);
    await openRefund(third);
    await confirm(third);
    await state(third, 'failed');
    await third.clock.fastForward(60000);
    await state(third, 'failed-later');
    }
    receipt.facts = JSON.parse((await call('GET', `${base}/refund-browser-facts`)).body.toString('utf8'));
  } catch (error) {
    receipt.errors.push(String((error && error.stack) || error));
  }
  await context.close();
  await browser.close();
  fs.writeFileSync(path.join(out, 'receipt.json'), JSON.stringify(receipt, null, 1));
  console.log(JSON.stringify({ steps: receipt.steps.map(({ shot, text, ...s }) => ({ ...s, text })), errors: receipt.errors, requests: receipt.requests, facts: receipt.facts ?? null }));
})();
