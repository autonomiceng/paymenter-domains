@component('layouts.app', ['title'=>'Change domain','sidebar'=>true,'spa'=>false])
<div class="container mt-14 mb-10"><div class="domains">@include('domains::partials.styles')
<a class="domains-back" href="{{ route('cart') }}">Back to cart</a><h1 class="domains-title">Change domain</h1>
<p class="domains-lede">Update this cart line. To choose a different TLD or registration type, remove the line from your cart and search again.</p>
@include('domains::partials.notices', ['fieldKeys'=>['domain'],'summary'=>false])
<form class="domains-panel domains-section" method="POST" action="{{ route('domains.cart.update',['item'=>$item->id]) }}">
@include('domains::partials.form-key',['keys'=>['edit'=>$key],'action'=>'edit'])
<label for="domain">Domain name</label><input id="domain" class="domains-input" name="domain" value="{{ $domain['name'] }}" maxlength="253" required>
@php
// Each term's line total, priced like the cart line itself (tax and coupon included); a complimentary account pays $0.00.
$termTotal=function($plan)use($item){if(\Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::authorization((int)auth()->id())){return $item->price->format(0);}
    $probe=$item;if($plan->id!==$item->plan_id){$probe=$item->replicate();$probe->plan_id=$plan->id;$probe->setRelation('plan',$plan);}$price=$probe->price;return $price->format($price->total*$item->quantity);};
@endphp
<label for="term">Years</label><select id="term" class="domains-input" name="term">@foreach(app(\Paymenter\Extensions\Servers\Domains\Services\Storefront::class)->checkoutPlans($item->product) as $plan)<option value="{{ $plan->billing_period }}" @selected($plan->id===$item->plan_id)>{{ $plan->billing_period }} {{ (int)$plan->billing_period===1?'year':'years' }} · {{ $termTotal($plan) }}</option>@endforeach</select>
<button class="domains-btn domains-btn--primary" type="submit">Update domain</button></form>
</div></div>
@endcomponent
