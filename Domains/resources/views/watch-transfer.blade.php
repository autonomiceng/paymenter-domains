{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@component('layouts.app', ['title' => $strings['watch.transfer_compare'], 'sidebar' => true, 'spa' => false])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<a class="domains-back" href="{{ route('domains.checks.show',['kind'=>'watch','id'=>$id]) }}">Domain details</a>
<div class="domains-head"><div><h1 class="domains-title">{{ $strings['watch.transfer_compare'] }}</h1>
<p class="domains-lede domains-domain">{{ $name }}</p></div></div>
<p>{{ $strings['watch.transfer_help'] }}</p>
@if ($state === 'eligible')
<div class="domains-panel"><section class="domains-section" aria-labelledby="transfer-offer">
<h2 class="domains-h2" id="transfer-offer">Current transfer offer</h2>
<dl class="domains-facts"><div class="domains-fact"><dt>Transfer, includes 1 year</dt><dd class="domains-num">{{ $price }}</dd></div><div class="domains-fact"><dt>Annual renewal</dt><dd class="domains-num">{{ $renew }}</dd></div>@if ($saving && ($verifiedRenew ?? null))<div class="domains-fact"><dt>Your current renewal (verified)</dt><dd>{{ $verifiedRenew }}</dd></div>@endif</dl>
<p class="domains-small domains-muted">Prices plus tax where applicable. Final availability and price are checked again at checkout.</p>
@if ($saving && ($verifiedRenew ?? null))<p>{{ str_replace(':amount',$saving,$strings['watch.transfer_saving']) }}</p>@endif
<form method="POST" action="{{ route('domains.watch.transfer-check',$id) }}">@csrf
<input type="hidden" name="_request_key" value="{{ $key }}"><input type="hidden" name="revision" value="{{ $revision }}"><input type="hidden" name="step" value="confirm">
<p><label class="domains-checkbox"><input type="checkbox" name="authority" value="1" required @if ($ownerError ?? false) aria-invalid="true" aria-describedby="authority-error" @endif><span>{{ $strings['watch.transfer_owner'] }}</span></label></p>
@if ($ownerError ?? false)<p class="domains-error" id="authority-error">Confirm that you own this domain or are authorized to transfer it.</p>@endif
<button class="domains-btn domains-btn--primary" type="submit">Add transfer to cart</button>
</form><p class="domains-small domains-muted">Enter the transfer code on the domain page after checkout.</p>
</section></div>
@elseif ($state === 'changed' || $state === 'unavailable')
<div class="domains-notice domains-notice--warn" role="status">{{ $strings['watch.transfer_'.$state] }}</div>
<a class="domains-btn domains-btn--secondary" href="{{ route('domains.watch.transfer',$id) }}">Check latest details</a>
@elseif ($canCheck)
<p>Transfer eligibility has not been confirmed. Check availability to see the current offer.</p>
<form method="POST" action="{{ route('domains.watch.transfer-check',$id) }}">@csrf
<input type="hidden" name="_request_key" value="{{ $key }}"><input type="hidden" name="revision" value="{{ $revision }}">
<button class="domains-btn domains-btn--primary" type="submit">Check transfer availability</button></form>
@else
<p>{{ $strings['watch.transfer_unavailable'] }}</p>
@endif
<p><a class="domains-a" href="{{ route('tickets.create') }}">Contact support</a></p>
</div></div>
@endcomponent
