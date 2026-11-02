<x-filament-panels::page>
    <style>
        .domains-sell{display:grid;gap:1.5rem}
        .domains-sell-add{display:flex;gap:.75rem;align-items:center;max-width:24rem}.domains-sell-add .fi-input-wrp{flex:1}
        .domains-sell-left{display:block}
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
        .domains-sell-table .fi-ta-table tr>:first-child{padding-inline-start:20px}.domains-sell-table .fi-ta-table tr>:last-child{padding-inline-end:20px}
        .domains-sell-table .fi-ta-table td:first-child .fi-ta-text{padding-inline-start:0}.domains-sell-table .fi-ta-table td:last-child .fi-ta-text{padding-inline-end:0}
        /* Staff rows are one line at 44px. */
        .domains-sell-table .fi-ta-table td .fi-ta-text{padding-block:10px}.domains-sell-table .fi-ta-table td.fi-ta-selection-cell,.domains-sell-table .fi-ta-table td:last-child{padding-block:0}
        .domains-sell-table .fi-ta-selection-cell,.domains-sell-table .domains-sell-pin{position:sticky;left:0;z-index:1;background:var(--beco-card,Canvas)}
        .dark .domains-sell-table .fi-ta-selection-cell,.dark .domains-sell-table .domains-sell-pin{background:var(--beco-card,var(--gray-900))}
        .domains-sell-table .fi-ta-selection-cell{width:3rem;min-width:3rem}.domains-sell-table .fi-ta-selection-cell + .domains-sell-pin{left:3rem}
        @media(max-width:40rem){
            .domains-sell-note{padding-inline:12px}.domains-price-help dl{grid-template-columns:minmax(0,1fr)}
            /* The account and year choices share a row, and search and the buttons wrap below, so nothing is cut. */
            .domains-sell-table .fi-ta-header-toolbar>div:last-child{flex-wrap:wrap;width:100%;row-gap:.75rem}.domains-sell-table .fi-ta-header-toolbar>.fi-ta-actions:not(:has(>:not([style*="display: none"]))){display:none}
            .domains-sell-choices{flex:1 0 100%;flex-wrap:nowrap}.domains-sell-choices .fi-input-wrp{flex:1 1 auto;min-width:0}
            .domains-sell-table .fi-ta-header-toolbar .fi-ta-search-field{flex:1 0 100%;width:auto;max-width:none}
            .domains-sell-table .fi-ta-table tr>:first-child{padding-inline-start:12px}.domains-sell-table .fi-ta-table tr>:last-child{padding-inline-end:12px}
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
