{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Staff page layout that holds without a skin. Unlayered, so it sits over Filament; a skin's own rules on these classes are more specific. --}}
<style>
.domains-mono,.domains-wrap,.domains-row .fi-in-entry-content-col{overflow-wrap:anywhere}
.domains-title{display:inline-flex;flex-wrap:wrap;align-items:center;gap:.5rem;min-width:0}
.domains-name-cell{overflow-wrap:break-word;min-width:11rem;max-width:24rem}
.domains-missing-mark{color:var(--danger-500)}
.domains-note{min-width:14rem;max-width:24rem;overflow-wrap:anywhere}
.domains-narrow{max-width:12rem}
.domains-cut{max-width:11rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.domains-watch-header .fi-ac{flex-wrap:wrap}
@media (min-width:40rem){.domains-watch-header{flex-wrap:wrap!important}.domains-watch-header>div:first-child{flex:1 1 100%;min-width:0}.domains-watch-header .fi-header-actions-ctn{flex:1 1 30rem;min-width:0}}
.domains-small{font-size:.75rem;opacity:.75}
.domains-quiet{opacity:.75}
.domains-strong{font-weight:500}
.domains-nowrap{white-space:nowrap}
.domains-blocked{opacity:.6;cursor:not-allowed}
.domains-table-wrap{max-width:100%;overflow-x:auto}
.domains-table{width:100%;border-collapse:collapse;font-size:.875rem}
.domains-table th{padding:8px 12px;text-align:start;white-space:nowrap;font-weight:500}
.domains-table td{padding:8px 12px;vertical-align:top}
.domains-table tbody tr{border-top:1px solid color-mix(in srgb,currentColor 20%,transparent)}
.domains-table-tag{margin-inline-start:6px;padding:1px 6px;border-radius:4px;border:1px solid currentColor;font-size:11px;opacity:.8;white-space:nowrap}
</style>
