<x-filament-panels::page>
    <style>
        .domains-prices{border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden;background:#fff;color:#111827}
        .dark .domains-prices{border-color:#374151;background:#111827;color:#f3f4f6}
        .domains-prices th,.domains-prices td{padding:.75rem;text-align:left;border-bottom:1px solid #e5e7eb;vertical-align:top}
        .dark .domains-prices th,.dark .domains-prices td{border-color:#374151}
        .domains-prices thead{background:#f9fafb}.dark .domains-prices thead{background:#1f2937}
        .domains-prices .money{white-space:nowrap;font-variant-numeric:tabular-nums}
        .domains-prices .loss{color:#b91c1c}.dark .domains-prices .loss{color:#fca5a5}
        .domains-prices .missing{color:#92400e}.dark .domains-prices .missing{color:#fcd34d}
        .domains-price-filters{display:flex;flex-wrap:wrap;gap:1rem}.domains-price-filters label{display:grid;gap:.4rem;min-width:10rem}
        .domains-price-filters select{border:1px solid #9ca3af;border-radius:.5rem;padding:.5rem 2rem .5rem .75rem;background:transparent;color:inherit}
        .dark .domains-price-filters select option{background:#111827;color:#f3f4f6}
    </style>
    <div class="domains-price-filters">
        <label>Years<select wire:model.live="years">@foreach(range(1,10) as $year)<option value="{{ $year }}">{{ $year }} {{ $year === 1 ? 'year' : 'years' }}</option>@endforeach</select></label>
        <label>Show<select wire:model.live="filter">@foreach(\Paymenter\Extensions\Servers\Domains\Services\PriceList::FILTERS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        <label>Registrar account<select wire:model.live="serverId"><option value="">All accounts</option>@foreach($matrix['accounts'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
    </div>
    <x-filament::section heading="How prices are worked out" collapsible>
        <div style="display:grid;gap:.75rem">
            <p>Each TLD follows its account default or has its own rule. Yearly prices are rounded first, then multiplied by the years.</p>
            <ul style="display:grid;gap:.75rem">
                @foreach($matrix['explanations'] as $explanation)
                    <li>
                        <div style="font-weight:600">{{ $explanation['account'] }} · {{ $explanation['pricing'] }}@if($explanation['pricing'] === 'Own rule') · {{ implode(', ',$explanation['tlds']) }}@endif</div>
                        <div>{{ $explanation['formula'] }}</div>
                        @if($explanation['fee_in_price'])<div>The card fee is worked out on the final price, so the price covers it.</div>@endif
                        <div>Card fee: {{ \Paymenter\Extensions\Servers\Domains\Services\CardFee::describe($explanation['fee']) }} per payment.</div>
                    </li>
                @endforeach
            </ul>
            <p>The table shows saved prices. If a price does not match its current rule, save the rule again to update it.</p>
            <p>Rough profit = price − registrar cost − card fee on that price. The fixed part of the fee is counted once per payment, however many years. International cards and refunds can cost more, so treat it as an estimate.</p>
            <p>Red marks a price below registrar cost, or a loss after the card fee. Issued invoices keep their prices.</p>
        </div>
    </x-filament::section>
    <p>Missing means no price is saved for this many years. It is never worked out from the 1-year price.</p>
    <div class="domains-prices" style="overflow-x:auto">
        <table data-price-list style="width:100%;min-width:58rem;font-size:.875rem">
            <caption style="text-align:left;padding:.75rem">Saved prices in <span data-price-currency>USD</span> for <span data-price-years>{{ $matrix['years'] }}</span> {{ $matrix['years'] === 1 ? 'year' : 'years' }}</caption>
            <thead>
                <tr><th scope="col" rowspan="2">TLD, account and rule</th>@foreach(\Paymenter\Extensions\Servers\Domains\Services\PriceList::ACTIONS as $label)<th scope="colgroup" colspan="3">{{ $label }}</th>@endforeach</tr>
                <tr>@foreach(\Paymenter\Extensions\Servers\Domains\Services\PriceList::ACTIONS as $label)<th scope="col">Cost</th><th scope="col">Price</th><th scope="col">Rough profit</th>@endforeach</tr>
            </thead>
            <tbody>
            @forelse($matrix['rows'] as $row)
                @php($values=app(\Paymenter\Extensions\Servers\Domains\Services\PriceList::class)->values($row))
                <tr data-price-row="{{ $row['id'] }}">
                    <td style="min-width:11rem">
                        <div style="font-weight:600" data-price-value="0">{{ $row['tld'] }}</div>
                        <div data-price-value="1">{{ $row['account'] }}</div>
                        <div data-price-value="3">{{ $row['pricing'] }}</div>
                    </td>
                    @foreach($row['prices'] as $action => $cell)
                        @php($offset=5 + $loop->index*4)
                        @foreach(['cost'=>'Cost','price'=>'Price','profit'=>'Rough profit'] as $key => $label)
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
