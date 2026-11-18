{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Staff page layout that holds without a skin. Unlayered, so it sits over Filament; a skin's own rules on these classes are more specific. --}}
<style>
/* Filament reverses end-aligned footers. Keep the configured Cancel-first DOM and keyboard order visible. */
.domains-dialog .fi-modal-footer:is(.fi-align-end,.fi-align-right) .fi-modal-footer-actions{flex-direction:row;justify-content:flex-end}
/* Phones: dialog buttons stack full width with the saving action on top; Cancel stays first for keyboards. */
@media (max-width:639px){.domains-dialog .fi-modal-footer .fi-modal-footer-actions,.domains-dialog .fi-modal-footer:is(.fi-align-end,.fi-align-right) .fi-modal-footer-actions{display:flex;flex-direction:column-reverse;align-items:stretch}.domains-dialog .fi-modal-footer-actions>*{width:100%}}
.domains-mono,.domains-wrap,.domains-panel-head-title,.domains-row .fi-in-entry-content-col{overflow-wrap:anywhere}
.domains-title{display:inline-flex;flex-wrap:wrap;align-items:center;gap:.5rem;min-width:0}
/* Not read and other calm words: the panel's muted gray, the same tone as a gray fact value. */
.domains .domains-muted{color:var(--e-muted,var(--gray-400))}
/* Domain price action: a plain item in the customer header's action row; a long last-change reason in its confirm wraps. */
.domains-comp{display:contents}
/* With it, the header row wraps whole: on a phone the native buttons move under it instead of stacking beside it. */
.fi-header-actions-ctn:has(>.domains-comp){flex-wrap:wrap}
.fi-header-actions-ctn:has(>.domains-comp)>.fi-ac{flex:0 1 auto}
.domains-comp-last{overflow-wrap:anywhere}
.domains-missing-mark{display:inline-block;width:14px;height:16px;flex:none;vertical-align:middle;color:inherit}
.domains-missing{display:inline-flex;align-items:center;gap:.375rem;color:var(--panel-err,var(--danger-600));font-weight:600}
.domains-missing--calm{color:var(--panel-fg-3,var(--gray-500));font-weight:500}
.domains-missing__cause{margin-inline-start:.5rem;color:var(--panel-fg-2,var(--gray-500))}
.domains-note{min-width:14rem;max-width:24rem;overflow-wrap:anywhere}
.domains-narrow{max-width:12rem}
/* P-TBL-7: columns size to content. Values and people's names stay whole on one line, and only a linked
   name or note shortens with "..." (the record it opens shows it in full). */
.domains-line{display:block;white-space:nowrap}
/* A person's name stays on one line and stops at about 24 characters with '...'; the cell's title holds the full name. */
.fi-ta-text-item.domains-person,.domains-person>.fi-ta-text-item{max-width:24ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.domains-cut{display:block;white-space:normal;overflow-wrap:break-word}
.domains-name-cell{display:block;white-space:nowrap}
.domains-list-note{white-space:normal}
a :is(.domains-name-cell,.domains-list-note),a :is(.domains-name-cell,.domains-list-note)>.fi-ta-text-item{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* Filament draws each table text item as a line-clamp -webkit-box, which paints no "...". Ours are plain blocks. */
/* Column::whole(): on a phone the name keeps its width cap but wraps there instead of being cut. */
@media(max-width:39.99rem){a .domains-name-whole,a .domains-name-whole>.fi-ta-text-item,.domains-name-whole,.domains-name-whole>.fi-ta-text-item{white-space:normal;overflow:visible;text-overflow:clip}.domains-name-whole .domains-dn{display:inline-block;white-space:normal;overflow-wrap:break-word}.domains-name-whole .domains-dn-head{overflow:visible}}
.fi-ta-text-item:is(.domains-cut,.domains-line,.domains-name-cell):not(.fi-bulleted li.fi-ta-text-item),:is(.domains-cut,.domains-line,.domains-name-cell)>.fi-ta-text-item:not(.fi-bulleted li.fi-ta-text-item){display:block}
[data-domains-table] .fi-ta-table{table-layout:auto;width:100%}
/* P-TBL-7: a staff row is one line, 44px: 20px of text, 12px above and 11px below, and the 1px line between rows. */
[data-domains-table] .fi-ta-row>td .fi-ta-text{padding-block:12px 11px}
[data-domains-table] .fi-ta-row>td :is(.fi-ta-text,.fi-ta-text-item,.domains-panel-val){line-height:20px}
[data-domains-table] .fi-ta-header-cell{white-space:nowrap}
/* P-TBL-7: a linked name or note keeps its full width while the row has room, and shortens with '...' only when it has none. */
[data-domains-table] td:has(a .domains-list-note)>*{display:grid;grid-template-columns:minmax(0,max-content)}
/* P-TBL-12: narrower than the default columns, the table scrolls inside its surface. The identity column stays put, and
   once the rows have scrolled under it, it casts an edge shadow. Browsers without scroll timelines skip the shadow. */
[data-domains-table] .fi-ta-content-ctn{overflow-x:auto}
@media(max-width:1279px){[data-domains-table] .fi-ta-table{width:max-content;min-width:100%}}
/* P-TBL-7: every column but Note sizes to its content on one line. Note is the last column and takes only the room left:
   it shortens with '...' and the reveal shows it in full. A domain name caps itself (DomainName::cell). */
@media(min-width:1280px){[data-domains-table] td.domains-note-cell{width:100%;min-width:8rem}}
@media(max-width:1279px){[data-domains-table] td.domains-note-cell>*{max-width:24rem}}
[data-domains-table] .domains-list-note,[data-domains-table] .domains-list-note>.fi-ta-text-item{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
{{-- The shared admin CSS gives a clamped note margin instead of padding; a one-line note keeps the cell padding, so the row stays 44px. --}}
[data-domains-table] .fi-ta-cell .domains-list-note{margin-block:0}
td.domains-history-detail{min-width:20rem}
[data-domains-table] .domains-note{min-width:0;max-width:none}
[data-domains-table] :is(.fi-ta-row>td,.fi-ta-table>thead>tr>th):first-child{position:sticky;inset-inline-start:0;z-index:1;background:var(--panel-card,#111B36)}
[data-domains-table] :is(.fi-ta-row>td,.fi-ta-table>thead>tr>th):first-child:has(input[type=checkbox])+:is(td,th){position:sticky;inset-inline-start:44px;z-index:1;background:var(--panel-card,#111B36)}
@keyframes domains-edge{from{box-shadow:none}to{box-shadow:8px 0 8px -8px rgb(0 0 0/.6)}}
@supports (animation-timeline:scroll()){
 [data-domains-table] :is(.fi-ta-row>td,.fi-ta-table>thead>tr>th):first-child:not(:has(input[type=checkbox])),
 [data-domains-table] :is(.fi-ta-row>td,.fi-ta-table>thead>tr>th):first-child:has(input[type=checkbox])+:is(td,th){animation:domains-edge linear both;animation-timeline:scroll(nearest inline);animation-range:0 24px}
}
/* Keyboard focus on a cut text, or on the row link around it, shows the whole text over the page (staff-reveal), so no cell or row changes size. */
.domains-reveal{position:fixed;z-index:60;max-width:min(24rem,calc(100vw - 16px));max-height:calc(100vh - 16px);max-height:calc(100dvh - 16px);overflow-y:auto;overscroll-behavior:contain;padding:6px 10px;border:1px solid var(--panel-line,color-mix(in srgb,currentColor 20%,transparent));border-radius:6px;background:var(--panel-card-2,#fff);color:var(--panel-fg,#111827);box-shadow:0 4px 12px rgb(0 0 0/.2);font-size:.875rem;line-height:1.25rem;white-space:pre-line;overflow-wrap:anywhere;pointer-events:none}
.dark .domains-reveal{background:var(--panel-card-2,#18181b);color:var(--panel-fg,#fff)}
.domains-reveal[hidden]{display:none}
.domains-reveal[data-domains-scroll]{pointer-events:auto}
.domains-line.fi-ta-text-has-descriptions{display:flex;align-items:baseline;gap:.375rem}.domains-line.fi-ta-text-has-descriptions>.fi-ta-text-item{flex:none}.domains-line.fi-ta-text-has-descriptions>.fi-ta-text-description{min-width:0}
.domains-money{font-variant-numeric:tabular-nums;white-space:nowrap}.fi-ta-cell .domains-money{text-align:end}
.domains-panel-page-head .fi-ac{flex-wrap:wrap}
@media (min-width:40rem){.domains-panel-page-head{flex-wrap:wrap!important}.domains-panel-page-head>div:first-child{min-width:0}}
.domains-small{font-size:.75rem;opacity:.75}
.domains-quiet{opacity:.75}
.domains-strong{font-weight:500}
.domains-nowrap{white-space:nowrap}
.domains-blocked{opacity:.6;cursor:not-allowed}
.domains-row-target{scroll-margin-top:5rem;box-shadow:inset 2px 0 var(--primary-500);background:color-mix(in srgb,var(--primary-500) 10%,transparent)}
/* Shared customer mark shapes, resolved through native panel and optional skin tokens. */
.domains-status { display:inline-flex;align-items:center;--domains-fg:var(--panel-fg,inherit);--domains-quiet:var(--panel-fg-3,var(--gray-400));--domains-ok:var(--success-500);--domains-info:var(--panel-cyan,var(--info-500));--domains-warn:var(--panel-warn,var(--warning-500));--domains-bad:var(--panel-err,var(--danger-500));color:var(--domains-fg) }
.domains-status { gap: .4375rem; padding: 0; border-radius: 0; font-size: inherit; font-weight: 500; line-height: inherit; background: none; --domains-mark: var(--domains-quiet); }
.domains-status::before { width: 14px; height: 16px; flex: none; border-radius: 0; background: var(--domains-mark);
    mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 18'%3E%3Cpath d='M8 1 15 5v8l-7 4-7-4V5z' fill='none' stroke='black' stroke-width='1.4' stroke-linejoin='round'/%3E%3Cpath d='M5.6 9h4.8' stroke='black' stroke-width='1.7' stroke-linecap='round'/%3E%3C/svg%3E") center / contain no-repeat; }
.domains-status.domains-tone-ok { color: var(--domains-fg); --domains-mark: var(--domains-ok); }
.domains-status.domains-tone-info { --domains-mark: var(--domains-info); }
.domains-status.domains-tone-warn { --domains-mark: var(--domains-warn); }
.domains-status.domains-tone-bad { --domains-mark: var(--domains-bad); }
.domains-status.domains-tone-ok::before { mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 18'%3E%3Cmask id='m'%3E%3Crect width='16' height='18' fill='white'/%3E%3Cpath d='M4.9 9.3 7 11.4l4.1-4.7' fill='none' stroke='black' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/mask%3E%3Cpath mask='url(%23m)' d='M8 1 15 5v8l-7 4-7-4V5z' stroke='black' stroke-width='1.4' stroke-linejoin='round'/%3E%3C/svg%3E"); }
.domains-status.domains-tone-info::before { mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 18'%3E%3Cpath d='M8 1 15 5v8l-7 4-7-4V5z' fill='none' stroke='black' stroke-width='1.4' stroke-linejoin='round'/%3E%3Ccircle cx='8' cy='9' r='2'/%3E%3C/svg%3E"); }
.domains-status.domains-tone-warn::before { mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 18'%3E%3Cmask id='m'%3E%3Crect width='16' height='18' fill='white'/%3E%3Cpath d='M8 5.4v4.2' stroke='black' stroke-width='1.7' stroke-linecap='round'/%3E%3Ccircle cx='8' cy='12.4' r='1.1'/%3E%3C/mask%3E%3Cpath mask='url(%23m)' d='M8 1 15 5v8l-7 4-7-4V5z' stroke='black' stroke-width='1.4' stroke-linejoin='round'/%3E%3C/svg%3E"); }
.domains-status.domains-tone-bad::before { mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 18'%3E%3Cmask id='m'%3E%3Crect width='16' height='18' fill='white'/%3E%3Cpath d='M5.7 6.7l4.6 4.6M10.3 6.7l-4.6 4.6' stroke='black' stroke-width='1.7' stroke-linecap='round'/%3E%3C/mask%3E%3Cpath mask='url(%23m)' d='M8 1 15 5v8l-7 4-7-4V5z' stroke='black' stroke-width='1.4' stroke-linejoin='round'/%3E%3C/svg%3E"); }
.domains-status::before { content:"" }
.domains-status.domains-tone-warn { color:var(--domains-warn) }
.domains-status.domains-tone-bad { color:var(--domains-bad) }
.domains-status.domains-tone-muted { color:var(--domains-quiet);--domains-mark:var(--domains-quiet) }
/* Shared Blade facts and tables use the same sheet structure as native Filament rows.
   These neutral fallbacks also work without a panel skin. */
.domains-sec .domains{min-width:0}
.domains-sec .domains-card{margin:0}
.domains-sec .domains-card>div{display:grid;grid-template-columns:156px minmax(0,1fr);gap:16px;align-items:start;min-height:44px;padding:8px 24px;border-top:1px solid var(--panel-line-soft,color-mix(in srgb,currentColor 20%,transparent))}
.domains-sec .domains-card dt{font-weight:400;color:var(--panel-fg-2,inherit)}
.domains-sec .domains-card dd{color:var(--panel-fg,inherit);margin:0;min-width:0;overflow-wrap:anywhere}
.domains-sec .domains-card .domains-mono{font-family:var(--panel-font-mono,ui-monospace,monospace);font-weight:600}
.domains-sub{display:block;margin-top:3px;font-size:.75rem;line-height:1.125rem;color:var(--panel-fg-2,inherit)}
.domains-hint{margin:6px 24px;font-size:.75rem;line-height:1.125rem;color:var(--panel-fg-2,inherit)}
.domains-h3{margin:12px 24px 8px;font-size:.875rem;font-weight:600}
.domains-tablewrap{max-width:100%;overflow-x:auto}
.domains-tbl{width:100%;border-collapse:collapse;font-size:.875rem}
.domains-tbl th{padding:8px 12px;text-align:start;font-weight:500;color:var(--panel-fg-2,inherit)}
.domains-tbl td{padding:8px 12px;vertical-align:top;overflow-wrap:anywhere}
.domains-tbl tbody tr{border-top:1px solid var(--panel-line-soft,color-mix(in srgb,currentColor 20%,transparent))}
.domains-tbl .domains-money{text-align:end}
@media(max-width:40rem){.domains-sec .domains-card>div{grid-template-columns:minmax(0,1fr);gap:4px;padding:8px 16px}}
.domains-table-wrap{max-width:100%;overflow-x:auto}
.domains-table{width:100%;border-collapse:collapse;font-size:.875rem}
.domains-table th{padding:8px 12px;text-align:start;white-space:nowrap;font-weight:500}
.domains-table td{padding:8px 12px;vertical-align:top}
.domains-table tbody tr{border-top:1px solid color-mix(in srgb,currentColor 20%,transparent)}
.domains-table tbody tr.domains-table-note{border-top:0}
.domains-table tr.domains-table-note td{padding-top:0}
.domains-table-tag{margin-inline-start:6px;padding:1px 6px;border-radius:4px;border:1px solid currentColor;font-size:11px;opacity:.8;white-space:nowrap}
.domains-space{margin-top:.75rem}
.domains-list{list-style:disc;padding-inline-start:1.25rem}
.domains-scroll{max-height:24rem;overflow:auto}
.domains-scroll-short{max-height:8rem}
.domains-disclosure{cursor:pointer}
.domains-total{display:block;font-size:1.5rem;font-weight:600;line-height:1.25;font-variant-numeric:tabular-nums}

/* Panel pieces (Value, Row, SettingsRow, Trace) without a skin: stacked lines on phones, the sheet's label, value and
   action columns from 40rem. Zero specificity, so a skin's rules win whatever order the sheets load in. */
:where(.domains-panel-val){display:grid;gap:2px;min-width:0}
:where(.domains-panel-sub){font-size:.75rem;line-height:1.125rem;color:var(--panel-fg-2,inherit)}
:where(.domains-panel-fact[data-domains-fact=host],.domains-panel-fact[data-domains-fact=code]){font-family:var(--panel-font-mono,ui-monospace,monospace)}
:where(.domains-panel-fact[data-domains-fact=money],.domains-panel-fact[data-domains-fact=count]){font-variant-numeric:tabular-nums}
:where(.domains-panel-st){font-weight:500}
:where(.domains-panel-row){display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 16px;align-items:center;min-height:44px;padding:8px 0;border-top:1px solid var(--panel-line-soft,color-mix(in srgb,currentColor 20%,transparent))}
:where(.domains-panel-row-k){grid-column:1/-1;min-width:0;color:var(--panel-fg-2,inherit)}
:where(.domains-panel-row-v){min-width:0;overflow-wrap:anywhere}
.domains-panel-row-v .domains-demo-tag{display:inline-block;margin-inline-start:8px;padding:0 5px;border-radius:4px;font-size:11px;font-weight:500;line-height:16px;white-space:nowrap;vertical-align:1px}
:where(.domains-panel-row-a,.domains-panel-row-a .fi-ac){display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:12px}
:where(.domains-panel-row-a:empty){display:none}
/* Two actions on a phone: their own line under the value, from the left. Real specificity, so a skin's end alignment can't put them back beside the value. */
@media(max-width:39.99rem){.domains-panel-row[data-domains-actions]>.domains-panel-row-a,.domains-panel-row[data-domains-actions]>.domains-panel-row-a .fi-ac{grid-column:1/-1;justify-content:flex-start}}
:where(.domains-panel-nw){white-space:nowrap}:where(.domains-panel-nw .domains-help){display:inline-flex;vertical-align:middle}
:where(.domains-panel-blocked){cursor:not-allowed}
@media(min-width:40rem){:where(.domains-panel-row){grid-template-columns:156px minmax(0,1fr) auto}:where(.domains-panel-row-k){grid-column:auto}}
:where(.domains-panel-row[data-domains-row=notice]){grid-template-columns:minmax(0,1fr) auto}
/* A hold on the whole page is one notice under the page head: its state mark and a 1px edge in the state's tone, no
   tint (P-NTC-5). The edge replaces the row's divider. Real specificity, so a skin's notice divider can't draw inside it. */
.domains-page-notice{--domains-edge:var(--panel-line,color-mix(in srgb,currentColor 20%,transparent));border:1px solid var(--domains-edge);border-radius:8px;background:var(--panel-card,transparent)}
.domains-page-notice:has(.domains-panel-st[data-domains-tone=prog]){--domains-edge:var(--panel-cyan,var(--info-500))}
.domains-page-notice:has(.domains-panel-st[data-domains-tone=warn]){--domains-edge:var(--panel-warn,var(--warning-500))}
.domains-page-notice:has(.domains-panel-st[data-domains-tone=err]){--domains-edge:var(--panel-err,var(--danger-500))}
.domains-page-notice .domains-panel-row[data-domains-row=notice]{border:0}
/* Below 640px a notice's action goes under its sentence, from where the sentence starts, at full touch size (P-NTC-5).
   Real specificity, so a skin's two-column row can't keep it beside the sentence. */
@media(max-width:39.99rem){.domains-panel-row[data-domains-row=notice]{grid-template-columns:minmax(0,1fr);row-gap:10px}
.domains-panel-row[data-domains-row=notice]>.domains-panel-row-a,.domains-panel-row[data-domains-row=notice]>.domains-panel-row-a .fi-ac{grid-column:1/-1;justify-content:flex-start;justify-self:start}
.domains-panel-row[data-domains-row=notice]>.domains-panel-row-a .fi-ac>*{display:inline-flex;align-items:center;min-height:44px}
:where(.domains-panel-row[data-domains-row=notice]:has(.domains-panel-st)>.domains-panel-row-a){padding-inline-start:21px}}
/* A setting's switch sits right before its On or Off, and the line under them starts at the switch (Ilya, Nov 14, 2026).
   Real specificity: a skin that puts toggles in the action column must not pull it away from its word. */
.domains-panel-row-v[data-domains-switch]{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;column-gap:10px;row-gap:2px}
.domains-panel-row-v[data-domains-switch]>.domains-panel-inline{grid-row:1;grid-column:1;display:flex;align-items:center}
.domains-panel-row-v[data-domains-switch]>.domains-panel-inline :is(.fi-sc-actions,.fi-ac){display:flex;margin:0;padding:0}
.domains-panel-row-v[data-domains-switch]>.domains-panel-val{display:contents}
.domains-panel-row-v[data-domains-switch]>.domains-panel-val>*{grid-column:2}
.domains-panel-row-v[data-domains-switch]>.domains-panel-val>.domains-panel-sub{grid-column:1/-1;margin-top:0}
/* Helper lines keep a readable measure on wide sheets. */
.domains-panel-row-v .domains-panel-sub{max-width:80ch}
:where(.domains-panel-trace){display:grid;gap:12px;margin:0;padding:0;list-style:none}
:where(.domains-panel-step){display:block}
:where(.domains-panel-step-h){display:flex;flex-wrap:wrap;align-items:baseline;gap:0 8px}
:where(.domains-panel-step-d){font-size:.75rem;line-height:1.125rem;color:var(--panel-fg-2,inherit)}
:where(.domains-panel-step-w){font-weight:500;white-space:nowrap}
:where(.domains-panel-step-a){display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin-top:4px}:where(.domains-panel-step-a:empty){display:none}
:where(.domains-panel-partial){display:block;min-width:0;overflow-wrap:anywhere}
:where(.domains-hp-sum){display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 12px}:where(.domains-hp-line){display:block}:where(.domains-hp-err){color:var(--panel-danger,#F87171)}
@media(max-width:39.99rem){.domains-hp>.domains-panel-row-v{display:contents}.domains-hp .domains-hp-line{grid-column:1/-1}}
.domains-hp-dlg{margin:auto;width:min(480px,calc(100vw - 32px));max-height:calc(100dvh - 32px);padding:20px;border-radius:12px;background:var(--panel-card,#111B36);color:inherit;border:1px solid var(--panel-line-soft,color-mix(in srgb,currentColor 20%,transparent))}
.domains-hp-dlg::backdrop{background:rgb(0 0 0/.6)}
.domains-hp-dlg-h,.domains-hp-all,.domains-hp-foot{display:flex;align-items:center;justify-content:space-between;gap:12px}.domains-hp-dlg-h h2{font-size:1.125rem;font-weight:600}
.domains-hp-search,.domains-hp-all,.domains-hp-eff,.domains-hp-err-line{margin-top:12px}.domains-hp-solo{justify-content:flex-end}.domains-hp-foot{justify-content:flex-end;margin-top:16px}
.domains-hp-list{max-height:320px;overflow:auto;margin-top:4px;border-top:1px solid var(--panel-line-soft,color-mix(in srgb,currentColor 20%,transparent))}
.domains-hp-ck{display:flex;align-items:center;gap:12px;min-height:44px}.domains-hp-hidden{margin-inline-start:auto}.domains-hp-gap{width:16px;flex:none}
.domains-hp-searching .domains-hp-nm,.domains-hp-searching .domains-hp-nm *{font-weight:400}.domains-hp-searching .domains-hp-nm b{font-weight:700}
/* The search field has its own clear button, so hide the native one. */
.domains-hp-dlg input[type=search]::-webkit-search-cancel-button,.domains-hp-dlg input[type=search]::-webkit-search-decoration{-webkit-appearance:none;appearance:none;display:none}
.domains-hp-clr{display:grid;place-items:center;min-width:44px;min-height:44px}.domains-hp-none{padding:12px 0}
.fi-modal-window.domains-template-dialog{width:100%;max-width:30rem}
.domains-template-editor{padding:clamp(1rem,2vw,1.5rem)}
.domains-template-editor .domains-template-editor-footer{justify-content:flex-end;margin-block-start:1.5rem}
/* The shared table pins its first cell. Leave room on a phone for the cells scrolling past it. */
@media(max-width:39.99rem){
  .domains-template-list .fi-ta-row>td:first-child .domains-panel-val{max-width:9rem;white-space:normal;overflow-wrap:anywhere}
}
/* Template usage and save effects have full sentences within a narrow rail. */
.domains-template-detail .domains-rail .domains-panel-row{grid-template-columns:minmax(0,1fr) auto}
.domains-template-detail .domains-rail .domains-panel-row-k{grid-column:1/-1}
.domains-template-detail .domains-rail .domains-panel-row-v{min-width:0;overflow-wrap:anywhere}
</style>
@include('domains::partials.domain-name-styles')
