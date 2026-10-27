{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
<div role="status" style="margin-bottom:1.5rem">
<x-filament::section>
    <strong>Check sales after pricing changes</strong>
    @if ($native)
        <p>Editing native plan prices can pause new sales. Publish prices from TLDs &amp; pricing to restore matching native prices.</p>
        <p>Keep the domain product identity and annual plan terms unchanged.</p>
    @else
        <p>A pricing rule without matching published prices pauses new sales. After saving, check the public sale status below. Changing the account default can pause sales for other TLDs that use it. Check those TLDs after saving, even when this TLD is available.</p>
        <p>If sales are unavailable, check TLDs &amp; pricing. Publish prices if needed. Sync out-of-date costs first; below-cost registration and transfer prices stay unavailable.</p>
    @endif
    @foreach ($status as $action => $available)
        <p><strong>Public {{ $action }}: {{ $available ? 'available' : 'unavailable' }}</strong></p>
    @endforeach
    <p>Existing invoices keep their prices.</p>
</x-filament::section>
</div>
