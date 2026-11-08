@if($explanations !== [])
    <x-filament::section heading="How to read this table" collapsible collapsed>
        <div class="domains-price-help">
            <p>Each TLD uses Default pricing, or Custom pricing set just for that TLD. Yearly prices are rounded first, then multiplied by the years. Each registrar account page shows its Default pricing and card fee.</p>
            <div>
                <dl data-price-notes>
                    <dt>Saved prices</dt><dd>The table shows saved prices. If a price does not match its current rule, save the rule again to update it. Gray 1-year prices are estimates from the cost and the rule.</dd>
                    <dt>Profit</dt><dd>An estimate: price − registrar cost − card fee on that price. The fixed part of the fee counts once per payment, however many years. International cards and refunds can cost more.</dd>
                    <dt>Red</dt><dd>A price below registrar cost, or a loss after the card fee. Issued invoices keep their prices.</dd>
                    <dt>Off</dt><dd>That sale is turned off, or the TLD is not for sale for this many years. To sell it longer, tick the row and use Edit selected.</dd>
                    <dt>Missing</dt><dd>No price is saved for this many years. It is never worked out from the 1-year price. To add one, open the TLD page and use Edit prices.</dd>
                </dl>
            </div>
        </div>
    </x-filament::section>
@endif
