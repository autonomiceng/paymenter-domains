{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@component('layouts.app', ['title' => str_replace(':domain', $name, $strings['watch.transfer_title']), 'sidebar' => true, 'spa' => false])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<nav class="domains-crumb" aria-label="Breadcrumb"><a href="{{ route('domains.domains.index') }}">Domains</a> / <a href="{{ route('domains.checks.show', ['kind' => 'watch', 'id' => $id]) }}">{{ $name }}</a></nav>
<div class="domains-ph"><h1>{{ str_replace(':domain', $name, $strings['watch.transfer_title']) }}</h1></div>
@if ($state === 'in_cart')
<div class="domains-notice" role="status"><span class="domains-notice__tx">{{ $strings['watch.transfer_in_cart'] }}</span></div>
<a class="domains-btn domains-btn--primary" href="{{ route('cart') }}">Go to cart</a>
@elseif ($state === 'eligible')
<section class="domains-panel domains-transfer" aria-labelledby="transfer-offer"><div class="domains-panel__h"><h2 class="domains-h2" id="transfer-offer">Price</h2></div><div class="domains-panel__b">
<dl class="domains-card"><div><dt>Transfer (adds 1 year)</dt><dd class="domains-num">{{ $price }}</dd></div><div><dt>Renewal price</dt><dd class="domains-num">{{ $renew }}/yr</dd></div></dl>
<p class="domains-sub">Plus tax where applicable.</p>
<form method="POST" action="{{ route('domains.watch.transfer-check',$id) }}">@csrf
<input type="hidden" name="_request_key" value="{{ $key }}"><input type="hidden" name="revision" value="{{ $revision }}"><input type="hidden" name="step" value="confirm"><input type="hidden" name="authority" value="1">
<div class="domains-buttons"><button class="domains-btn domains-btn--primary" type="submit">Add transfer to cart</button></div>
</form><p class="domains-sub">You’ll enter the auth code after checkout.</p>
</div></section>
@elseif ($state === 'changed')
<div class="domains-notice domains-notice--warn" role="status"><span class="domains-notice__tx">{{ $strings['watch.transfer_changed'] }}</span></div>
<a class="domains-btn domains-btn--primary" href="{{ route('domains.watch.transfer',$id) }}">Check again</a>
@elseif ($state === 'unavailable' || !$canCheck)
<div class="domains-notice domains-notice--warn" role="status"><span class="domains-notice__tx">{{ $strings['watch.transfer_unavailable'] }}</span></div>
@else
<form method="POST" action="{{ route('domains.watch.transfer-check',$id) }}">@csrf
<input type="hidden" name="_request_key" value="{{ $key }}"><input type="hidden" name="revision" value="{{ $revision }}">
<button class="domains-btn domains-btn--primary" type="submit">Check price</button></form>
@endif
</div></div>
@endcomponent
