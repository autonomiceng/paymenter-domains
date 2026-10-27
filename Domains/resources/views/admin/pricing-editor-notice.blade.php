{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
<div role="status" style="margin-bottom:1.5rem">
<x-filament::section>
    <strong>Price changes can stop domain sales</strong>
    @if ($native)
        <p>Changing a price here can stop new orders for this TLD until its prices match again. To fix it, open this TLD in TLDs &amp; pricing and use Publish prices.</p>
        <p>Don’t change this product’s server, slug, visibility or quantity setting, or its plans’ type or billing period.</p>
    @else
        <p>A TLD is only for sale when its saved prices match its published prices. Changing the account default affects every TLD that uses it.</p>
        <p>After you save, check that each affected TLD is still for sale. If one isn’t, open it in TLDs &amp; pricing and use Publish prices. If its costs are out of date, use Sync TLD prices on the registrar account first. Registration and transfer prices below cost are never put on sale.</p>
    @endif
    @foreach ($status as $action => $available)
        <p><strong>{{ $action }}: {{ $available ? 'For sale' : 'Not for sale' }}</strong></p>
    @endforeach
    <p>Existing invoices keep their prices.</p>
</x-filament::section>
</div>
