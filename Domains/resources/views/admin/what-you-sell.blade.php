<x-filament-panels::page>
    <style>
        .domains-sell{display:grid;gap:1.5rem}
        .domains-sell-add{display:flex;gap:.75rem;align-items:center;max-width:24rem}.domains-sell-add .fi-input-wrp{flex:1}
        .domains-sell-left{display:flex;gap:.5rem;align-items:flex-start;color:inherit}.domains-sell-left-mark{flex:none;width:1rem;height:1rem;margin-block-start:.125rem;color:var(--warning-500)}
        .domains-sell-table{--domains-sell-edge:20px;--domains-sell-rule:var(--beco-line-soft,var(--gray-200))}.dark .domains-sell-table{--domains-sell-rule:var(--beco-line-soft,#ffffff14)}
        /* What the skin's table head says for a plain cell, for the Register, Renew and Transfer cells above it. */
        .domains-sell-table .fi-ta-header-group-cell{padding:8px 20px;border-width:0 0 1px 1px;border-style:solid;border-color:var(--domains-sell-rule);color:var(--beco-fg-2,var(--gray-400));font-size:13px;font-weight:400;line-height:20px}.domains-sell-table .fi-ta-header-cell{border:0}.domains-sell-table thead .fi-ta-selection-cell{padding-block:8px;text-align:start}
        .domains-sell-choices{display:flex;flex-wrap:wrap;gap:.5rem}
        .domains-sell-note{padding:.5rem 20px;font-size:.8125rem;color:var(--gray-500)}.dark .domains-sell-note{color:var(--gray-400)}
        .domains-price-help{display:grid;gap:1rem;font-size:.875rem}
        .domains-price-help h3{font-weight:600}
        .domains-price-help dl{display:grid;grid-template-columns:minmax(8rem,12rem) minmax(0,1fr);gap:.5rem 1rem}
        .domains-price-help dt{color:var(--gray-500)}.dark .domains-price-help dt{color:var(--gray-400)}
        .domains-price-help code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.8125rem;overflow-wrap:anywhere}
        /* One group needs no second header row. Filament would print it above a single Register. */
        .domains-sell-table .fi-ta-table-head-groups-row:not(:has(.fi-ta-header-group-cell ~ .fi-ta-header-group-cell)){display:none}
        .domains-sell-table .fi-ta-table td,.domains-sell-table .fi-ta-table th{white-space:nowrap}
        .domains-sell-table .fi-ta-table>thead>tr,.dark .domains-sell-table .fi-ta-table>thead>tr{background:none}
        .fi-header-subheading .domains-help-beside{margin-inline:0 .875rem}
        /* With more than one group on, cells are 12px apart as in the mock, so every group fits a 1440 screen. The rules below keep the 20px edge. */
        .domains-sell-table :where(.fi-ta-table:has(.fi-ta-header-group-cell ~ .fi-ta-header-group-cell)) :is(th.fi-ta-header-cell,td .fi-ta-text){padding-inline:12px}
        /* One edge with or without ticks: a text cell's own padding makes it, so the head and a tick box line up with it. */
        .domains-sell-table .fi-ta-table tr>:first-child{padding-inline-start:var(--domains-sell-edge)}.domains-sell-table .fi-ta-table tr>:last-child{padding-inline-end:var(--domains-sell-edge)}
        .domains-sell-table .fi-ta-table>*>tr>.fi-ta-cell.fi-ta-selection-cell:first-child:not(:has(.fi-ta-text)){padding-inline-start:var(--domains-sell-edge)}
        .domains-sell-table .fi-ta-table td:first-child:has(.fi-ta-text){padding-inline-start:0}.domains-sell-table .fi-ta-table td:last-child:has(.fi-ta-text){padding-inline-end:0}
        .domains-sell-table .fi-ta-table td:first-child .fi-ta-text{padding-inline-start:var(--domains-sell-edge)}.domains-sell-table .fi-ta-table td:last-child .fi-ta-text{padding-inline-end:var(--domains-sell-edge)}
        /* The mock's thin rules, only when more than one group shows. Hidden columns leave the page, so a rule goes on the first of each group still there. */
        .domains-sell-table .fi-ta-table:has(.fi-ta-header-group-cell ~ .fi-ta-header-group-cell) :is(:not(.domains-sell-register)+.domains-sell-register,:not(.domains-sell-renew)+.domains-sell-renew,:not(.domains-sell-transfer)+.domains-sell-transfer,.domains-sell-status){border-inline-start:1px solid var(--domains-sell-rule)}
        /* Staff rows are one line at 44px. */
        .domains-sell-table .fi-ta-table td .fi-ta-text{padding-block:10px}.domains-sell-table .fi-ta-table td.fi-ta-selection-cell,.domains-sell-table .fi-ta-table td:last-child{padding-block:0}
        .domains-sell-table .fi-ta-selection-cell,.domains-sell-table .domains-sell-pin{position:sticky;left:0;z-index:1;background:var(--beco-card,Canvas)}
        .dark .domains-sell-table .fi-ta-selection-cell,.dark .domains-sell-table .domains-sell-pin{background:var(--beco-card,var(--gray-900))}
        /* Pinned cells are solid so rows slide under them, so a ticked row's tint is repeated here. Filament's first-cell rule must not unpin them. */
        .domains-sell-table .fi-ta-row.fi-selected>:first-child{position:sticky}
        .domains-sell-table .fi-ta-row.fi-selected>.fi-ta-selection-cell,.domains-sell-table .fi-ta-row.fi-selected>.domains-sell-pin{background:var(--gray-50)}
        .dark .domains-sell-table .fi-ta-row.fi-selected>.fi-ta-selection-cell,.dark .domains-sell-table .fi-ta-row.fi-selected>.domains-sell-pin{background:linear-gradient(#ffffff0d,#ffffff0d),var(--beco-card,var(--gray-900))}
        .domains-sell-table .fi-ta-selection-cell{width:3rem;min-width:3rem}.domains-sell-table .fi-ta-selection-cell + .domains-sell-pin{left:3rem}
        @media(max-width:40rem){
            .domains-sell-note{padding-inline:12px}.domains-price-help dl{grid-template-columns:minmax(0,1fr)}
            /* The account and year choices share a row, and search and the buttons wrap below, so nothing is cut. */
            .domains-sell-table .fi-ta-header-toolbar>div:last-child{flex-wrap:wrap;width:100%;row-gap:.75rem}.domains-sell-table .fi-ta-header-toolbar>.fi-ta-actions:not(:has(>:not([style*="display: none"]))){display:none}
            .domains-sell-choices{flex:1 0 100%;flex-wrap:nowrap}.domains-sell-choices .fi-input-wrp{flex:1 1 auto;min-width:0}
            .domains-sell-table .fi-ta-header-toolbar .fi-ta-search-field{flex:1 0 100%;width:auto;max-width:none}
            .domains-sell-table{--domains-sell-edge:12px}
        }
    </style>
    <div class="domains-sell">
        @if($this->canEdit() && $this->binding() !== null && !$this->readable())
            <form wire:submit="addEnding" class="domains-sell-add">
                <x-filament::input.wrapper><x-filament::input type="text" wire:model="newEnding" placeholder=".com" aria-label="TLD" /></x-filament::input.wrapper>
                <x-filament::button type="submit" color="gray">Add TLD</x-filament::button>
            </form>
        @endif
        @include('domains::admin.sell-explained',['saved' => $this->savedPrices(),'explanations' => $this->explanations()])
        {{ $this->content }}
    </div>
</x-filament-panels::page>
