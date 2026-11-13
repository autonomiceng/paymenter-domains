{{-- Enom 444f31c transfer view; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@component('layouts.app', ['title' => 'Transfer a domain', 'sidebar' => auth()->check(), 'spa' => false])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
@include('domains::partials.test-banner', ['test' => $testEnvironment])
@include('domains::partials.store-head', ['title' => 'Get a domain', 'lede' => 'Move a domain you own from another registrar. A transfer adds one year to its expiry date.', 'tabs' => 'transfer'])
@include('domains::partials.notices', ['fieldKeys' => ['domain'], 'summary' => false])
@if (!$endings)
<div class="domains-notice domains-notice--warn" role="status">Domain transfers aren't open yet. @php($support = \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl())@if ($support) <a class="domains-a" href="{{ $support }}">{{ config('settings.tickets_disabled', false) ? 'Contact support' : 'Open a ticket' }}</a>@endif</div>
@else
<form method="GET" action="{{ route('domains.domains.transfer') }}" role="search">
<div class="domains-searchbar">
<label for="domains-transfer-name" class="domains-sr">Domain name</label>
<input id="domains-transfer-name" name="name" type="text" value="{{ $sld ?? $name }}" placeholder="yourbusiness" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="253" required pattern=".*\S.*" @if ($error) aria-invalid="true" aria-describedby="domains-transfer-error" @endif>
<label for="domains-transfer-tld" class="domains-sr">TLD</label><select id="domains-transfer-tld" name="tld" class="domains-input">@foreach ($endings as $ending)<option value="{{ $ending['tld'] }}" @selected($ending['tld'] === $preferred)>.{{ $ending['tld'] }}</option>@endforeach</select>
<button type="submit" class="domains-btn domains-btn--primary">Continue</button></div>
@if ($error)<p id="domains-transfer-error" class="domains-error" role="alert">{{ $error }}</p>@endif
</form>
@if ($results)
<ul class="domains-results" data-mode="transfer" data-support-url="{{ \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl() }}" data-domains-results="{{ route('domains.domains.check') }}" data-request-key="{{ $checkKey }}">@foreach ($results as $result)@include('domains::partials.result', ['first' => true])@endforeach</ul>
@endif
{{-- Steps are a trace (DESIGN.md P-MRK-2); the prices are their own section beside them, not inside the steps. --}}
<x-domains::customer.split>
<x-domains::customer.section id="domains-before" title="Before you start" note="Do these at your current registrar.">
<div class="domains-panel__b"><x-domains::customer.trace :steps="[['label' => 'Turn off the transfer lock.'], ['label' => 'Get the auth code (also called EPP code). You’ll enter it after checkout.'], ['label' => 'Make sure the owner email address is current. The registrar may send an approval email there.']]" />
<p class="domains-small domains-muted domains-fine">Domains registered or transferred in the last 60 days can’t be transferred yet. Most transfers finish within 5 to 7 days.</p></div>
</x-domains::customer.section>
<x-slot:rail>
<x-domains::customer.section id="domains-transfer-prices" title="Transfer prices">
<div class="domains-tablewrap"><table class="domains-tbl domains-pricelist"><thead><tr><th scope="col">TLD</th><th scope="col" class="domains-end">Transfer (adds 1 year)</th></tr></thead><tbody>
@foreach ($endings as $ending)<tr><th scope="row" class="domains-domain domains-mono">.{{ $ending['tld'] }}@if (($ending['testEnvironment'] ?? false) && !$testEnvironment){{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label(false, true) }}@endif</th><td class="domains-end domains-num">{{ $ending['transfer'] }}</td></tr>@endforeach
</tbody></table></div><p class="domains-panel__b domains-small domains-muted">{{ \Paymenter\Extensions\Servers\Domains\Presentation\Fact::IN_USD }} Prices plus tax where applicable.</p>
</x-domains::customer.section>
</x-slot:rail>
</x-domains::customer.split>
@endif
</div></div>
@include('domains::partials.client-script')
@endcomponent
