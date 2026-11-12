{{-- Enom 444f31c transfer view; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@component('layouts.app', ['title' => 'Transfer a domain', 'sidebar' => auth()->check(), 'spa' => false])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
@include('domains::partials.test-banner', ['test' => $testEnvironment])
@php($listed = \Paymenter\Extensions\Servers\Domains\Http\DomainList::reachable())
{{-- Back only to a domain list the visitor can open, as on Register; the Register tab below is the way to registration. --}}
<div>@if ($listed)<a class="domains-back" href="{{ route('domains.domains.index') }}"><x-ri-arrow-left-s-line class="domains-i" aria-hidden="true" />Domains</a>@endif
<div class="domains-head domains-head--search"><div><h1 class="domains-title">Transfer a domain</h1><p class="domains-lede">Move a domain you own from another registrar. A transfer adds one year to its expiry date.</p></div></div></div>
@include('domains::partials.acquire-tabs', ['current' => 'transfer'])
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
<div class="domains-panel"><section class="domains-section" aria-labelledby="domains-before-heading">
<h2 id="domains-before-heading" class="domains-h2">Before you start</h2><p>Do these at your current registrar.</p>
<ol class="domains-steps2"><li>Turn off the transfer lock.</li><li>Get the auth code (also called EPP code). You’ll enter it after checkout.</li><li>Make sure the owner email address is current. The registrar may send an approval email there.</li></ol>
<p class="domains-small domains-muted">Domains registered or transferred in the last 60 days can’t be transferred yet. Most transfers finish within 5 to 7 days.</p>
</section><section class="domains-section" aria-labelledby="domains-transfer-prices"><h2 id="domains-transfer-prices" class="domains-h2">Transfer prices</h2>
<div class="domains-tablewrap"><table class="domains-tbl"><thead><tr><th scope="col">TLD</th><th scope="col" class="domains-end">Transfer (adds 1 year)</th></tr></thead><tbody>
@foreach ($endings as $ending)<tr><td class="domains-domain">.{{ $ending['tld'] }}@if ($ending['testEnvironment'] ?? false) <span class="domains-badge domains-tone-muted">Test</span>@endif</td><td class="domains-end domains-num">{{ $ending['transfer'] }}</td></tr>@endforeach
</tbody></table></div><p class="domains-small domains-muted">Prices plus tax where applicable.</p></section></div>
@endif
</div></div>
@include('domains::partials.client-script')
@endcomponent
