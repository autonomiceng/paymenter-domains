{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $policy = \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::POLICY);
    $canEditPrices = $policy
        && \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('publish'))
        && \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('override'));
    $fix = $canEditPrices
        ? 'To fix it, open this TLD in TLDs & pricing, open Edit prices and save.'
        : 'Ask a staff member who can edit domain pricing to open this TLD in TLDs & pricing, open Edit prices and save.';
    $help = implode(' ', ['Changing a price here can stop new orders for this TLD until its prices match again.', $fix, 'Existing invoices keep their prices.']);
@endphp
<div role="status">
<x-filament::section>
    <div class="fi-prose">
        <p>{{ \Paymenter\Extensions\Servers\Domains\Admin\Support\HelpHint::beside('<strong>Price changes can stop domain sales</strong>', $help, 'About price changes') }}</p>
        <p>Don’t change this product’s server, slug, visibility or quantity setting, or its plans’ type or billing period.</p>
        @if ($status)
            <p>@foreach ($status as $action => $available)@unless ($loop->first)<br>@endunless<strong>{{ $action }}: {{ $available ? 'For sale' : 'Not for sale' }}</strong>@endforeach</p>
            @if (! ($status['Registration'] ?? true))
                <p>{{ $recovery ?? $fix }}</p>
            @endif
        @endif
    </div>
</x-filament::section>
</div>
