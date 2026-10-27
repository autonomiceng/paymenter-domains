{{-- Adapted from Enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@once
<style>
/* ---------- Client area ---------- */
.domains {
    --e-fg: hsl(var(--color-base));
    --e-muted: hsl(var(--color-muted));
    --e-line: hsl(var(--color-neutral));
    --e-bg: hsl(var(--color-background));
    --e-panel: hsl(var(--color-background-secondary));
    --e-accent: hsl(var(--color-primary));
    --e-field: var(--e-panel);
    --e-ok: color-mix(in srgb, hsl(var(--color-success)) 70%, black);
    --e-info: color-mix(in srgb, hsl(var(--color-info)) 75%, black);
    --e-warn: color-mix(in srgb, hsl(var(--color-warning)) 78%, black);
    --e-bad: color-mix(in srgb, hsl(var(--color-error)) 80%, black);
    --e-quiet: hsl(var(--color-muted));
    max-width: 72rem; margin-inline: auto; color: var(--e-fg); overflow-wrap: anywhere;
}
.dark .domains {
    --e-ok: color-mix(in srgb, hsl(var(--color-success)) 80%, white);
    --e-info: color-mix(in srgb, hsl(var(--color-info)) 80%, white);
    --e-warn: color-mix(in srgb, hsl(var(--color-warning)) 85%, white);
    --e-bad: color-mix(in srgb, hsl(var(--color-error)) 82%, white);
}
.domains [hidden] { display: none !important; }
.domains > * + * { margin-top: 1.5rem; }
.domains :is(a, button, input, select, textarea, summary):focus-visible { outline: 2px solid var(--e-accent); outline-offset: 2px; }
.domains .domains-i { width: 1rem; height: 1rem; flex: none; }
.domains .domains-muted { color: var(--e-muted); }
.domains .domains-small { font-size: .8125rem; line-height: 1.25rem; }
.domains .domains-num { font-variant-numeric: tabular-nums; }
.domains .domains-nowrap { white-space: nowrap; }
.domains .domains-a { color: var(--e-accent); font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }
.domains .domains-row__a .domains-a, .domains .domains-card dd > .domains-a, .domains .domains-linkbtn { text-decoration: none; }
.domains .domains-a:hover { text-decoration: underline; }
.domains .domains-row__a .domains-a:hover, .domains .domains-card dd > .domains-a:hover, .domains .domains-linkbtn:hover { text-decoration: none; }
.domains .domains-back { display: inline-flex; align-items: center; gap: .25rem; font-size: .875rem; color: var(--e-muted); min-height: 2rem; }
.domains .domains-back:hover { color: var(--e-fg); }
.domains .domains-stack > * + * { margin-top: 1rem; }
.domains .domains-stack-sm > * + * { margin-top: .5rem; }
.domains .domains-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; }
.domains-sr, .domains .domains-sr { position: absolute !important; width: 1px !important; height: 1px !important; padding: 0 !important; margin: -1px !important; border: 0 !important; overflow: hidden !important; clip: rect(0 0 0 0) !important; clip-path: inset(50%) !important; white-space: nowrap !important; }

/* Page header */
.domains .domains-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem; }
.domains .domains-title { font-size: 1.625rem; line-height: 2.125rem; font-weight: 700; letter-spacing: -.015em; }
.domains .domains-lede { margin-top: .25rem; color: var(--e-muted); font-size: .9375rem; max-width: 60ch; }
.domains h2.domains-h2 { font-size: 1.0625rem; font-weight: 700; line-height: 1.5rem; }
.domains h3.domains-h3 { font-size: .9375rem; font-weight: 700; }

@media (max-width: 639px) { .domains .domains-head > .domains-btn--ghost { margin-left: -.5rem; } }

/* Buttons */
.domains .domains-btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 2.5rem; padding: 0 1rem;
    border-radius: .375rem; border: 1px solid transparent; font-size: .875rem; font-weight: 600; line-height: 1.25rem; white-space: nowrap;
    cursor: pointer; transition: background-color .15s, border-color .15s, color .15s; text-decoration: none; }
.domains .domains-btn--primary { background: var(--e-accent); color: #fff; }
.domains .domains-btn--primary:hover { background: hsl(var(--color-primary) / .85); }
.domains .domains-btn--secondary { background: var(--e-panel); border-color: var(--e-line); color: var(--e-fg); }
.domains .domains-panel .domains-btn--secondary { background: var(--e-bg); }
.domains .domains-btn--secondary:hover { border-color: color-mix(in srgb, var(--e-fg) 30%, var(--e-line)); }
.domains .domains-btn--ghost { background: transparent; color: var(--e-accent); padding-inline: .5rem; }
.domains .domains-btn--ghost:hover { background: hsl(var(--color-primary) / .08); }
.domains .domains-btn--danger { background: hsl(var(--color-error)); color: #fff; }
.domains .domains-btn--danger:hover { background: hsl(var(--color-error) / .85); }
.domains .domains-btn--sm { min-height: 2rem; padding: 0 .75rem; font-size: .8125rem; }
.domains .domains-btn:disabled, .domains .domains-btn[aria-disabled="true"] { opacity: .5; cursor: not-allowed; }
@media (pointer: coarse) { .domains .domains-btn { min-height: 2.75rem; } .domains .domains-btn--sm { min-height: 2.5rem; } }

/* Panels and sections */
.domains .domains-panel { --e-field: var(--e-bg); background: var(--e-panel); border: 1px solid var(--e-line); border-radius: .75rem; }
.domains .domains-section { padding: 1.25rem 1.5rem; }
.domains .domains-section + .domains-section { border-top: 1px solid var(--e-line); }
.domains .domains-section__head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .5rem 1rem; margin-bottom: 1rem; }
.domains .domains-section__head:last-child { margin-bottom: 0; }
.domains .domains-section__head p { color: var(--e-muted); font-size: .875rem; margin-top: .125rem; max-width: 62ch; }
@media (max-width: 639px) { .domains .domains-section { padding: 1rem; } }

/* Badges */
.domains .domains-badge { display: inline-flex; align-items: center; gap: .375rem; padding: .125rem .625rem; border-radius: 999px; font-size: .75rem;
    font-weight: 700; line-height: 1.25rem; white-space: nowrap; color: var(--e-quiet); background: color-mix(in srgb, currentColor 13%, transparent); }
.domains .domains-badge::before { content: ""; width: .375rem; height: .375rem; border-radius: 50%; background: currentColor; }
.domains .domains-pill-reason { display: block; margin-top: .25rem; font-size: .75rem; line-height: 1rem; color: var(--e-muted); }
.domains .domains-tone-ok { color: var(--e-ok); }
.domains .domains-tone-info { color: var(--e-info); }
.domains .domains-tone-warn { color: var(--e-warn); }
.domains .domains-tone-bad { color: var(--e-bad); }
.domains .domains-tone-muted { color: var(--e-quiet); }

/* Notices */
.domains .domains-notice { display: flex; align-items: flex-start; gap: .75rem; padding: .875rem 1rem; border-radius: .5rem; font-size: .875rem; line-height: 1.375rem;
    border: 1px solid color-mix(in srgb, var(--tone, var(--e-info)) 35%, transparent); background: color-mix(in srgb, var(--tone, var(--e-info)) 8%, transparent); }
.domains .domains-notice > svg { width: 1.25rem; height: 1.25rem; flex: none; color: var(--tone, var(--e-info)); margin-top: .0625rem; }
.domains .domains-notice--ok { --tone: var(--e-ok); }
.domains .domains-notice--warn { --tone: var(--e-warn); }
.domains .domains-notice--bad { --tone: var(--e-bad); }
.domains .domains-notice strong { font-weight: 700; }
.domains .domains-notice ul { margin-top: .25rem; }
.domains .domains-testbar { display: flex; align-items: center; gap: .5rem; padding: .5rem .875rem; font-size: .8125rem; border-radius: .5rem;
    color: var(--e-warn); border: 1px dashed color-mix(in srgb, var(--e-warn) 55%, transparent); background: color-mix(in srgb, var(--e-warn) 7%, transparent); }
.domains .domains-testbar span { color: var(--e-fg); }

/* Forms */
.domains .domains-label { display: block; font-size: .8125rem; font-weight: 600; margin-bottom: .375rem; }
.domains .domains-label .domains-opt { font-weight: 400; color: var(--e-muted); }
.domains .domains-input { display: block; width: 100%; min-height: 2.625rem; padding: .5rem .75rem; font-size: .875rem; line-height: 1.5rem; color: var(--e-fg);
    background: var(--e-field); border: 1px solid var(--e-line); border-radius: .375rem; transition: border-color .15s, box-shadow .15s; }
.domains textarea.domains-input { min-height: 6.5rem; resize: vertical; }
.domains select.domains-input { appearance: none; padding-right: 2.25rem;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23888'%3E%3Cpath d='M12 15.6 6.4 10l1.4-1.4 4.2 4.2 4.2-4.2 1.4 1.4z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right .5rem center; background-size: 1.25rem; }
.domains .domains-input::placeholder { color: var(--e-muted); opacity: .8; }
.domains .domains-input:focus { outline: none; border-color: var(--e-accent); box-shadow: 0 0 0 3px hsl(var(--color-primary) / .18); }
.domains .domains-input[aria-invalid="true"] { border-color: var(--e-bad); }
.domains .domains-input:disabled { opacity: .55; }
.domains .domains-hint { font-size: .75rem; line-height: 1.125rem; color: var(--e-muted); margin-top: .375rem; }
.domains .domains-error { font-size: .75rem; line-height: 1.125rem; color: var(--e-bad); margin-top: .375rem; font-weight: 600; }
.domains .domains-fields { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
.domains .domains-fields .domains-span { grid-column: 1 / -1; }
@media (max-width: 639px) { .domains .domains-fields { grid-template-columns: minmax(0, 1fr); } }
.domains .domains-checkbox { display: flex; align-items: flex-start; gap: .625rem; font-size: .875rem; cursor: pointer; padding-block: .25rem; }
.domains .domains-checkbox input, .domains .domains-choice input { width: 1.125rem; height: 1.125rem; flex: none; margin-top: .125rem; accent-color: var(--e-accent); }
.domains input[type="checkbox"] { color-scheme: light; }
.dark .domains input[type="checkbox"] { color-scheme: dark; }
.domains .domains-choice { display: flex; align-items: flex-start; gap: .75rem; padding: .875rem 1rem; border: 1px solid var(--e-line); border-radius: .5rem; cursor: pointer; background: var(--e-field); }
.domains .domains-choice:has(input:checked) { border-color: var(--e-accent); box-shadow: inset 0 0 0 1px var(--e-accent); }
.domains .domains-choice strong { display: block; font-size: .875rem; }
.domains .domains-choice span { font-size: .8125rem; color: var(--e-muted); }
.domains .domains-choices { display: grid; gap: .5rem; }
.domains details.domains-more > summary { cursor: pointer; font-size: .8125rem; font-weight: 600; color: var(--e-accent); list-style: none; display: inline-flex; align-items: center; gap: .25rem; min-height: 2rem; }
.domains details.domains-more > summary::-webkit-details-marker { display: none; }
.domains details.domains-more > summary::before { content: "+"; width: 1rem; text-align: center; }
.domains details.domains-more[open] > summary::before { content: "−"; }

/* Switch */
.domains .domains-switch { display: inline-flex; align-items: center; gap: .625rem; font-size: .875rem; font-weight: 600; cursor: pointer; background: none; border: 0; padding: .25rem 0; color: var(--e-fg); }
.domains .domains-switch__track { position: relative; width: 2.5rem; height: 1.375rem; border-radius: 999px; background: color-mix(in srgb, var(--e-muted) 45%, transparent); transition: background-color .15s; flex: none; }
.domains .domains-switch__track::after { content: ""; position: absolute; top: .1875rem; left: .1875rem; width: 1rem; height: 1rem; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / .3); transition: transform .15s; }
.domains .domains-switch[aria-checked="true"] .domains-switch__track { background: var(--e-accent); }
.domains .domains-switch[aria-checked="true"] .domains-switch__track::after { transform: translateX(1.125rem); }
.domains .domains-switch:disabled { cursor: not-allowed; opacity: .55; }

/* Tables */
.domains .domains-tablewrap { overflow-x: auto; }
.domains table.domains-tbl { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .875rem; }
.domains .domains-tbl th { text-align: left; font-weight: 600; font-size: .75rem; color: var(--e-muted); padding: .625rem 1rem; border-bottom: 1px solid var(--e-line); white-space: nowrap; }
.domains .domains-tbl th a { display: inline-flex; align-items: center; gap: .25rem; color: inherit; }
.domains .domains-tbl th a:hover, .domains .domains-tbl th a[aria-current] { color: var(--e-fg); }
.domains .domains-tbl td { padding: .875rem 1rem; border-bottom: 1px solid var(--e-line); vertical-align: middle; }
.domains .domains-tbl tbody tr:last-child td { border-bottom: 0; }
.domains .domains-tbl tbody tr:hover td { background: color-mix(in srgb, var(--e-line) 22%, transparent); }
.domains .domains-tbl .domains-end { text-align: right; }
.domains .domains-domain, .domains .domains-num .domains-domain { font-variant-numeric: normal; }
.domains .domains-domain { font-weight: 700; letter-spacing: -.005em; color: var(--e-fg); }
.domains a.domains-domain:hover { color: var(--e-accent); }
@media (max-width: 719px) {
    .domains table.domains-tbl.domains-stackable, .domains .domains-stackable tbody { display: block; }
    .domains .domains-stackable thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .domains .domains-stackable tr { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .375rem .75rem; padding: .875rem 1rem; border-bottom: 1px solid var(--e-line); }
    .domains .domains-stackable tbody tr:last-child { border-bottom: 0; }
    .domains .domains-stackable td { display: block; padding: 0; border: 0; }
    .domains .domains-stackable tbody tr:hover td { background: none; }
    .domains .domains-stackable td[data-label]::before { content: attr(data-label) " "; color: var(--e-muted); font-size: .75rem; }
    .domains .domains-stackable .domains-cell-wide { grid-column: 1 / -1; }
    .domains .domains-stackable .domains-end { text-align: left; }
}

/* Search */
.domains .domains-searchbar { display: flex; align-items: stretch; gap: .375rem; padding: .375rem; border: 1px solid var(--e-line); border-radius: .875rem;
    background: var(--e-panel); transition: border-color .15s, box-shadow .15s; }
.domains .domains-searchbar:focus-within { border-color: var(--e-accent); box-shadow: 0 0 0 1px var(--e-accent), 0 0 0 4px hsl(var(--color-primary) / .2); }
.domains .domains-searchbar input { flex: 1 1 auto; min-width: 0; border: 0; background: transparent; color: var(--e-fg); font-size: 1.1875rem; font-weight: 600; padding: .625rem .875rem; letter-spacing: -.005em; }
.domains .domains-searchbar input::placeholder { color: var(--e-muted); font-weight: 400; }
.domains .domains-searchbar :is(input, select):is(:focus, :focus-visible) { outline: none; box-shadow: none; }
.domains .domains-searchbar select { flex: none; border: 0; border-left: 1px solid var(--e-line); border-radius: 0; background-color: transparent; color: var(--e-fg);
    font-size: 1rem; font-weight: 700; padding: 0 2.25rem 0 1rem; min-height: 3rem; width: auto; }
.domains .domains-searchbar .domains-btn { min-height: 3rem; padding: 0 1.375rem; border-radius: .5rem; font-size: .9375rem; }
@media (max-width: 639px) {
    .domains .domains-searchbar { flex-wrap: wrap; }
    .domains .domains-searchbar input { flex-basis: 100%; }
    .domains .domains-searchbar select { flex: 1 1 auto; border-left: 0; border-top: 1px solid var(--e-line); border-radius: .375rem; }
    .domains .domains-searchbar .domains-btn { flex: 1 1 auto; }
}
.domains .domains-endings { display: flex; flex-wrap: wrap; gap: .25rem .875rem; font-size: .8125rem; color: var(--e-muted); margin-top: .75rem; }
.domains .domains-endings b { color: var(--e-fg); font-weight: 700; }

.domains .domains-results { border: 1px solid var(--e-line); border-radius: .875rem; background: var(--e-panel); overflow: hidden; }
.domains .domains-result { display: grid; grid-template-columns: minmax(12rem, 1fr) max-content 12rem 9.5rem; align-items: center; gap: .5rem 1.25rem; padding: 1rem 1.25rem; }
.domains .domains-result + .domains-result { border-top: 1px solid var(--e-line); }
.domains .domains-result__name { min-width: 0; overflow-wrap: anywhere; font-size: 1.0625rem; font-weight: 700; letter-spacing: -.01em; }
.domains .domains-result__note { grid-column: 1 / -1; max-width: 96ch; margin: 0; line-height: 1.5; }
.domains .domains-result__note:empty { display: none; }
.domains .domains-result__name .domains-tld { color: var(--e-accent); }
.domains .domains-result.is-first { padding-block: 1.375rem; background: color-mix(in srgb, var(--e-bg) 40%, transparent); }
.domains .domains-result.is-first .domains-result__name { font-size: 1.375rem; }
.domains .domains-result__price { text-align: right; font-variant-numeric: tabular-nums; line-height: 1.25rem; }
.domains .domains-result__price strong { font-size: 1rem; }
.domains .domains-result__price small { display: block; font-size: .75rem; color: var(--e-muted); }
.domains .domains-result__action { display: flex; justify-content: flex-end; }
.domains .domains-result__action > * { display: flex; }
.domains .domains-result__action form { width: 100%; flex-direction: column; gap: .5rem; }
.domains .domains-result__action select { width: 100%; min-width: 0; font-size: .8125rem; }
.domains .domains-result__action .domains-retry { flex-direction: column; align-items: flex-end; gap: .25rem; }
.domains .domains-result__action .domains-btn { width: 100%; }
.domains .domains-result[data-state="taken"] .domains-result__name, .domains .domains-result[data-state="premium"] .domains-result__name,
.domains .domains-result[data-state="held"] .domains-result__name, .domains .domains-result[data-state="managed"] .domains-result__name, .domains .domains-result[data-state="invalid"] .domains-result__name { color: var(--e-muted); }
.domains .domains-result[data-state="taken"] .domains-tld, .domains .domains-result[data-state="premium"] .domains-tld,
.domains .domains-result[data-state="held"] .domains-tld, .domains .domains-result[data-state="managed"] .domains-tld, .domains .domains-result[data-state="invalid"] .domains-tld { color: inherit; }
.domains .domains-checking { display: inline-flex; align-items: center; gap: .5rem; font-size: .8125rem; color: var(--e-muted); }
.domains .domains-checking::before { content: ""; width: .875rem; height: .875rem; border-radius: 50%; border: 2px solid color-mix(in srgb, var(--e-muted) 35%, transparent);
    border-top-color: var(--e-accent); animation: domains-spin .8s linear infinite; }
@keyframes domains-spin { to { transform: rotate(360deg); } }
.domains:not(.domains-js) [data-js-only] { display: none !important; }
.domains.domains-js [data-nojs-only] { display: none !important; }
.domains.domains-js .domains-result [data-when] { display: none; }
.domains.domains-js .domains-result[data-state="available"] [data-when~="available"],
.domains.domains-js .domains-result[data-state="transferable"] [data-when~="transferable"],
.domains.domains-js .domains-result[data-state="taken"] [data-when~="taken"],
.domains.domains-js .domains-result[data-state="yours"] [data-when~="yours"],
.domains.domains-js .domains-result[data-state="error"] [data-when~="error"] { display: flex; }
@media (max-width: 959px) {
    .domains .domains-result { grid-template-columns: minmax(0, 1fr) auto; }
    .domains .domains-result__name { grid-column: 1 / -1; }
    .domains .domains-result.is-first .domains-result__name { font-size: 1.1875rem; }
    .domains .domains-result__price { text-align: left; }
    .domains .domains-result__status { grid-column: 1; grid-row: 2; }
    .domains .domains-result__price { grid-column: 1; grid-row: 3; }
    .domains .domains-result__price:empty { display: none; }
    .domains .domains-result__action { width: 7.5rem; grid-column: 2; grid-row: 2 / span 2; align-self: center; min-width: 7.5rem; }
    /* Without a price the note moves up into row 3, so no empty track is left. */
    .domains .domains-result:has(> .domains-result__price:empty) .domains-result__action { grid-row: 2; }
}

/* Price list */
.domains .domains-pricelist tbody th { font-size: .9375rem; color: var(--e-fg); padding: .875rem 1rem; border-bottom: 1px solid var(--e-line); font-weight: 700; }
.domains .domains-pricelist tbody tr:last-child th { border-bottom: 0; }
@media (max-width: 719px) {
    .domains .domains-pricelist.domains-stackable tr { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .domains .domains-pricelist.domains-stackable tbody th { display: block; padding: 0; border: 0; grid-column: 1 / -1; }
    .domains .domains-pricelist.domains-stackable td[data-label]::before { display: block; }
}


.domains .domains-result { grid-template-columns: minmax(0, 1fr) max-content 12rem minmax(9.5rem, max-content); }
.domains .domains-result__action:has(select) { width: 13rem; }
.domains .domains-result__price, .domains .domains-result__status, .domains .domains-result__action { min-width: 0; }
.domains .domains-result__price { overflow-wrap: anywhere; }
@media (max-width: 959px) { .domains .domains-result { grid-template-columns: minmax(0, 1fr) auto; } }
@media (max-width: 959px) {
    .domains .domains-result__action:has(select) { grid-column: 1 / -1; grid-row: 4; width: 100%; }
    .domains .domains-result__action:has(select) form { flex-direction: column; }
    .domains .domains-result__action select { flex: 1; }
    .domains .domains-result__action:has(select) .domains-btn { width: 100%; }
}
@media (prefers-reduced-motion: reduce) { .domains * { transition: none !important; animation: none !important; } }
/* Owner forms retain the approved management layout. */
.domains .domains-owner-form { display: grid; gap: .75rem; max-width: 38rem; margin-top: 1rem; }
.domains .domains-owner-form .domains-btn { justify-self: start; }
.domains .domains-section > p { margin-block: .5rem; }
.domains .domains-title, .domains .domains-back { overflow-wrap: anywhere; }
.domains .domains-checkbox { align-items: start; }
.domains .domains-checkbox input { margin-top: .25rem; flex: none; }

.domains-dhead { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem 2rem; }
.domains .domains-dhead__meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; margin-top: .5rem; font-size: .875rem; color: var(--e-muted); }
.domains .domains-dhead__meta strong { color: var(--e-fg); font-weight: 600; }
.domains .domains-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); }
.domains .domains-facts { padding-block: .5rem; }
.domains .domains-facts:not(:last-child) { border-bottom: 1px solid var(--e-line); }
.domains .domains-fact { padding: .875rem 1.5rem; }
@media (max-width: 639px) { .domains .domains-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } .domains .domains-fact { padding: .75rem 1rem; } }
.domains .domains-fact dt { font-size: .8125rem; color: var(--e-muted); }
.domains .domains-fact dd { margin-top: .25rem; font-size: 1rem; font-weight: 700; }
.domains .domains-fact dd small { display: block; font-size: .8125rem; font-weight: 400; color: var(--e-muted); margin-top: .125rem; }
.domains .domains-hosts { display: grid; gap: .25rem; font-size: .9375rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.domains .domains-hosts li { display: flex; align-items: center; gap: .625rem; }
.domains .domains-hosts li::before { content: ""; width: .375rem; height: .375rem; border-radius: 50%; background: var(--e-line); }
.domains ol.domains-steps2 { counter-reset: s; display: grid; gap: .625rem; font-size: .875rem; }
.domains ol.domains-steps2 li { counter-increment: s; display: flex; gap: .75rem; align-items: baseline; }
.domains ol.domains-steps2 li::before { content: counter(s); flex: none; width: 1.375rem; height: 1.375rem; border-radius: 50%; display: inline-grid; place-items: center;
    font-size: .75rem; font-weight: 700; background: hsl(var(--color-primary) / .12); color: var(--e-accent); }
.domains .domains-status-card { padding: 1.75rem 1.5rem; }
.domains .domains-status-card h2 { font-size: 1.25rem; font-weight: 700; }
.domains .domains-status-card p { color: var(--e-muted); max-width: 60ch; margin-top: .375rem; }
.domains .domains-status-card .domains-input { max-width: 28rem; }
.domains .domains-progress { display: flex; gap: .375rem; margin-top: 1.25rem; max-width: 28rem; }
.domains .domains-progress span { flex: 1; height: .3125rem; border-radius: 999px; background: color-mix(in srgb, var(--e-muted) 25%, transparent); }
.domains .domains-progress span.is-done { background: var(--e-accent); }
.domains .domains-progress-labels { display: flex; justify-content: space-between; max-width: 28rem; font-size: .75rem; color: var(--e-muted); margin-top: .375rem; }

/* Dialog */
.domains-dialog { margin: auto; max-height: calc(100vh - 2rem); border: 0; padding: 0; border-radius: .75rem; width: min(28rem, calc(100vw - 2rem)); background: hsl(var(--color-background-secondary)); color: hsl(var(--color-base));
    box-shadow: 0 24px 60px rgb(0 0 0 / .35); }
.domains-dialog::backdrop { background: rgb(0 0 0 / .55); }
.domains-dialog form { padding: 1.5rem; }
.domains-dialog h2 { font-size: 1.125rem; font-weight: 700; }
.domains-dialog p { margin-top: .5rem; font-size: .9375rem; line-height: 1.5rem; color: hsl(var(--color-muted)); }
.domains-dialog .domains-dialog__actions { display: flex; flex-wrap: wrap-reverse; justify-content: flex-end; gap: .5rem; margin-top: 1.5rem; }
.domains-dialog .domains-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 2.5rem; padding: 0 1rem; border-radius: .375rem; font-size: .875rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
.domains-dialog .domains-btn--secondary { background: transparent; border-color: hsl(var(--color-neutral)); color: hsl(var(--color-base)); }
.domains-dialog .domains-btn--primary { background: hsl(var(--color-primary)); color: #fff; }
.domains-dialog .domains-btn--danger { background: hsl(var(--color-error)); color: #fff; }
.domains-dialog :focus-visible { outline: 2px solid hsl(var(--color-primary)); outline-offset: 2px; }

/* Help */
.domains .domains-faq { max-width: 46rem; }
.domains .domains-faq details { border-bottom: 1px solid var(--e-line); }
.domains .domains-faq summary { cursor: pointer; padding: 1rem 0; font-weight: 700; list-style: none; display: flex; justify-content: space-between; gap: 1rem; }
.domains .domains-faq summary::-webkit-details-marker { display: none; }
.domains .domains-faq summary::after { content: "+"; color: var(--e-muted); font-weight: 400; }
.domains .domains-faq details[open] summary::after { content: "−"; }
.domains .domains-faq details > div { padding-bottom: 1rem; color: color-mix(in srgb, var(--e-fg) 78%, transparent); font-size: .9375rem; line-height: 1.5rem; }
.domains .domains-faq details > div > * + * { margin-top: .5rem; }

@media (prefers-reduced-motion: reduce) {
    .domains-ui *, .domains *, .domains-dialog { transition: none !important; animation: none !important; }
}

/* Toolbar and chips */
.domains .domains-toolbar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; padding: 1rem; border-bottom: 1px solid var(--e-line); }
.domains .domains-chips { display: flex; flex-wrap: wrap; gap: .375rem; }
.domains .domains-chip { display: inline-flex; align-items: center; gap: .375rem; min-height: 2rem; padding: 0 .75rem; border-radius: 999px; border: 1px solid var(--e-line);
    font-size: .8125rem; font-weight: 600; color: var(--e-muted); background: var(--e-bg); }
.domains .domains-chip:hover { color: var(--e-fg); }
.domains .domains-chip[aria-current="true"] { color: var(--e-accent); border-color: hsl(var(--color-primary) / .5); background: hsl(var(--color-primary) / .08); }
.domains .domains-chip small { font-size: .75rem; font-weight: 600; opacity: .75; }
.domains .domains-filter { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
.domains .domains-filter .domains-input { min-height: 2.25rem; width: auto; }
.domains .domains-search-mini { position: relative; }
.domains .domains-search-mini svg { position: absolute; left: .625rem; top: 50%; transform: translateY(-50%); color: var(--e-muted); pointer-events: none; }
.domains .domains-search-mini .domains-input { padding-left: 2rem; width: 15rem; max-width: 100%; }
@media (max-width: 639px) { .domains .domains-search-mini, .domains .domains-search-mini .domains-input { width: 100%; } .domains .domains-filter { width: 100%; } }
.domains .domains-filter > .domains-input { max-width: 100%; }
@media (max-width: 639px) { .domains .domains-filter > .domains-input { width: 100%; } }

/* Empty state */
.domains .domains-empty { padding: 3rem 1.5rem; text-align: center; }
.domains .domains-empty p { color: var(--e-muted); max-width: 42ch; margin: .375rem auto 1.25rem; font-size: .9375rem; }
.domains .domains-empty p.domains-empty-alt { margin: 1rem auto 0; font-size: .875rem; }
/* Record editors share the table overflow and input focus rules. */
.domains .domains-record-form { max-width: 100%; min-width: 0; }
.domains .domains-record-form .domains-tablewrap { position: relative; }
.domains .domains-record-form .domains-tablewrap:focus-visible { outline: 2px solid var(--e-accent); outline-offset: 2px; }
.domains .domains-scroll-hint { display: none; }
@media (max-width: 52rem) { .domains .domains-scroll-hint { display: block; } }
.domains .domains-records { min-width: 46rem; }
.domains .domains-records td { padding: .375rem .5rem; vertical-align: top; }
.domains .domains-records .domains-label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
.domains .domains-records .domains-input { min-width: 5.5rem; }
.domains .domains-record-card { border: 1px solid var(--e-line); border-radius: .5rem; padding: .75rem 1rem; margin-block: .75rem; }
.domains .domains-dhead > div { min-width: 0; max-width: 100%; }
.domains .domains-record-value { white-space: nowrap; overflow-wrap: normal; overflow-x: auto; max-width: 100%; }
.domains .domains-inline-token { display: inline-block; max-width: 100%; white-space: nowrap; overflow-wrap: normal; overflow-x: auto; vertical-align: bottom; }
.domains .domains-wrap { overflow-wrap:anywhere; word-break:normal; white-space:normal; }
.domains .domains-summary { min-width:0; }
.domains .domains-summary-badges { margin-block:.75rem; align-items:flex-start; }
.domains .domains-summary .domains-small { display:block; }
.domains .domains-summary-links { margin-block:1rem; }
.domains .domains-checks { margin-block:1rem; }
.domains .domains-checks > summary { cursor:pointer; text-decoration:underline; text-underline-offset:3px; }
/* Domains list: the whole row opens the domain; a fix link inside a cell stays clickable above it. */
.domains .domains-linkrow { position:relative; }
.domains .domains-row-link::after { content:""; position:absolute; inset:0; }
.domains .domains-row-link:focus-visible { outline:none; }
.domains .domains-linkrow:is(:hover, :focus-within) td { background: color-mix(in srgb, var(--e-accent) 10%, transparent); }
.domains .domains-linkrow .domains-missing__fix { position:relative; z-index:1; }
.domains .domains-list .domains-domain { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-weight:500; text-decoration:none; }
.domains .domains-list .domains-domain:hover { text-decoration:underline; }
.domains .domains-list td { vertical-align:top; }
.domains .domains-cell-main { display:block; }
.domains .domains-cell-sub, .domains .domains-list .domains-missing__cause, .domains .domains-list .domains-missing__fix { display:block; margin:.2rem 0 0; font-size:.75rem; color:var(--e-muted); }
.domains .domains-list .domains-missing__fix { color:var(--e-accent); }
.domains .domains-cell-sub .domains-missing { font-weight:500; }
.domains .domains-tone-text-warn { color: var(--e-warn); }
.domains .domains-tone-text-bad { color: var(--e-bad); }
.domains .domains-badge { white-space:normal; }
.domains .domains-missing { display:inline-flex; align-items:center; gap:.375rem; color: var(--e-bad); font-weight:600; }
.domains .domains-missing__cause { margin-inline-start:.5rem; color:var(--e-muted); }
.domains .domains-missing__fix { margin-inline-start:.5rem; white-space:nowrap; }
/* Demo tag: the only orange on the page. Wraps as one unit after the name. The Test variant has the same shape. */
.domains .domains-demo-tag { display: inline-flex; align-items:center; height:18px; padding:0 6px; margin-inline-start:8px; border-radius:4px;
    font-size:11px; font-weight:600; line-height:1; letter-spacing:.03em; white-space: nowrap; vertical-align:2px; cursor:help; }
@include('domains::partials.demo-tag-colors')
.domains a:focus-visible,.domains summary:focus-visible { outline:2px solid var(--e-accent);outline-offset:3px; }
@media(max-width:639px) { .domains .domains-summary .domains-facts { grid-template-columns:1fr; } .domains .domains-summary .domains-fact { min-width:0; } }
.domains fieldset{min-width:0}.domains .domains-checkbox{overflow-wrap:anywhere}
.domains-name-cell{min-width:11rem}.domains-name-cell .domains-domain{display:inline-block}
@media(max-width:719px){.domains-stackable .domains-name-cell{min-width:0;grid-column:1}.domains-stackable td.domains-cell-wide{grid-column:1/-1}.domains-list td.domains-end{grid-column:2;grid-row:1}}
/* Confirm step: a modal with script, an inline panel without. */
.domains .domains-buttons { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
.domains .domains-confirm { width: min(30rem, calc(100vw - 2rem)); max-height: calc(100dvh - 2rem); margin: auto; padding: 1.5rem; border: 1px solid var(--e-line); border-radius: .75rem;
    background: var(--e-panel); color: var(--e-fg); box-shadow: 0 24px 60px rgb(0 0 0 / .35); overflow: auto; }
.domains .domains-confirm::backdrop { background: rgb(0 0 0 / .55); }
.domains .domains-confirm:not(:modal) { position: static; width: auto; max-width: 38rem; margin: .25rem 0 0; box-shadow: none; border-color: var(--e-warn); }
.domains .domains-confirm > p { margin-top: .75rem; font-size: .875rem; }
.domains .domains-confirm__facts { display: grid; gap: .625rem; margin-top: .75rem; font-size: .875rem; }
.domains .domains-confirm__facts dt { font-size: .8125rem; color: var(--e-muted); }
.domains .domains-confirm__facts dd { margin: .125rem 0 0; }
.domains .domains-confirm__facts dd span { display: block; font-weight: 600; }
.domains .domains-confirm .domains-buttons { margin-top: 1.25rem; }
@media (prefers-reduced-motion: no-preference) { .domains .domains-confirm:modal { animation: domains-confirm-in .15s ease-out; } }
@keyframes domains-confirm-in { from { opacity: 0; transform: translateY(.5rem); } }
/* Help popovers and the help page */
.domains .domains-help-head { position: relative; display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
.domains .domains-help-btn { display: inline-grid; place-items: center; inline-size: 1.5rem; block-size: 1.5rem; flex: none; border: 1px solid var(--e-line); border-radius: 999px; background: transparent; color: var(--e-muted); font-size: .8125rem; font-weight: 700; line-height: 1; cursor: help; }
.domains .domains-help-btn:hover, .domains .domains-help-btn[aria-expanded="true"] { color: var(--e-fg); border-color: var(--e-accent); }
.domains .domains-help-btn:focus-visible { outline: 2px solid var(--e-accent); outline-offset: 2px; }
.domains .domains-help-pop { white-space: normal; display: none; box-sizing: border-box; position: absolute; z-index: 20; top: 100%; left: 0; margin-top: .375rem; inline-size: max-content; max-inline-size: min(22rem, 100%); padding: .625rem .75rem; border: 1px solid var(--e-line); border-radius: .5rem; background: var(--e-panel); color: var(--e-fg); box-shadow: 0 .5rem 1.5rem rgb(0 0 0 / .25); font-size: .875rem; font-weight: 400; line-height: 1.375rem; }
.domains .domains-help[data-open] .domains-help-pop, .domains .domains-help:not([data-ready]):is(:hover, :focus-within) .domains-help-pop { display: block; }
@media (prefers-reduced-motion: no-preference) { .domains .domains-help[data-open] .domains-help-pop { animation: domains-confirm-in .12s ease-out; } }
.domains .domains-help-toc { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; margin-top: .5rem; }
.domains .domains-prose h2 { margin-top: 1.75rem; scroll-margin-top: 5rem; }
.domains .domains-prose h2:first-child { margin-top: 0; }
.domains .domains-prose p, .domains .domains-prose ul { margin-top: .625rem; max-width: 42rem; }
.domains .domains-prose ul { padding-left: 1.25rem; list-style: disc; }
.domains .domains-prose li + li { margin-top: .25rem; }
/* ---------- Domain page ---------- */
.domains .domains-crumb { font-size: .8125rem; color: var(--e-muted); margin-bottom: .5rem; }
.domains .domains-crumb a { color: var(--e-muted); text-decoration: none; }
.domains .domains-transfer { max-width: 36rem; }
.domains .domains-crumb a:hover { color: var(--e-fg); }
.domains .domains-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.domains .domains-ph { position: relative; display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-bottom: 1.5rem; }
.domains .domains-ph__sub { flex-basis: 100%; margin: -.5rem 0 0; color: var(--e-muted); font-size: .875rem; }
.domains h1.domains-mono { font-size: 1.75rem; line-height: 1.2; font-weight: 600; overflow-wrap: anywhere; }
.domains .domains-ph ~ .domains-notice { align-items: center; margin-bottom: 1.5rem; }
.domains .domains-notice__tx { flex: 1; }
.domains .domains-cols { display: grid; grid-template-columns: minmax(0, 760px) 320px; gap: 1.5rem; align-items: start; }
.domains .domains-cols > * > .domains-panel + .domains-panel { margin-top: 1.5rem; }
.domains .domains-panel__h { padding: 1rem 1.25rem; border-bottom: 1px solid var(--e-line); }
.domains .domains-panel__h h2, .domains .domains-card, .domains .domains-card dd { margin: 0; }
.domains .domains-rows { list-style: none; margin: 0; padding: 0; }
.domains .domains-panel__b { padding: 1rem 1.25rem; }
.domains .domains-rows > .domains-row { position: relative; display: grid; grid-template-columns: 11.25rem minmax(0, 1fr) auto; gap: 1rem; align-items: center; padding: .875rem 1.25rem; border-bottom: 1px solid var(--e-line); }
.domains .domains-rows > .domains-row:last-child { border-bottom: 0; }
.domains .domains-row > form { display: contents; }
.domains .domains-row > dialog, .domains .domains-row > form > dialog { grid-column: 1 / -1; }
.domains .domains-row__l { display: inline-flex; align-items: center; gap: .375rem; font-size: .8125rem; font-weight: 500; color: var(--e-muted); }
.domains .domains-row__v { min-width: 0; font-weight: 500; overflow-wrap: anywhere; }
.domains .domains-row__a { display: flex; flex-wrap: wrap; gap: .75rem; font-size: .8125rem; font-weight: 600; }
.domains .domains-sub { display: block; margin-top: .1875rem; font-size: .75rem; font-weight: 400; color: var(--e-muted); }
.domains .domains-linkbtn { padding: 0; border: 0; background: none; font: inherit; cursor: pointer; }
.domains .domains-inline { display: inline; margin: 0; }
.domains .domains-card > div { position: relative; display: flex; justify-content: space-between; gap: .75rem; padding: .5rem 0; border-bottom: 1px solid var(--e-line); }
.domains .domains-card > div:last-child { border-bottom: 0; }
.domains .domains-card dt { display: inline-flex; align-items: center; gap: .375rem; font-size: .8125rem; font-weight: 500; color: var(--e-muted); }
.domains .domains-card dd { min-width: 0; text-align: right; font-weight: 500; }
.domains .domains-missing__line { display: inline-flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .25rem .375rem; }
.domains .domains-missing__line .domains-missing__fix { margin-inline-start: 0; }
.domains .domains-card .domains-missing__fix { font-size: .8125rem; }
@media (min-width: 640px) {
  .domains .domains-card dt { flex: none; white-space: nowrap; }
  .domains .domains-missing__line { flex-wrap: nowrap; white-space: nowrap; }
}
.domains .domains-btn--block { display: flex; justify-content: center; box-sizing: border-box; width: 100%; margin-top: 1rem; }
.domains .domains-edit { width: min(40rem, calc(100vw - 2rem)); }
.domains .domains-edit:not(:modal) { max-width: none; }
.domains .domains-edit__close { display: flex; justify-content: flex-end; margin-top: .75rem; }
@media (max-width: 900px) {
    .domains .domains-cols { grid-template-columns: minmax(0, 1fr); }
    .domains .domains-rows > .domains-row { grid-template-columns: minmax(0, 1fr) auto; }
    .domains .domains-row__l { grid-column: 1 / -1; }
}
</style>
@endonce
