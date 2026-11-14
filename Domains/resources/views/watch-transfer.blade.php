{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@component('layouts.app', ['title' => str_replace(':domain', $name, $strings['watch.transfer_title']), 'sidebar' => true, 'spa' => false])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
@php
    $title = str_replace(':domain', $name, $strings['watch.transfer_title']);
    $back = route('domains.checks.show', ['kind' => 'watch', 'id' => $id]);
    $support = \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl();
@endphp
{{-- The same head as every customer page (DESIGN.md P-LAY-12, P-TYP-1); each blocked state is one notice with its one next step (P-NTC-5, P-ACT-8). --}}
<x-domains::customer.head :title="$title" :crumbs="[['Domains', route('domains.domains.index')], [$name, $back], ['Transfer', null]]" />
@if ($state === 'in_cart')
<x-domains::customer.notice tone="info" :action="['label' => 'Go to cart', 'url' => route('cart'), 'button' => true]">{{ $strings['watch.transfer_in_cart'] }}</x-domains::customer.notice>
@elseif ($state === 'eligible')
<x-domains::customer.split>
<x-domains::customer.section id="transfer-offer" title="Price"><x-domains::customer.rows>
<x-domains::customer.row label="Transfer (adds 1 year)"><span class="domains-card__v domains-num">{{ $price }}</span> <span class="domains-sub">Plus tax where applicable.</span></x-domains::customer.row>
<x-domains::customer.row label="Renewal price"><span class="domains-card__v domains-num">{{ $renew }}/yr</span></x-domains::customer.row>
</x-domains::customer.rows>
<div class="domains-panel__b"><form method="POST" action="{{ route('domains.watch.transfer-check',$id) }}">@csrf
<input type="hidden" name="_request_key" value="{{ $key }}"><input type="hidden" name="revision" value="{{ $revision }}"><input type="hidden" name="step" value="confirm"><input type="hidden" name="authority" value="1">
<div class="domains-buttons"><button class="domains-btn domains-btn--primary" type="submit">Add transfer to cart</button></div>
</form><p class="domains-sub">You’ll enter the auth code after checkout.</p></div>
</x-domains::customer.section>
</x-domains::customer.split>
@elseif ($state === 'changed')
<x-domains::customer.notice tone="warn" :action="['label' => 'Check again', 'url' => route('domains.watch.transfer',$id), 'button' => true]">{{ $strings['watch.transfer_changed'] }}</x-domains::customer.notice>
@elseif ($state === 'unavailable' || !$canCheck)
<x-domains::customer.notice tone="warn" :action="$support ? ['label' => 'Contact support', 'url' => $support] : null">{{ $strings['watch.transfer_unavailable'] }}</x-domains::customer.notice>
@else
<x-domains::customer.split>
<x-domains::customer.section id="transfer-offer" title="Price" note="We check the current transfer price before you add it to your cart.">
<div class="domains-panel__b"><form method="POST" action="{{ route('domains.watch.transfer-check',$id) }}">@csrf
<input type="hidden" name="_request_key" value="{{ $key }}"><input type="hidden" name="revision" value="{{ $revision }}">
<button class="domains-btn domains-btn--primary" type="submit">Check price</button></form></div>
</x-domains::customer.section>
</x-domains::customer.split>
@endif
</div></div>
@endcomponent
