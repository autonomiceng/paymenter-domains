{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $policy = \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::POLICY);
    $canEditPrices = $policy
        && \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('publish'))
        && \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('override'));
    $canSyncPrices = $policy && \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('import'));
@endphp
<div role="status" style="margin-bottom:1.5rem">
<x-filament::section>
    <div class="fi-prose">
        <p><strong>Price changes can stop domain sales</strong></p>
        @if ($native)
            <p>Changing a price here can stop new orders for this TLD until its prices match again.
                @if ($canEditPrices)
                    To fix it, open this TLD in TLDs &amp; pricing, open Edit prices and save.
                @else
                    Ask a staff member who can edit domain pricing to open this TLD in TLDs &amp; pricing, open Edit prices and save.
                @endif
            </p>
            <p>Don’t change this product’s server, slug, visibility or quantity setting, or its plans’ type or billing period.</p>
        @else
            <p>A TLD is only for sale when its saved prices match its published prices. Changing the account default affects every TLD that uses it.</p>
            <p>After you save, check that each affected TLD is still for sale.
                @if ($canEditPrices)
                    If one isn’t, open it in TLDs &amp; pricing, open Edit prices and save.
                @else
                    If one isn’t, ask a staff member who can edit domain pricing to open it in TLDs &amp; pricing, open Edit prices and save.
                @endif
                @if ($canSyncPrices)
                    If it has no costs, use Sync prices on its TLD page first.
                @else
                    If it has no costs, ask a staff member who can sync TLD prices to use Sync prices on its TLD page first.
                @endif
                Registration and transfer prices below cost are never put on sale.
            </p>
        @endif
        @if ($status)
            <p>@foreach ($status as $action => $available)@unless ($loop->first)<br>@endunless<strong>{{ $action }}: {{ $available ? 'For sale' : 'Not for sale' }}</strong>@endforeach</p>
        @endif
        <p>Existing invoices keep their prices.</p>
    </div>
</x-filament::section>
</div>
