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
.domains .domains-a { color: var(--e-accent); font-weight: 600; text-underline-offset: 3px; }
.domains .domains-a:hover { text-decoration: underline; }
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
.domains .domains-searchbar:focus-within { border-color: var(--e-accent); box-shadow: 0 0 0 4px hsl(var(--color-primary) / .14); }
.domains .domains-searchbar input { flex: 1 1 auto; min-width: 0; border: 0; background: transparent; color: var(--e-fg); font-size: 1.1875rem; font-weight: 600; padding: .625rem .875rem; letter-spacing: -.005em; }
.domains .domains-searchbar input::placeholder { color: var(--e-muted); font-weight: 400; }
.domains .domains-searchbar :is(input, select):focus { outline: none; box-shadow: none; }
.domains .domains-searchbar :is(input, select):focus-visible { outline: 2px solid currentColor; outline-offset: -3px; }
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
</style>
@endonce
