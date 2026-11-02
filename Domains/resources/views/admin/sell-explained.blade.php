@if($explanations !== [])
    <x-filament::section heading="How prices are worked out" collapsible collapsed>
        <div class="domains-price-help">
            <p>Each TLD uses its account's Default pricing, or Custom pricing set just for that TLD. Yearly prices are rounded first, then multiplied by the years.</p>
            @foreach(collect($explanations)->groupBy('account') as $account => $rules)
                <div>
                    <h3>{{ $account }}</h3>
                    <dl>
                        @foreach($rules as $rule)
                            <dt data-price-pricing>{{ $rule['pricing'] === 'Custom' ? 'Custom pricing for '.implode(', ',$rule['tlds']) : 'Default pricing' }}</dt>
                            <dd data-price-explanation><code data-price-formula>{{ $rule['formula'] }}</code></dd>
                        @endforeach
                        <dt>Card fee</dt>
                        <dd>{{ \Paymenter\Extensions\Servers\Domains\Services\CardFee::describe($rules->first()['fee']) }} per payment.@if($rules->every('fee_in_price')) It is worked out on the final price, so the price covers it.@endif</dd>
                    </dl>
                </div>
            @endforeach
            <div>
                <h3>Good to know</h3>
                <dl data-price-notes>
                    <dt>Saved prices</dt><dd>The table shows saved prices. If a price does not match its current rule, save the rule again to update it. Gray 1-year prices are estimates from the cost and the rule.</dd>
                    <dt>Profit</dt><dd>An estimate: price − registrar cost − card fee on that price. The fixed part of the fee counts once per payment, however many years. International cards and refunds can cost more.</dd>
                    <dt>Red</dt><dd>A price below registrar cost, or a loss after the card fee. Issued invoices keep their prices.</dd>
                    <dt>Missing</dt><dd>No price is saved for this many years. It is never worked out from the 1-year price. To add one, open the TLD page and use Edit prices.</dd>
                </dl>
            </div>
        </div>
    </x-filament::section>
@endif
@if($saved['skipped'] !== [])
    <x-filament::callout heading="Some TLDs are left out" icon="heroicon-o-exclamation-triangle" color="warning">
        <x-slot name="description">
        <span class="domains-sell-left">Their saved pricing can't be read, so no prices are shown for them. The rows below are not affected.</span>
            @foreach($saved['warnings'] as $warning)
                <span class="domains-sell-left" data-price-omission>
                    @if($warning['cause'] === 'custom_rule')
                        <span data-omitted-tld>{{ $warning['tlds'][0] }}</span> on {{ $warning['account'] }}: custom pricing can't be read.
                        To fix, open the {{ $warning['tlds'][0] }} TLD page and use Edit prices.
                    @else
                        {{ $warning['account'] }}: {{ $warning['cause'] === 'card_fee' ? "card fee can't be read." : "default pricing can't be read." }}
                        {{ count($warning['tlds']) }} {{ count($warning['tlds']) === 1 ? 'TLD' : 'TLDs' }} left out: @foreach($warning['tlds'] as $tld)<span data-omitted-tld>{{ $tld }}</span>{{ $loop->last ? '.' : ', ' }}@endforeach
                        To fix, open {{ $warning['account'] }} under Registrars and use {{ $warning['cause'] === 'card_fee' ? 'Edit card fee.' : 'Edit default pricing.' }}
                    @endif
                </span>
            @endforeach
        </x-slot>
    </x-filament::callout>
@endif
