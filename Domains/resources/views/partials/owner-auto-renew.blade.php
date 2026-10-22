@if ($domain->renewalOff)
<div class="domains-notice" role="status">
@if ($domain->renewalOff === 'owner')Auto-renew is off. This domain expires on @include('domains::partials.date', ['date' => $domain->expiry]).@if (isset($domain->renewNow['url'])) Renew it now or turn auto-renew back on.@elseif (isset($domain->renewNow['invoice'])) Pay the renewal invoice to keep it.@else Contact support if you want to keep it.@endif
@elseif ($domain->renewalOff === 'cancellation')This domain is set to cancel when it expires on @include('domains::partials.date', ['date' => $domain->expiry]). Contact support to keep it.
@else Renewals are paused for this domain. Contact support to keep it.
@endif
</div>
@endif
@if ($domain->autoRenew)
<form method="POST" action="{{ $domain->autoRenew['url'] }}" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => 'auto-renew'])
<input type="hidden" name="enabled" value="{{ $domain->autoRenew['enabled'] ? '0' : '1' }}">
@if ($domain->autoRenew['enabled'])
@include('domains::partials.owner-confirm', ['text' => 'Turn off auto-renew? This domain will expire on '.\Carbon\CarbonImmutable::parse($domain->expiry)->format('M j, Y').' unless you renew it.'])
@endif
<button type="submit" class="domains-btn domains-btn--secondary">{{ $domain->autoRenew['enabled'] ? 'Turn off auto-renew' : 'Turn on auto-renew' }}</button>
</form>
@endif
