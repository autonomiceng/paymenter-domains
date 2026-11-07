{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Staff page layout that holds without a skin. Unlayered, so it sits over Filament; a skin's own rules on these classes are more specific. --}}
<style>
.domains-mono,.domains-wrap,.domains-row .fi-in-entry-content-col{overflow-wrap:anywhere}
.domains-title{display:inline-flex;flex-wrap:wrap;align-items:center;gap:.5rem;min-width:0}
.domains-missing-mark{display:block;margin-top:.1875rem;color:var(--danger-500)}
.domains-note{min-width:14rem;max-width:24rem;overflow-wrap:anywhere}
.domains-narrow{max-width:12rem}
/* Staff rows are one line: long text ends in "..." and its tooltip has all of it. Keyboard focus shows it in place. */
.domains-cut,.domains-cut>*,.domains-line,.domains-line>*,.domains-name-cell,.domains-name-cell>*{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.domains-cut{max-width:11rem}
.domains-line,.domains-name-cell{max-width:24rem}
a:focus-visible :is(.domains-cut,.domains-line,.domains-name-cell),a:focus-visible :is(.domains-cut,.domains-line,.domains-name-cell)>*{white-space:normal;overflow-wrap:anywhere}
.domains-line.fi-ta-text-has-descriptions{display:flex;align-items:baseline;gap:.375rem}.domains-line.fi-ta-text-has-descriptions>.fi-ta-text-item{flex:none}.domains-line.fi-ta-text-has-descriptions>.fi-ta-text-description{min-width:0}
.domains-money{font-variant-numeric:tabular-nums;white-space:nowrap}.fi-ta-cell .domains-money{text-align:end}
.domains-watch-header .fi-ac{flex-wrap:wrap}
@media (min-width:40rem){.domains-watch-header{flex-wrap:wrap!important}.domains-watch-header>div:first-child{flex:1 1 100%;min-width:0}.domains-watch-header .fi-header-actions-ctn{flex:1 1 30rem;min-width:0}}
.domains-small{font-size:.75rem;opacity:.75}
.domains-quiet{opacity:.75}
.domains-strong{font-weight:500}
.domains-nowrap{white-space:nowrap}
.domains-blocked{opacity:.6;cursor:not-allowed}
.domains-row-target{scroll-margin-top:5rem;box-shadow:inset 2px 0 var(--primary-500);background:color-mix(in srgb,var(--primary-500) 10%,transparent)}
/* Shared Blade facts and tables use the same sheet structure as native Filament rows.
   These neutral fallbacks also work without a panel skin. */
.domains-sec .domains{min-width:0}
.domains-sec .domains-card{margin:0}
.domains-sec .domains-card>div{display:grid;grid-template-columns:156px minmax(0,1fr);gap:16px;align-items:start;min-height:44px;padding:8px 24px;border-top:1px solid var(--beco-line-soft,color-mix(in srgb,currentColor 20%,transparent))}
.domains-sec .domains-card dt{font-weight:400;color:var(--beco-fg-2,inherit)}
.domains-sec .domains-card dd{margin:0;min-width:0;overflow-wrap:anywhere}
.domains-sub{display:block;margin-top:3px;font-size:.75rem;line-height:1.125rem;color:var(--beco-fg-2,inherit)}
.domains-hint{margin:6px 24px;font-size:.75rem;line-height:1.125rem;color:var(--beco-fg-2,inherit)}
.domains-h3{margin:12px 24px 8px;font-size:.875rem;font-weight:600}
.domains-tablewrap{max-width:100%;overflow-x:auto}
.domains-tbl{width:100%;border-collapse:collapse;font-size:.875rem}
.domains-tbl th{padding:8px 12px;text-align:start;font-weight:500;color:var(--beco-fg-2,inherit)}
.domains-tbl td{padding:8px 12px;vertical-align:top;overflow-wrap:anywhere}
.domains-tbl tbody tr{border-top:1px solid var(--beco-line-soft,color-mix(in srgb,currentColor 20%,transparent))}
.domains-tbl .domains-money{text-align:end}
@media(max-width:40rem){.domains-sec .domains-card>div{grid-template-columns:minmax(0,1fr);gap:4px;padding:8px 16px}}
.domains-table-wrap{max-width:100%;overflow-x:auto}
.domains-table{width:100%;border-collapse:collapse;font-size:.875rem}
.domains-table th{padding:8px 12px;text-align:start;white-space:nowrap;font-weight:500}
.domains-table td{padding:8px 12px;vertical-align:top}
.domains-table tbody tr{border-top:1px solid color-mix(in srgb,currentColor 20%,transparent)}
.domains-table-tag{margin-inline-start:6px;padding:1px 6px;border-radius:4px;border:1px solid currentColor;font-size:11px;opacity:.8;white-space:nowrap}
.domains-space{margin-top:.75rem}
.domains-list{list-style:disc;padding-inline-start:1.25rem}
.domains-scroll{max-height:24rem;overflow:auto}
.domains-scroll-short{max-height:8rem}
.domains-disclosure{cursor:pointer}
</style>
