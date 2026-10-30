{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $policy = \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::POLICY);
    $canEditPrices = $policy
        && \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('publish'))
        && \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('override'));
    $canSyncPrices = $policy && \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('import'));
    $fix = $canEditPrices
        ? 'To fix it, open this TLD in TLDs & pricing, open Edit prices and save.'
        : 'Ask a staff member who can edit domain pricing to open this TLD in TLDs & pricing, open Edit prices and save.';
    $help = $native
        ? implode(' ', ['Changing a price here can stop new orders for this TLD until its prices match again.', $fix, 'Existing invoices keep their prices.'])
        : implode(' ', [
            'A TLD is only for sale when its saved prices match its published prices. Changing the account default affects every TLD that uses it.',
            'After you save, check that each affected TLD is still for sale.',
            $canEditPrices ? 'If one isn’t, open it in TLDs & pricing, open Edit prices and save.' : 'If one isn’t, ask a staff member who can edit domain pricing to open it in TLDs & pricing, open Edit prices and save.',
            $canSyncPrices ? 'If it has no costs, use Sync prices on the TLD list first.' : 'If it has no costs, ask a staff member who can sync TLD prices to use Sync prices on the TLD list first.',
            'Registration and transfer prices below cost are never put on sale. Existing invoices keep their prices.',
        ]);
@endphp
<div role="status">
<x-filament::section>
    <div class="fi-prose">
        <p class="flex items-center gap-1"><strong>Price changes can stop domain sales</strong> <x-filament::icon-button icon="heroicon-m-question-mark-circle" color="primary" size="sm" label="About price changes" :tooltip="$help" /></p>
        @if ($native)
            <p>Don’t change this product’s server, slug, visibility or quantity setting, or its plans’ type or billing period.</p>
        @endif
        @if ($status)
            <p>@foreach ($status as $action => $available)@unless ($loop->first)<br>@endunless<strong>{{ $action }}: {{ $available ? 'For sale' : 'Not for sale' }}</strong>@endforeach</p>
            @if (! ($status['Registration'] ?? true))
                <p>{{ $fix }}</p>
            @endif
        @endif
    </div>
</x-filament::section>
</div>
