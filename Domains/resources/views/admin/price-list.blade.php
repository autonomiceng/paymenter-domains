<x-filament-panels::page>
    <style>
        .domains-prices{border:1px solid var(--gray-200);border-radius:.75rem;overflow:hidden;background:#fff;color:var(--gray-950)}
        .dark .domains-prices{border-color:rgb(255 255 255 / .1);background:var(--gray-900);color:#fff}
        .domains-prices th,.domains-prices td{padding:.75rem;text-align:left;border-bottom:1px solid var(--gray-200);vertical-align:top}
        .dark .domains-prices th,.dark .domains-prices td{border-color:rgb(255 255 255 / .1)}
        .domains-prices thead{background:var(--gray-50)}.dark .domains-prices thead{background:rgb(255 255 255 / .05)}
        .domains-prices .money{white-space:nowrap;font-variant-numeric:tabular-nums}
        .domains-prices .loss{color:#b91c1c}.dark .domains-prices .loss{color:#fca5a5}
        .domains-prices .missing{color:#92400e}.dark .domains-prices .missing{color:#fcd34d}
        .domains-price-filters{display:flex;flex-wrap:wrap;gap:1rem}.domains-price-filters label{display:grid;gap:.4rem;min-width:10rem}
        .domains-price-filters select{border:1px solid #9ca3af;border-radius:.5rem;padding:.5rem 2rem .5rem .75rem;background:transparent;color:inherit}
        .dark .domains-price-filters select option{background:var(--gray-900);color:#fff}
        .domains-price-muted{color:var(--gray-500)}.dark .domains-price-muted{color:var(--gray-400)}
        .domains-price-help{display:grid;gap:1rem;font-size:.875rem}
        .domains-price-rule{display:grid;gap:.4rem}
        .domains-price-rule-head{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;font-weight:600}
        .domains-price-help code{display:block;width:fit-content;max-width:100%;padding:.4rem .75rem;border-radius:.5rem;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.8125rem;
            background:var(--gray-50);border:1px solid var(--gray-200);color:var(--gray-950);overflow-wrap:anywhere}
        .dark .domains-price-help code{background:rgb(255 255 255 / .05);border-color:rgb(255 255 255 / .1);color:#fff}
        .domains-price-help ul{display:grid;gap:.35rem;margin:0;padding-left:1.1rem;list-style:disc}
    </style>
    <div class="domains-price-filters">
        <label>Years<select wire:model.live="years">@foreach(range(1,10) as $year)<option value="{{ $year }}">{{ $year }} {{ $year === 1 ? 'year' : 'years' }}</option>@endforeach</select></label>
        <label>Show<select wire:model.live="filter">@foreach(\Paymenter\Extensions\Servers\Domains\Services\PriceList::FILTERS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        <label>Registrar account<select wire:model.live="serverId"><option value="">All accounts</option>@foreach($matrix['accounts'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
    </div>
    <x-filament::section heading="How prices are worked out" collapsible>
        <div class="domains-price-help">
            <p>Each TLD uses its account's Default pricing, or Custom pricing set just for that TLD. Yearly prices are rounded first, then multiplied by the years.</p>
            @foreach($matrix['explanations'] as $explanation)
                <div class="domains-price-rule" data-price-explanation>
                    <div class="domains-price-rule-head">
                        <span>{{ $explanation['account'] }}</span>
                        <span data-price-pricing><x-filament::badge :color="$explanation['pricing'] === 'Custom' ? 'info' : 'gray'">{{ $explanation['pricing'] }}</x-filament::badge></span>
                        @if($explanation['pricing'] === 'Custom')<span class="domains-price-muted" style="font-weight:400">for {{ implode(', ',$explanation['tlds']) }}</span>@endif
                    </div>
                    <code data-price-formula>{{ $explanation['formula'] }}</code>
                    <p class="domains-price-muted">Card fee: {{ \Paymenter\Extensions\Servers\Domains\Services\CardFee::describe($explanation['fee']) }} per payment.@if($explanation['fee_in_price']) It is worked out on the final price, so the price covers it.@endif</p>
                </div>
            @endforeach
            <ul class="domains-price-muted" data-price-notes>
                <li>The table shows saved prices. If a price does not match its current rule, save the rule again to update it.</li>
                <li>Est. profit = price − registrar cost − card fee on that price. The fixed part of the fee is counted once per payment, however many years. International cards and refunds can cost more, so treat it as an estimate.</li>
                <li>Red marks a price below registrar cost, or a loss after the card fee. Issued invoices keep their prices.</li>
            </ul>
        </div>
    </x-filament::section>
    <p class="domains-price-muted" style="font-size:.875rem">Missing means no price is saved for this many years. It is never worked out from the 1-year price.</p>
    <div class="domains-prices" style="overflow-x:auto">
        <table data-price-list style="width:100%;min-width:58rem;font-size:.875rem">
            <caption style="text-align:left;padding:.75rem">Saved prices in <span data-price-currency>USD</span> for <span data-price-years>{{ $matrix['years'] }}</span> {{ $matrix['years'] === 1 ? 'year' : 'years' }}</caption>
            <thead>
                <tr><th scope="col" rowspan="2">TLD</th>@foreach(\Paymenter\Extensions\Servers\Domains\Services\PriceList::ACTIONS as $label)<th scope="colgroup" colspan="3">{{ $label }}</th>@endforeach</tr>
                <tr>@foreach(\Paymenter\Extensions\Servers\Domains\Services\PriceList::ACTIONS as $label)<th scope="col">Cost</th><th scope="col">Price</th><th scope="col">Est. profit</th>@endforeach</tr>
            </thead>
            <tbody>
            @forelse($matrix['rows'] as $row)
                @php($values=app(\Paymenter\Extensions\Servers\Domains\Services\PriceList::class)->values($row))
                <tr data-price-row="{{ $row['id'] }}">
                    <td style="min-width:9rem">
                        <div style="font-weight:600" data-price-value="0">{{ $row['tld'] }}</div>
                        <div class="domains-price-muted" style="font-size:.75rem"><span data-price-value="1">{{ $row['account'] }}</span> · <span data-price-value="3">{{ $row['pricing'] }}</span></div>
                    </td>
                    @foreach($row['prices'] as $action => $cell)
                        @php($offset=5 + $loop->index*4)
                        @foreach(['cost'=>'Cost','price'=>'Price','profit'=>'Est. profit'] as $key => $label)
                            <td class="money {{ $key !== 'cost' && ($cell['below_cost'] || $cell['loses_money']) ? 'loss' : '' }}">
                                <span data-price-value="{{ $offset+$loop->index }}" @if($cell[$key] === null) class="missing" @endif>{{ $values[$offset+$loop->index] }}</span>
                                @if($key === 'profit')<div data-price-value="{{ $offset+3 }}" style="white-space:normal;font-size:.75rem">{{ $values[$offset+3] }}</div>@endif
                            </td>
                        @endforeach
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="10">No prices match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

</x-filament-panels::page>
